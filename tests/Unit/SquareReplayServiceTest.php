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
}
