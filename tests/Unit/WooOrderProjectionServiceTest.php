<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class WooOrderProjectionServiceTest extends \AIMS\Tests\TestCase {

	public function testEvaluateProjectionSkipsWhenFlagAbsent(): void {
		$service  = new \AIMS_Woo_Order_Projection_Service();
		$decision = $service->evaluate_projection( array( 'woo_order_id' => 0 ), array() );

		$this->assertSame( 'skipped', $decision['status'] );
		$this->assertSame( 'projection_disabled', $decision['reason'] );
	}

	public function testEvaluateProjectionSkipsWhenAwaitingReconciliation(): void {
		$service  = new \AIMS_Woo_Order_Projection_Service();
		$decision = $service->evaluate_projection(
			array( 'woo_order_id' => 0 ),
			array(
				'allow_woo_order_projection'   => true,
				'reconciliation_status'        => 'pending',
				// allow_unreconciled_projection intentionally absent
			)
		);

		$this->assertSame( 'skipped', $decision['status'] );
		$this->assertSame( 'awaiting_reconciliation', $decision['reason'] );
	}

	public function testEvaluateProjectionReturnsReadyWithBothFlags(): void {
		$service  = new \AIMS_Woo_Order_Projection_Service();
		$decision = $service->evaluate_projection(
			array( 'woo_order_id' => 0 ),
			array(
				'allow_woo_order_projection'   => true,
				'allow_unreconciled_projection' => true,
			)
		);

		$this->assertSame( 'ready', $decision['status'] );
	}

	public function testEvaluateProjectionAllowsPendingProjectionMode(): void {
		$service  = new \AIMS_Woo_Order_Projection_Service();
		$decision = $service->evaluate_projection(
			array( 'woo_order_id' => 0 ),
			array(
				'allow_woo_order_projection'   => true,
				'allow_unreconciled_projection' => true,
				'projection_mode'              => 'pending',
			)
		);

		$this->assertSame( 'ready', $decision['status'] );
		$this->assertSame( 'pending', $decision['projection_mode'] );
	}

	public function testEvaluateProjectionRejectsUnsupportedProjectionMode(): void {
		$service  = new \AIMS_Woo_Order_Projection_Service();
		$decision = $service->evaluate_projection(
			array( 'woo_order_id' => 0 ),
			array(
				'allow_woo_order_projection'   => true,
				'allow_unreconciled_projection' => true,
				'projection_mode'              => 'processing',
			)
		);

		$this->assertSame( 'skipped', $decision['status'] );
		$this->assertSame( 'unsupported_projection_mode', $decision['reason'] );
	}

	public function testProjectNormalizedSaleWritesSyncRunIdMetaViaCreatorCallback(): void {
		$captured_context = array();

		$service = new \AIMS_Woo_Order_Projection_Service(
			static function ( array $sale_record, array $context ) use ( &$captured_context ): array {
				$captured_context = $context;
				return array( 'woo_order_id' => 501, 'projection_mode' => 'draft', 'reason' => 'draft_projected' );
			}
		);

		$result = $service->project_normalized_sale(
			array(
				'woo_order_id'      => 0,
				'square_order_id'   => 'SQ-META-TEST',
				'normalized_sale_id'=> 900,
			),
			array(
				'allow_woo_order_projection'   => true,
				'allow_unreconciled_projection' => true,
				'sync_run_id'                  => 77,
			)
		);

		$this->assertSame( 'projected', $result['status'] );
		$this->assertSame( 501, $result['woo_order_id'] );
		$this->assertSame( 77, $captured_context['sync_run_id'] ?? 0, 'sync_run_id should flow through to creator context.' );
	}

	public function testProjectNormalizedSalePassesPendingProjectionModeToCreatorCallback(): void {
		$captured_context = array();

		$service = new \AIMS_Woo_Order_Projection_Service(
			static function ( array $sale_record, array $context ) use ( &$captured_context ): array {
				unset( $sale_record );
				$captured_context = $context;
				return array( 'woo_order_id' => 777, 'projection_mode' => 'pending', 'reason' => 'pending_projected' );
			}
		);

		$result = $service->project_normalized_sale(
			array( 'woo_order_id' => 0 ),
			array(
				'allow_woo_order_projection'    => true,
				'allow_unreconciled_projection' => true,
				'projection_mode'               => 'pending',
			)
		);

		$this->assertSame( 'projected', $result['status'] );
		$this->assertSame( 'pending', $captured_context['projection_mode'] ?? '' );
		$this->assertSame( 'pending', $result['projection_mode'] ?? '' );
	}

	public function testProjectNormalizedSaleBuildsUnfulfilledAndAdditionalChargesInCreatorContext(): void {
		$captured_context = array();

		$service = new \AIMS_Woo_Order_Projection_Service(
			static function ( array $sale_record, array $context ) use ( &$captured_context ): array {
				unset( $sale_record );
				$captured_context = $context;

				return array( 'woo_order_id' => 990, 'projection_mode' => 'draft', 'reason' => 'draft_projected' );
			}
		);

		$result = $service->project_normalized_sale(
			array(
				'woo_order_id'        => 0,
				'fulfillment_status'  => 'pending',
			),
			array(
				'allow_woo_order_projection'    => true,
				'allow_unreconciled_projection' => true,
				'unfulfilled_charge_amount'     => 4.25,
				'unfulfilled_charge_label'      => 'Unfulfilled Line Charge',
				'additional_projection_charges' => array(
					array(
						'code'   => 'cold_pack',
						'label'  => 'Cold Pack',
						'amount' => 1.75,
					),
				),
			)
		);

		$this->assertSame( 'projected', $result['status'] );
		$this->assertCount( 2, $captured_context['projection_charges'] ?? array() );
		$this->assertSame( 'unfulfilled', $captured_context['projection_charges'][0]['code'] ?? '' );
		$this->assertSame( 4.25, $captured_context['projection_charges'][0]['amount'] ?? 0.0 );
		$this->assertSame( 'cold_pack', $captured_context['projection_charges'][1]['code'] ?? '' );
		$this->assertSame( 1.75, $captured_context['projection_charges'][1]['amount'] ?? 0.0 );
	}

	public function testProjectNormalizedSaleOmitsUnfulfilledChargeWhenStatusFulfilled(): void {
		$captured_context = array();

		$service = new \AIMS_Woo_Order_Projection_Service(
			static function ( array $sale_record, array $context ) use ( &$captured_context ): array {
				unset( $sale_record );
				$captured_context = $context;

				return array( 'woo_order_id' => 991, 'projection_mode' => 'draft', 'reason' => 'draft_projected' );
			}
		);

		$service->project_normalized_sale(
			array(
				'woo_order_id'        => 0,
				'fulfillment_status'  => 'fulfilled',
			),
			array(
				'allow_woo_order_projection'    => true,
				'allow_unreconciled_projection' => true,
				'unfulfilled_charge_amount'     => 4.25,
			)
		);

		$this->assertCount( 0, $captured_context['projection_charges'] ?? array() );
	}

	public function testPromoteDraftProjectionsPromotesOrdersAndSkipsNonDraft(): void {
		$promoted_ids = array();
		$skipped_ids  = array();

		$service = new \AIMS_Woo_Order_Projection_Service(
			null,
			static function ( int $order_id ) use ( &$promoted_ids, &$skipped_ids ): array {
				if ( in_array( $order_id, array( 101, 102 ), true ) ) {
					$promoted_ids[] = $order_id;
					return array( 'status' => 'promoted', 'woo_order_id' => $order_id );
				}
				$skipped_ids[] = $order_id;
				return array( 'status' => 'skipped', 'woo_order_id' => $order_id );
			}
		);

		$result = $service->promote_draft_projections_for_run( 44, array( 101, 102, 103 ) );

		$this->assertSame( 44, $result['run_id'] );
		$this->assertSame( 2, $result['promoted_count'] );
		$this->assertSame( 1, $result['skipped_count'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 101, 102 ), $promoted_ids );
		$this->assertSame( array( 103 ), $skipped_ids );
	}

	public function testPromoteDraftProjectionsSkipsZeroOrderIds(): void {
		$service = new \AIMS_Woo_Order_Projection_Service(
			null,
			static function ( int $order_id ): array {
				return array( 'status' => 'promoted', 'woo_order_id' => $order_id );
			}
		);

		$result = $service->promote_draft_projections_for_run( 44, array( 0, -1, 5 ) );

		// 0 and -1 are skipped without calling the promoter; 5 is promoted
		$this->assertSame( 1, $result['promoted_count'] );
		$this->assertSame( 2, $result['skipped_count'] );
	}

	public function testPromoteDraftProjectionsReturnsEmptyResultForNoOrders(): void {
		$service = new \AIMS_Woo_Order_Projection_Service();
		$result  = $service->promote_draft_projections_for_run( 44, array() );

		$this->assertSame( 44, $result['run_id'] );
		$this->assertSame( 0, $result['promoted_count'] );
		$this->assertSame( 0, $result['skipped_count'] );
		$this->assertSame( array(), $result['errors'] );
	}
}
