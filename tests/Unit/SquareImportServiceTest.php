<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareImportServiceTest extends \AIMS\Tests\TestCase {
	public function testPersistQueueToSalesFlowKeepsWooProjectionBehindReconciliationGate(): void {
		$queue = new class() extends \AIMS_Square_Import_Queue_Repository {
			public array $processed = array();
			public array $errored = array();

			public function __construct() {}

			public function save( array $data, int $queue_id = 0 ): int {
				unset( $data, $queue_id );
				return 900;
			}

			public function mark_processed( int $queue_id, ?string $processed_at = null ): bool {
				$this->processed[] = array( 'queue_id' => $queue_id, 'processed_at' => $processed_at );
				return true;
			}

			public function mark_error( int $queue_id ): bool {
				$this->errored[] = $queue_id;
				return true;
			}
		};

		$sales = new class() extends \AIMS_Square_Sale_Repository {
			public array $saved = array();

			public function __construct() {}

			public function save( array $data ): int {
				$this->saved[] = $data;
				return 1201;
			}
		};

		$customers = new class() extends \AIMS_Customer_Repository {
			public function __construct() {}

			public function find_by_square_customer_id( string $square_customer_id ): ?array {
				unset( $square_customer_id );
				return array( 'id' => 3001 );
			}

			public function save( array $data, int $customer_id = 0 ): int {
				unset( $data, $customer_id );
				return 3001;
			}
		};

		$addresses = new class() extends \AIMS_Customer_Address_Repository {
			public function __construct() {}

			public function find_by_square_address_id( string $square_address_id ): ?array {
				unset( $square_address_id );
				return array( 'id' => 4001 );
			}

			public function save( array $data, int $address_id = 0 ): int {
				unset( $data, $address_id );
				return 4001;
			}
		};

		$fulfillment = new class() extends \AIMS_Fulfillment_Service {
			public array $created = array();

			public function __construct() {}

			public function create_allocation( array $data ): int {
				$this->created[] = $data;
				return 5001;
			}
		};

		$service = new \AIMS_Square_Import_Service(
			$queue,
			$sales,
			$customers,
			$addresses,
			$fulfillment,
			new \AIMS_Square_Normalization_Service(),
			null,
			null,
			new \AIMS_Woo_Order_Projection_Service()
		);

		$result = $service->persist_queue_to_sales_flow(
			array(
				'id'                         => 'SQ-IMPORT-100',
				'created_at'                 => '2026-04-11T14:00:00Z',
				'location_id'                => 'LOC-9',
				'event_id'                   => 61,
				'vendor_id'                  => 31,
				'allow_woo_order_projection' => true,
				'reconciliation_status'      => 'pending',
				'line_items'                 => array(
					array(
						'uid'            => 'LINE-100',
						'woo_product_id' => 910,
						'sku'            => 'SKU-910',
						'quantity'       => 1,
						'net_amount'     => 21.00,
					),
				),
			),
			900
		);

		$this->assertSame( array( 1201 ), $result['sale_ids'] ?? array() );
		$this->assertSame( 'skipped', $result['projection'][0]['status'] ?? '' );
		$this->assertSame( 'awaiting_reconciliation', $result['projection'][0]['reason'] ?? '' );
		$this->assertCount( 1, $sales->saved );
	}

	public function testPersistQueueToSalesFlowProjectsDraftWooOrderWhenReconciled(): void {
		$queue = new class() extends \AIMS_Square_Import_Queue_Repository {
			public function __construct() {}

			public function save( array $data, int $queue_id = 0 ): int {
				unset( $data, $queue_id );
				return 901;
			}

			public function mark_processed( int $queue_id, ?string $processed_at = null ): bool {
				unset( $queue_id, $processed_at );
				return true;
			}

			public function mark_error( int $queue_id ): bool {
				unset( $queue_id );
				return false;
			}
		};

		$sales = new class() extends \AIMS_Square_Sale_Repository {
			public array $saved = array();

			public function __construct() {}

			public function save( array $data ): int {
				$this->saved[] = $data;
				return 2201;
			}
		};

		$customers = new class() extends \AIMS_Customer_Repository {
			public function __construct() {}

			public function find_by_square_customer_id( string $square_customer_id ): ?array {
				unset( $square_customer_id );
				return array( 'id' => 3002 );
			}
		};

		$addresses = new class() extends \AIMS_Customer_Address_Repository {
			public function __construct() {}

			public function find_by_square_address_id( string $square_address_id ): ?array {
				unset( $square_address_id );
				return array( 'id' => 4002 );
			}
		};

		$fulfillment = new class() extends \AIMS_Fulfillment_Service {
			public function __construct() {}

			public function create_allocation( array $data ): int {
				unset( $data );
				return 5002;
			}
		};

		$projection = new \AIMS_Woo_Order_Projection_Service(
			static function( array $sale_record, array $context = array() ): array {
				unset( $sale_record );

				return array(
					'woo_order_id'    => 8801,
					'projection_mode' => (string) ( $context['projection_mode'] ?? 'draft' ),
				);
			}
		);

		$service = new \AIMS_Square_Import_Service(
			$queue,
			$sales,
			$customers,
			$addresses,
			$fulfillment,
			new \AIMS_Square_Normalization_Service(),
			null,
			null,
			$projection
		);

		$result = $service->persist_queue_to_sales_flow(
			array(
				'id'                         => 'SQ-IMPORT-101',
				'created_at'                 => '2026-04-11T14:05:00Z',
				'location_id'                => 'LOC-10',
				'event_id'                   => 62,
				'vendor_id'                  => 32,
				'allow_woo_order_projection' => true,
				'reconciliation_status'      => 'reconciled',
				'projection_mode'            => 'draft',
				'line_items'                 => array(
					array(
						'uid'            => 'LINE-101',
						'woo_product_id' => 911,
						'sku'            => 'SKU-911',
						'quantity'       => 1,
						'net_amount'     => 32.00,
					),
				),
			),
			901
		);

		$this->assertSame( 'projected', $result['projection'][0]['status'] ?? '' );
		$this->assertSame( 8801, $result['projection'][0]['woo_order_id'] ?? 0 );
		$this->assertSame( 8801, $sales->saved[1]['woo_order_id'] ?? 0 );
		$this->assertCount( 2, $sales->saved );
	}

	public function testPersistQueueToSalesFlowRecordsProjectionEffectWithRunContext(): void {
		$queue = new class() extends \AIMS_Square_Import_Queue_Repository {
			public function __construct() {}

			public function save( array $data, int $queue_id = 0 ): int {
				unset( $data, $queue_id );
				return 902;
			}

			public function mark_processed( int $queue_id, ?string $processed_at = null ): bool {
				unset( $queue_id, $processed_at );
				return true;
			}
		};

		$sales = new class() extends \AIMS_Square_Sale_Repository {
			public function __construct() {}

			public function save( array $data ): int {
				unset( $data );
				return 3301;
			}
		};

		$customers = new class() extends \AIMS_Customer_Repository {
			public function __construct() {}

			public function find_by_square_customer_id( string $square_customer_id ): ?array {
				unset( $square_customer_id );
				return array( 'id' => 3003 );
			}
		};

		$addresses = new class() extends \AIMS_Customer_Address_Repository {
			public function __construct() {}

			public function find_by_square_address_id( string $square_address_id ): ?array {
				unset( $square_address_id );
				return array( 'id' => 4003 );
			}
		};

		$fulfillment = new class() extends \AIMS_Fulfillment_Service {
			public function __construct() {}

			public function create_allocation( array $data ): int {
				unset( $data );
				return 5003;
			}
		};

		$effects = new class() extends \AIMS_Sync_Effect_Repository {
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $effect_id = 0 ): int {
				unset( $effect_id );
				$this->saved[] = $data;
				return 9901;
			}
		};

		$projection = new \AIMS_Woo_Order_Projection_Service(
			static function( array $sale_record, array $context = array() ): array {
				unset( $sale_record, $context );

				return array(
					'woo_order_id'    => 8811,
					'projection_mode' => 'draft',
				);
			}
		);

		$service = new \AIMS_Square_Import_Service(
			$queue,
			$sales,
			$customers,
			$addresses,
			$fulfillment,
			new \AIMS_Square_Normalization_Service(),
			null,
			null,
			$projection,
			$effects
		);

		$service->persist_queue_to_sales_flow(
			array(
				'id'                         => 'SQ-IMPORT-102',
				'created_at'                 => '2026-04-11T14:10:00Z',
				'location_id'                => 'LOC-11',
				'event_id'                   => 63,
				'vendor_id'                  => 33,
				'allow_woo_order_projection' => true,
				'reconciliation_status'      => 'reconciled',
				'projection_mode'            => 'draft',
				'sync_run_id'                => 55,
				'sync_action_id'             => 78,
				'line_items'                 => array(
					array(
						'uid'            => 'LINE-102',
						'woo_product_id' => 912,
						'sku'            => 'SKU-912',
						'quantity'       => 1,
						'net_amount'     => 42.00,
					),
				),
			),
			902
		);

		$this->assertCount( 1, $effects->saved );
		$this->assertSame( 55, $effects->saved[0]['sync_run_id'] ?? 0 );
		$this->assertSame( 78, $effects->saved[0]['sync_action_id'] ?? 0 );
		$this->assertSame( 'import_projection', $effects->saved[0]['effect_type'] ?? '' );

		$metadata = json_decode( (string) ( $effects->saved[0]['metadata_json'] ?? '' ), true );
		$this->assertSame( 8811, $metadata['projection'][0]['woo_order_id'] ?? 0 );
	}
}
