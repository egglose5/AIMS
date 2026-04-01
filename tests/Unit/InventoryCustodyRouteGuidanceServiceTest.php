<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryCustodyRouteGuidanceServiceTest extends \AIMS\Tests\TestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb()->reset();
	}

	public function testRouteGuidanceForEndpointReturnsDefaultRouteAndDirectFlowFlags(): void {
		$endpoint_repo = new class() extends \AIMS_Inventory_Custody_Endpoint_Repository {
			public function __construct() {}

			public function find( int $endpoint_id ): ?array {
				if ( 10 === $endpoint_id ) {
					return array(
						'id' => 10,
						'endpoint_key' => 'warehouse-main',
						'endpoint_type' => 'warehouse',
						'endpoint_status' => 'active',
						'default_route_policy' => 'guidance',
						'allows_direct_collection' => 1,
						'allows_direct_recovery' => 0,
					);
				}

				if ( 20 === $endpoint_id ) {
					return array(
						'id' => 20,
						'endpoint_key' => 'vendor-01',
						'endpoint_type' => 'vendor',
						'endpoint_status' => 'active',
					);
				}

				return null;
			}
		};

		$relationship_repo = new class() extends \AIMS_Inventory_Custody_Endpoint_Relationship_Repository {
			public function __construct() {}

			public function get_for_source_endpoint( int $source_endpoint_id, array $args = array() ): array {
				return array(
					array(
						'id' => 301,
						'source_endpoint_id' => 10,
						'target_endpoint_id' => 20,
						'relationship_key' => 'default_route',
						'relationship_type' => 'direct_route',
						'route_priority' => 1,
						'is_default_route' => 1,
						'is_active' => 1,
						'guidance_label' => 'Primary path',
					),
				);
			}
		};

		$service = new \AIMS_Inventory_Custody_Route_Guidance_Service( $endpoint_repo, $relationship_repo );
		$result = $service->get_route_guidance_for_endpoint( 10 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'guidance', $result['default_route_policy'] );
		$this->assertTrue( $result['allows_direct_collection'] );
		$this->assertFalse( $result['allows_direct_recovery'] );
		$this->assertCount( 1, $result['routes'] );
		$this->assertSame( 20, $result['routes'][0]['target_endpoint']['id'] );
	}

	public function testRouteGuidanceForNodeReturnsAllEndpointContexts(): void {
		$endpoint_repo = new class() extends \AIMS_Inventory_Custody_Endpoint_Repository {
			public function __construct() {}

			public function get_active_for_node( string $node_ref_type, int $node_ref_id ): array {
				return array(
					array(
						'id' => 10,
						'endpoint_key' => 'vendor-01',
						'endpoint_type' => 'vendor',
						'endpoint_status' => 'active',
						'default_route_policy' => 'guidance',
						'allows_direct_collection' => 1,
						'allows_direct_recovery' => 1,
					),
					array(
						'id' => 11,
						'endpoint_key' => 'vendor-01-backup',
						'endpoint_type' => 'vendor',
						'endpoint_status' => 'active',
						'default_route_policy' => 'guidance',
						'allows_direct_collection' => 1,
						'allows_direct_recovery' => 1,
					),
				);
			}

			public function find( int $endpoint_id ): ?array {
				return array(
					'id' => $endpoint_id,
					'endpoint_key' => 'endpoint-' . $endpoint_id,
					'endpoint_type' => 'vendor',
					'endpoint_status' => 'active',
					'default_route_policy' => 'guidance',
					'allows_direct_collection' => 1,
					'allows_direct_recovery' => 1,
				);
			}
		};

		$service = new \AIMS_Inventory_Custody_Route_Guidance_Service( $endpoint_repo, new class() extends \AIMS_Inventory_Custody_Endpoint_Relationship_Repository {} );
		$result = $service->get_route_guidance_for_node( 'vendor', 88 );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['endpoints'] );
		$this->assertCount( 2, $result['guidance'] );
	}
}
