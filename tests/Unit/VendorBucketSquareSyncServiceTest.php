<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class VendorBucketSquareSyncServiceTest extends \AIMS\Tests\TestCase {
	public function testSyncBucketToVendorLocationPushesBucketContentsToSquareManifestAndUpdatesBucket(): void {
		TestState::set_product(
			901,
			new class() {
				public function get_sku(): string {
					return 'SKU-901';
				}
			}
		);

		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function __construct() {}

			public function get_bucket_contents_summary( int $bucket_id ): array {
				return array(
					array(
						'bucket_id'         => $bucket_id,
						'vendor_id'         => 5,
						'product_id'        => 901,
						'quantity'          => 2.5,
						'reserved_quantity' => 0.0,
					),
				);
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public array $updates = array();

			public function __construct() {}

			public function find_with_context( int $bucket_id ): ?array {
				return array(
					'id'                 => $bucket_id,
					'bucket_code'        => 'BIN-77',
					'bucket_label'       => 'Vendor Tote',
					'vendor_id'          => 5,
					'square_location_id' => '',
				);
			}

			public function update_square_location_id( int $bucket_id, string $square_location_id ): bool {
				$this->updates[] = array(
					'bucket_id'          => $bucket_id,
					'square_location_id' => $square_location_id,
				);

				return true;
			}
		};

		$vendors = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function get_vendor( int $vendor_id ): ?array {
				return array(
					'id'                 => $vendor_id,
					'vendor_name'        => 'Vendor South',
					'square_location_id' => 'LOC-77',
				);
			}
		};

		$client = new class() extends \AIMS_Headless_Api_Client {
			public array $payloads = array();

			public function __construct() {}

			public function push_manifest( array $payload ): array {
				$this->payloads[] = $payload;

				return array(
					'success' => true,
					'json'    => array(
						'result' => array(
							'square' => array(
								array(
									'sku'     => 'SKU-901',
									'success' => true,
								),
							),
						),
					),
				);
			}
		};

		$charge_rules = new class() extends \AIMS_Square_Order_Charge_Rule_Service {
			public function get_push_rules(): array {
				return array(
					array(
						'code'               => 'line_unfulfilled',
						'square_charge_name' => 'Unfulfilled Line Charge',
						'default_amount'     => 2.5,
					),
				);
			}
		};

		$service = new \AIMS_Vendor_Bucket_Square_Sync_Service( $positions, $physical_buckets, $vendors, $client, $charge_rules );
		$result  = $service->sync_bucket_to_vendor_location(
			200,
			5,
			array(
				'event_id'      => 10,
				'reference_id'  => 'vendor-checkin-701',
				'reference_type'=> 'vendor_event_checkin',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'LOC-77', $result['square_location_id'] );
		$this->assertSame( 1, $result['synced_skus'] );
		$this->assertCount( 1, $client->payloads );
		$this->assertSame( 'SKU-901', $client->payloads[0]['resolved_truth']['catalog'][0]['sku'] );
		$this->assertSame( 2.5, $client->payloads[0]['resolved_truth']['catalog'][0]['stock_quantity'] );
		$this->assertSame( 'LOC-77', $client->payloads[0]['resolved_truth']['catalog'][0]['square_location_id'] );
		$this->assertSame( 'line_unfulfilled', $client->payloads[0]['resolved_truth']['order_charge_rules'][0]['code'] );
		$this->assertSame(
			array(
				array(
					'bucket_id'          => 200,
					'square_location_id' => 'LOC-77',
				),
			),
			$physical_buckets->updates
		);
	}
}
