<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class InventoryOverviewDataProviderTest extends \AIMS\Tests\TestCase {
	public function testRouteModelUsesRuntimeEndpointDirectoryWhenAvailable(): void {
		TestState::set_current_user_id( 44 );
		TestState::set_user_capabilities(
			44,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			)
		);
		TestState::set_user(
			44,
			(object) array(
				'ID'           => 44,
				'display_name' => 'Operator One',
			)
		);

		$endpoint_directory = new class() {
			public function get_source_bucket_pool(): array {
				return array(
					array(
						'id'            => 100,
						'bucket_code'   => 'SRC-100',
						'bucket_label'  => 'Source Bucket',
						'endpoint_label' => 'Dispatch Dock',
					),
				);
			}

			public function get_target_bucket_pool(): array {
				return array(
					array(
						'id'            => 200,
						'bucket_code'   => 'TGT-200',
						'bucket_label'  => 'Target Bucket',
						'endpoint_label' => 'Receiving Dock',
					),
				);
			}

			public function get_suggested_route_label(): string {
				return 'Dock-to-dock';
			}

			public function get_suggested_route_note(): string {
				return 'Use the dock-to-dock route unless an elevated operator overrides it.';
			}
		};

		$provider = new \AIMS_Inventory_Overview_Data_Provider( null, null, null, $endpoint_directory );
		$model    = $provider->get_route_model();
		$operator = $provider->get_operator_context();

		$this->assertTrue( $operator['is_elevated'] );
		$this->assertSame( 'Dock-to-dock', $model['suggested_route_label'] );
		$this->assertSame( 'Dispatch Dock', $model['source_pool'][0]['endpoint_label'] );
		$this->assertSame( 'Receiving Dock', $model['target_pool'][0]['endpoint_label'] );
		$this->assertTrue( $model['can_override_route'] );
	}

	public function testRouteModelFallsBackToBucketRepositoryPoolsWhenNoEndpointDirectoryExists(): void {
		TestState::set_current_user_id( 45 );
		TestState::set_user(
			45,
			(object) array(
				'ID'           => 45,
				'display_name' => 'Operator Two',
			)
		);

		$bucket_repo = new class() extends \AIMS_Physical_Bucket_Repository {
			public function __construct() {}

			public function get_available_for_planning( array $args = array() ): array {
				return array(
					array(
						'id'                   => 300,
						'bucket_code'          => 'B-300',
						'bucket_label'         => 'Dispatch Bin',
						'bucket_type'          => 'standard',
						'status'               => 'available',
						'current_storage_location' => array(
							'location_name' => 'Dispatch Dock',
						),
						'home_storage_location' => array(
							'location_name' => 'Home Bay',
						),
					),
					array(
						'id'                   => 301,
						'bucket_code'          => 'B-301',
						'bucket_label'         => 'Receipt Bin',
						'bucket_type'          => 'standard',
						'status'               => 'available',
						'current_storage_location' => array(
							'location_name' => 'Receiving Dock',
						),
						'home_storage_location' => array(
							'location_name' => 'Return Bay',
						),
					),
				);
			}
		};

		$provider = new \AIMS_Inventory_Overview_Data_Provider( null, null, $bucket_repo );
		$model    = $provider->get_route_model();

		$this->assertCount( 2, $model['source_pool'] );
		$this->assertCount( 2, $model['target_pool'] );
		$this->assertSame( 'Source Endpoint Pool', $model['source_pool'][0]['pool_label'] );
		$this->assertSame( 'Target Endpoint Pool', $model['target_pool'][0]['pool_label'] );
		$this->assertSame( 'Dispatch Dock', $model['source_pool'][0]['endpoint_label'] );
		$this->assertSame( 'Home Bay', $model['target_pool'][0]['endpoint_label'] );
		$this->assertFalse( $model['can_override_route'] );
	}

	public function testAvailableBucketsDelegateToSourceBucketSourcingService(): void {
		TestState::set_current_user_id( 46 );
		TestState::set_user(
			46,
			(object) array(
				'ID'           => 46,
				'display_name' => 'Operator Three',
			)
		);

		$bucket_sourcing = new class() extends \AIMS_Inventory_Bucket_Sourcing_Service {
			public array $calls = array();

			public function __construct() {}

			public function get_source_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
				$this->calls[] = array(
					'node_id'   => $node_id,
					'node_type' => $node_type,
					'context'   => $context,
				);

				return array(
					array(
						'id'            => 410,
						'bucket_code'   => 'SRC-410',
						'bucket_label'  => 'Source Dock Bucket',
						'bucket_type'   => 'standard',
						'status'        => 'available',
						'endpoint_key'  => 'vendor',
						'endpoint_label'=> 'Vendor Dock',
					),
				);
			}

			public function get_target_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
				return array();
			}

			public function get_bucket_sourcing_context( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
				return array(
					'node_id'         => $node_id,
					'node_type'       => $node_type,
					'source_buckets'  => $this->get_source_buckets( $node_id, $node_type, $context ),
					'target_buckets'  => $this->get_target_buckets( $node_id, $node_type, $context ),
				);
			}
		};

		$provider = new \AIMS_Inventory_Overview_Data_Provider( null, null, null, null, null, $bucket_sourcing );
		$buckets  = $provider->get_available_buckets( 46 );

		$this->assertCount( 1, $bucket_sourcing->calls );
		$this->assertSame( 46, $bucket_sourcing->calls[0]['node_id'] );
		$this->assertSame( 'vendor', $bucket_sourcing->calls[0]['node_type'] );
		$this->assertSame( 46, (int) $bucket_sourcing->calls[0]['context']['vendor_id'] );
		$this->assertCount( 1, $buckets );
		$this->assertSame( 'SRC-410', $buckets[0]['bucket_code'] );
	}
}
