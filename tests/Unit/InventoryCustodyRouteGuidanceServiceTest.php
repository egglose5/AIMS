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

	public function testRouteGuidanceRetainsExceptionalFlowFlagsForDirectCollectionAndRecovery(): void {
		$endpoint_repo = new class() extends \AIMS_Inventory_Custody_Endpoint_Repository {
			public function __construct() {}

			public function find( int $endpoint_id ): ?array {
				if ( 41 === $endpoint_id ) {
					return array(
						'id' => 41,
						'endpoint_key' => 'stitcher-handoff',
						'endpoint_type' => 'vendor',
						'endpoint_status' => 'active',
						'default_route_policy' => 'guidance',
						'allows_direct_collection' => 1,
						'allows_direct_recovery' => 1,
					);
				}

				return null;
			}
		};

		$relationships = new class() extends \AIMS_Inventory_Custody_Endpoint_Relationship_Repository {
			public function __construct() {}

			public function get_for_source_endpoint( int $source_endpoint_id, array $args = array() ): array {
				return array();
			}
		};

		$service = new \AIMS_Inventory_Custody_Route_Guidance_Service( $endpoint_repo, $relationships );
		$result  = $service->get_route_guidance_for_endpoint( 41 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'guidance', $result['default_route_policy'] );
		$this->assertTrue( $result['allows_direct_collection'] );
		$this->assertTrue( $result['allows_direct_recovery'] );
		$this->assertEmpty( $result['routes'] );
		$this->assertNull( $result['default_route'] );
	}

	public function testRouteGuidanceForRuntimeUserResolvesPersistedNodeReference(): void {
		$this->registerRuntimeRoleFromTemplate(
			'aims_test_runtime_vendor_user',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
			),
			'Runtime Vendor User'
		);

		\AIMS\Tests\Support\TestState::set_current_user_id( 77 );
		\AIMS\Tests\Support\TestState::set_user(
			77,
			(object) array(
				'ID'           => 77,
				'display_name' => 'Vendor Runtime',
				'roles'        => array( 'aims_test_runtime_vendor_user' ),
			)
		);

		$endpoint_repo = new class() extends \AIMS_Inventory_Custody_Endpoint_Repository {
			public function __construct() {}

			public function get_active_for_node( string $node_ref_type, int $node_ref_id ): array {
				return array(
					array(
						'id' => 33,
						'endpoint_key' => 'vendor-runtime',
						'endpoint_type' => 'vendor',
						'endpoint_status' => 'active',
					),
				);
			}

			public function find( int $endpoint_id ): ?array {
				return array(
					'id' => $endpoint_id,
					'endpoint_key' => 'vendor-runtime',
					'endpoint_type' => 'vendor',
					'endpoint_status' => 'active',
					'default_route_policy' => 'guidance',
					'allows_direct_collection' => 1,
					'allows_direct_recovery' => 1,
				);
			}
		};

		$relationship_repo = new class() extends \AIMS_Inventory_Custody_Endpoint_Relationship_Repository {
			public function __construct() {}

			public function get_for_source_endpoint( int $source_endpoint_id, array $args = array() ): array {
				return array(
					array(
						'id' => 904,
						'source_endpoint_id' => 33,
						'target_endpoint_id' => 34,
						'relationship_key' => 'default_route',
						'route_priority' => 1,
						'is_default_route' => 1,
						'is_active' => 1,
						'guidance_label' => 'Persisted default',
					),
				);
			}
		};

		$service = new \AIMS_Inventory_Custody_Route_Guidance_Service( $endpoint_repo, $relationship_repo );
		$result  = $service->get_route_guidance_for_runtime_user( 77 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'vendor', $result['node_ref_type'] );
		$this->assertSame( 77, $result['node_ref_id'] );
		$this->assertSame( 34, $result['guidance'][0]['default_route']['target_endpoint_id'] );
	}
}
