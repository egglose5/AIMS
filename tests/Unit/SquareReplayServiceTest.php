<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareReplayServiceTest extends \AIMS\Tests\TestCase {
	public function testReplayRawEventCreatesFulfillmentAllocationsForResolvedSaleContext(): void {
		$normalized_sales = new class() extends \AIMS_Square_Normalized_Sale_Repository {
			/** @var array<int, array<string, mixed>> */
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $sale_id = 0 ): int {
				unset( $sale_id );
				$this->saved[] = $data;
				return 501;
			}
		};

		$assignments = new class() extends \AIMS_Square_Assignment_Service {
			public function __construct() {}

			public function resolve_sale_assignment( array $sale, array $context = array() ): array {
				unset( $sale, $context );

				return array(
					'event_id'          => 22,
					'vendor_id'         => 11,
					'assignment_window' => array(
						'selected_assignment' => array(
							'event_id'  => 22,
							'vendor_id' => 11,
						),
					),
				);
			}
		};

		$attribution = new class() extends \AIMS_Vendor_Sales_Attribution_Service {
			public function __construct() {}

			public function attribute_sale( array $sale, array $resolved_assignment = array(), array $context = array() ): array {
				unset( $sale, $context );

				return array(
					'attribution_id' => 901,
					'event_id'       => (int) ( $resolved_assignment['event_id'] ?? 0 ),
					'vendor_id'      => (int) ( $resolved_assignment['vendor_id'] ?? 0 ),
				);
			}
		};

		$effects = new class() extends \AIMS_Sync_Effect_Repository {
			/** @var array<int, array<string, mixed>> */
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $effect_id = 0 ): int {
				unset( $effect_id );
				$this->saved[] = $data;
				return 77;
			}
		};

		$fulfillment = new class() extends \AIMS_Fulfillment_Service {
			/** @var array<int, array<string, mixed>> */
			public array $created = array();

			public function __construct() {}

			public function create_allocation( array $data ): int {
				$this->created[] = $data;
				return 222;
			}
		};

		$service = new \AIMS_Square_Replay_Service(
			null,
			$normalized_sales,
			$assignments,
			$attribution,
			new \AIMS_Square_Normalization_Service(),
			$effects,
			null,
			$fulfillment
		);

		$result = $service->replay_raw_event(
			array(
				'id'         => 1001,
				'payload'    => array(
					'id'          => 'SQ-ORDER-123',
					'created_at'  => '2026-04-11T10:00:00Z',
					'location_id' => 'LOC-1',
					'event_id'    => 22,
					'vendor_id'   => 11,
					'line_items'  => array(
						array(
							'uid'            => 'LINE-1',
							'woo_product_id' => 901,
							'sku'            => 'SKU-101',
							'quantity'       => 2,
							'gross_amount'   => 20.00,
							'net_amount'     => 18.00,
							'tax_amount'     => 1.50,
							'discount_amount'=> 2.00,
						),
					),
				),
			),
			array(
				'sync_run_id'    => 12,
				'sync_action_id' => 34,
			)
		);

		$this->assertTrue( $result['replayed'] );
		$this->assertCount( 1, $fulfillment->created );
		$this->assertSame( 501, $fulfillment->created[0]['square_sale_id'] );
		$this->assertSame( 'SQ-ORDER-123', $fulfillment->created[0]['square_order_id'] );
		$this->assertSame( 901, $fulfillment->created[0]['product_id'] );
		$this->assertSame( 11, $fulfillment->created[0]['vendor_id'] );
		$this->assertSame( 22, $fulfillment->created[0]['event_id'] );
		$this->assertSame( 'event_stock', $fulfillment->created[0]['allocation_type'] );
		$this->assertCount( 1, $result['fulfillment'] ?? array() );

		$metadata = json_decode( (string) ( $effects->saved[0]['metadata_json'] ?? '' ), true );
		$this->assertSame( 222, $metadata['fulfillment'][0]['allocation_id'] ?? 0 );
	}

	public function testReplayRawEventKeepsWooProjectionBehindReconciliationGate(): void {
		$normalized_sales = new class() extends \AIMS_Square_Normalized_Sale_Repository {
			public function __construct() {}

			public function save( array $data, int $sale_id = 0 ): int {
				unset( $data, $sale_id );
				return 601;
			}
		};

		$projection = new \AIMS_Woo_Order_Projection_Service();

		$service = new \AIMS_Square_Replay_Service(
			null,
			$normalized_sales,
			null,
			null,
			new \AIMS_Square_Normalization_Service(),
			null,
			null,
			null,
			$projection
		);

		$result = $service->replay_raw_event(
			array(
				'id'      => 1002,
				'payload' => array(
					'id'         => 'SQ-ORDER-456',
					'created_at' => '2026-04-11T11:00:00Z',
					'event_id'   => 44,
					'line_items' => array(
						array(
							'uid'            => 'LINE-2',
							'woo_product_id' => 902,
							'sku'            => 'SKU-202',
							'quantity'       => 1,
							'net_amount'     => 22.00,
						),
					),
				),
			),
			array(
				'allow_woo_order_projection' => true,
				'reconciliation_status'      => 'pending',
			)
		);

		$this->assertTrue( $result['replayed'] );
		$this->assertSame( 'skipped', $result['projection'][0]['status'] ?? '' );
		$this->assertSame( 'awaiting_reconciliation', $result['projection'][0]['reason'] ?? '' );
	}

	public function testReplayRawEventProjectsDraftWooOrderWhenExplicitlyEnabled(): void {
		$normalized_sales = new class() extends \AIMS_Square_Normalized_Sale_Repository {
			/** @var array<int, array<string, mixed>> */
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $sale_id = 0 ): int {
				unset( $sale_id );
				$this->saved[] = $data;
				return 777;
			}
		};

		$effects = new class() extends \AIMS_Sync_Effect_Repository {
			/** @var array<int, array<string, mixed>> */
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $effect_id = 0 ): int {
				unset( $effect_id );
				$this->saved[] = $data;
				return 88;
			}
		};

		$projection = new \AIMS_Woo_Order_Projection_Service(
			static function( array $sale_record, array $context = array() ): array {
				unset( $sale_record );

				return array(
					'woo_order_id'    => 7801,
					'projection_mode' => (string) ( $context['projection_mode'] ?? 'draft' ),
				);
			}
		);

		$service = new \AIMS_Square_Replay_Service(
			null,
			$normalized_sales,
			null,
			null,
			new \AIMS_Square_Normalization_Service(),
			$effects,
			null,
			null,
			$projection
		);

		$result = $service->replay_raw_event(
			array(
				'id'      => 1003,
				'payload' => array(
					'id'         => 'SQ-ORDER-789',
					'created_at' => '2026-04-11T12:00:00Z',
					'event_id'   => 55,
					'line_items' => array(
						array(
							'uid'            => 'LINE-3',
							'woo_product_id' => 903,
							'sku'            => 'SKU-303',
							'quantity'       => 1,
							'net_amount'     => 33.00,
						),
					),
				),
			),
			array(
				'allow_woo_order_projection' => true,
				'reconciliation_status'      => 'reconciled',
				'projection_mode'            => 'draft',
			)
		);

		$this->assertTrue( $result['replayed'] );
		$this->assertSame( 'projected', $result['projection'][0]['status'] ?? '' );
		$this->assertSame( 7801, $result['projection'][0]['woo_order_id'] ?? 0 );
		$this->assertSame( 7801, $result['normalized_rows'][0]['woo_order_id'] ?? 0 );

		$metadata = json_decode( (string) ( $effects->saved[0]['metadata_json'] ?? '' ), true );
		$this->assertSame( 7801, $metadata['projection'][0]['woo_order_id'] ?? 0 );
	}

	public function testReplayRawEventReturnsAlreadyReplayedWhenEffectExistsForSameRunAndEvent(): void {
		// Effects repo reports that raw_event 42 was already processed in run 99.
		$effects = new class() extends \AIMS_Sync_Effect_Repository {
			public function __construct() {}

			public function has_effect_for_raw_event( int $sync_run_id, int $raw_event_id ): bool {
				return 99 === $sync_run_id && 42 === $raw_event_id;
			}
		};

		$normalized_sales = new class() extends \AIMS_Square_Normalized_Sale_Repository {
			public function __construct() {}

			public int $save_count = 0;

			public function save( array $data, int $id = 0 ): int {
				$this->save_count++;
				return 1;
			}
		};

		$service = new \AIMS_Square_Replay_Service(
			null,
			$normalized_sales,
			null,
			null,
			null,
			$effects
		);

		$raw_event = array( 'id' => 42, 'payload' => array( 'line_items' => array() ) );
		$result    = $service->replay_raw_event( $raw_event, array( 'sync_run_id' => 99 ) );

		$this->assertFalse( $result['replayed'], 'Already-replayed event should return replayed=false.' );
		$this->assertTrue( $result['already_replayed'] ?? false, 'already_replayed flag should be set.' );
		$this->assertSame( 0, $normalized_sales->save_count, 'No normalized sale should be written when dedup fires.' );
	}

	public function testReplayRawEventProceedsNormallyWhenNoExistingEffectForRun(): void {
		// No existing effects - dedup guard should not fire.
		$effects = new class() extends \AIMS_Sync_Effect_Repository {
			public function __construct() {}

			public function has_effect_for_raw_event( int $sync_run_id, int $raw_event_id ): bool {
				return false;
			}

			public function save( array $data, int $id = 0 ): int {
				return 1;
			}
		};

		$service = new \AIMS_Square_Replay_Service(
			null,
			null,
			null,
			null,
			null,
			$effects
		);

		$raw_event = array(
			'id'      => 55,
			'payload' => array(
				'id'         => 'ORDER_55',
				'line_items' => array(
					array( 'uid' => 'LI1', 'quantity' => 1, 'total_money' => array( 'amount' => 1000 ) ),
				),
			),
		);

		$result = $service->replay_raw_event( $raw_event, array( 'sync_run_id' => 10 ) );

		$this->assertTrue( $result['replayed'], 'Non-duplicate event should proceed with replay=true.' );
		$this->assertFalse( $result['already_replayed'] ?? false, 'already_replayed should not be set for fresh events.' );
	}

	public function testReplayRawEventPassesCustomerAndAddressIntoProjectionContext(): void {
		$captured_context = array();

		$projection = new \AIMS_Woo_Order_Projection_Service(
			static function( array $sale_record, array $context = array() ) use ( &$captured_context ): array {
				unset( $sale_record );
				$captured_context = $context;

				return array(
					'woo_order_id'    => 7802,
					'projection_mode' => (string) ( $context['projection_mode'] ?? 'draft' ),
				);
			}
		);

		$service = new \AIMS_Square_Replay_Service(
			null,
			null,
			null,
			null,
			new \AIMS_Square_Normalization_Service(),
			null,
			null,
			null,
			$projection
		);

		$service->replay_raw_event(
			array(
				'id'      => 1004,
				'payload' => array(
					'id'               => 'SQ-ORDER-790',
					'created_at'       => '2026-04-11T12:05:00Z',
					'location_id'      => 'LOC-13',
					'customer'         => array(
						'id'            => 'CUST-11',
						'given_name'    => 'Jordan',
						'family_name'   => 'Lake',
						'email_address' => 'jordan@example.com',
						'phone_number'  => '+1 555 555 0101',
					),
					'shipping_address' => array(
						'id'                             => 'ADDR-11',
						'address_line_1'                 => '456 Pine St',
						'locality'                       => 'Portland',
						'administrative_district_level_1'=> 'OR',
						'postal_code'                    => '97202',
						'country'                        => 'US',
					),
					'line_items'       => array(
						array(
							'uid'            => 'LINE-4',
							'woo_product_id' => 904,
							'sku'            => 'SKU-304',
							'quantity'       => 1,
							'net_amount'     => 45.00,
						),
					),
				),
			),
			array(
				'allow_woo_order_projection' => true,
				'reconciliation_status'      => 'reconciled',
				'projection_mode'            => 'draft',
			)
		);

		$this->assertSame( 'jordan@example.com', $captured_context['customer_data']['email_address'] ?? '' );
		$this->assertSame( '+1 555 555 0101', $captured_context['customer_data']['phone_number'] ?? '' );
		$this->assertSame( '456 Pine St', $captured_context['address_data']['address_line_1'] ?? '' );
	}

	public function testReplayRawEventForcesPendingProjectionWhenChargeRuleRequestsUnfulfilledControl(): void {
		update_option(
			\AIMS_Square_Order_Charge_Rule_Service::OPTION_RULES,
			array(
				array(
					'code'                     => 'event_pay_later',
					'label'                    => 'Event Pay Later',
					'square_charge_name'       => 'Event Pay Later',
					'force_unfulfilled'        => true,
					'force_pending_projection' => true,
				),
			)
		);

		$captured_context = array();

		$projection = new \AIMS_Woo_Order_Projection_Service(
			static function( array $sale_record, array $context = array() ) use ( &$captured_context ): array {
				unset( $sale_record );
				$captured_context = $context;

				return array(
					'woo_order_id'    => 7803,
					'projection_mode' => (string) ( $context['projection_mode'] ?? 'draft' ),
				);
			}
		);

		$service = new \AIMS_Square_Replay_Service(
			null,
			null,
			null,
			null,
			new \AIMS_Square_Normalization_Service(),
			null,
			null,
			null,
			$projection
		);

		$service->replay_raw_event(
			array(
				'id'      => 1005,
				'payload' => array(
					'id'               => 'SQ-ORDER-791',
					'created_at'       => '2026-04-11T12:10:00Z',
					'location_id'      => 'LOC-15',
					'service_charges'  => array(
						array(
							'uid'          => 'svc-event-pay-later',
							'name'         => 'Event Pay Later',
							'amount_money' => array( 'amount' => 0, 'currency' => 'USD' ),
						),
					),
					'line_items'       => array(
						array(
							'uid'            => 'LINE-5',
							'woo_product_id' => 905,
							'sku'            => 'SKU-305',
							'quantity'       => 1,
							'net_amount'     => 30.00,
						),
					),
				),
			),
			array(
				'allow_woo_order_projection'    => true,
				'allow_unreconciled_projection' => false,
				'reconciliation_status'         => 'pending',
				'projection_mode'               => 'draft',
			)
		);

		$this->assertSame( 'pending', $captured_context['projection_mode'] ?? '' );
		$this->assertTrue( $captured_context['allow_unreconciled_projection'] ?? false );
	}
}
