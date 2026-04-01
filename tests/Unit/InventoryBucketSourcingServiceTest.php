<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryBucketSourcingServiceTest extends \AIMS\Tests\TestCase {
	public function testSourceAndTargetBucketsUseEndpointSpecificFilters(): void {
		$repo = new class() extends \AIMS_Physical_Bucket_Repository {
			public array $calls = array();

			public function __construct() {}

			public function get_for_endpoint( string $endpoint_key, array $args = array() ): array {
				$this->calls[] = array(
					'method'       => 'endpoint',
					'endpoint_key' => $endpoint_key,
					'args'         => $args,
				);

				$status_list = array_values( array_map( 'sanitize_key', (array) ( $args['status'] ?? array() ) ) );
				$record = array(
					'id'                 => 700,
					'bucket_code'        => 'WH-700',
					'bucket_label'       => 'Warehouse Source',
					'bucket_type'        => 'standard',
					'status'             => in_array( 'in_transit', $status_list, true ) ? 'in_transit' : 'available',
					'current_location_type' => 'warehouse',
					'home_location_type' => 'warehouse',
					'vendor_id'          => 0,
				);

				return array( $record );
			}

			public function get_for_vendor( int $vendor_id ): array {
				$this->calls[] = array(
					'method'     => 'vendor',
					'vendor_id'  => $vendor_id,
				);

				return array(
					array(
						'id'                   => 701,
						'bucket_code'          => 'VN-701',
						'bucket_label'         => 'Vendor Bucket',
						'bucket_type'          => 'standard',
						'status'               => 'available',
						'current_location_type' => 'vendor',
						'home_location_type'    => 'vendor',
						'vendor_id'            => $vendor_id,
					),
				);
			}
		};

		$directory = new class() extends \AIMS_Inventory_Endpoint_Directory_Service {
			private $endpoints;

			public function __construct() {
				$this->endpoints = array(
					'warehouse' => array(
						'endpoint_key'          => 'warehouse',
						'endpoint_label'        => 'Warehouse',
						'node_type'             => 'warehouse',
						'bucket_statuses'       => array( 'available', 'staged', 'in_transit' ),
						'current_location_types'=> array( 'warehouse', 'staging' ),
						'suggested_targets'     => array( 'vendor', 'supervisor' ),
					),
					'supervisor' => array(
						'endpoint_key'          => 'supervisor',
						'endpoint_label'        => 'Supervisor',
						'node_type'             => 'supervisor',
						'bucket_statuses'       => array( 'available', 'staged' ),
						'current_location_types'=> array( 'vendor', 'warehouse', 'staging' ),
						'suggested_targets'     => array( 'warehouse', 'vendor' ),
					),
					'vendor' => array(
						'endpoint_key'          => 'vendor',
						'endpoint_label'        => 'Vendor',
						'node_type'             => 'vendor',
						'bucket_statuses'       => array( 'available', 'staged' ),
						'current_location_types'=> array( 'vendor', 'staging' ),
						'suggested_targets'     => array( 'warehouse', 'supervisor' ),
					),
				);
			}

			public function get_endpoint( string $endpoint_key ): ?array {
				$endpoint_key = sanitize_key( $endpoint_key );
				return $this->endpoints[ $endpoint_key ] ?? null;
			}

			public function resolve_endpoint_from_node( int $node_id, string $node_type = '', int $user_id = 0 ): array {
				$endpoint = $this->get_endpoint( '' !== sanitize_key( $node_type ) ? $node_type : 'vendor' );
				return is_array( $endpoint ) ? $endpoint : array();
			}
		};

		$service = new \AIMS_Inventory_Bucket_Sourcing_Service( $repo, $directory );
		$source  = $service->get_source_buckets( 14, 'warehouse' );
		$target  = $service->get_target_buckets( 14, 'warehouse' );

		$this->assertCount( 2, $repo->calls );
		$this->assertSame( 'endpoint', $repo->calls[0]['method'] );
		$this->assertSame( 'warehouse', $repo->calls[0]['endpoint_key'] );
		$this->assertContains( 'in_transit', $repo->calls[0]['args']['status'] );
		$this->assertContains( 'warehouse', $repo->calls[0]['args']['current_location_types'] );
		$this->assertSame( 'source', $source[0]['sourcing_direction'] );
		$this->assertSame( 'warehouse', $source[0]['endpoint_key'] );
		$this->assertContains( 'warehouse', $repo->calls[1]['args']['current_location_types'] );
		$this->assertNotContains( 'in_transit', $repo->calls[1]['args']['status'] );
		$this->assertSame( 'target', $target[0]['sourcing_direction'] );
	}

	public function testSupervisorEndpointWithoutVendorScopeReturnsNoBuckets(): void {
		$repo = new class() extends \AIMS_Physical_Bucket_Repository {
			public array $calls = array();

			public function __construct() {}

			public function get_for_endpoint( string $endpoint_key, array $args = array() ): array {
				$this->calls[] = array( 'endpoint_key' => $endpoint_key, 'args' => $args );
				return array();
			}
		};

		$directory = new class() extends \AIMS_Inventory_Endpoint_Directory_Service {
			public function __construct() {}

			public function resolve_endpoint_from_node( int $node_id, string $node_type = '', int $user_id = 0 ): array {
				return array(
					'endpoint_key'          => 'supervisor',
					'endpoint_label'        => 'Supervisor',
					'node_type'             => 'supervisor',
					'bucket_statuses'       => array( 'available', 'staged' ),
					'current_location_types'=> array( 'vendor', 'warehouse', 'staging' ),
					'suggested_targets'     => array( 'warehouse', 'vendor' ),
				);
			}
		};

		$service = new \AIMS_Inventory_Bucket_Sourcing_Service( $repo, $directory );
		$result  = $service->get_source_buckets( 0, 'supervisor' );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), $repo->calls );
	}
}
