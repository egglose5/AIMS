<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class InventoryTransferAuthorizationServiceTest extends \AIMS\Tests\TestCase {
	public function testWarehouseOperatorRoleHasGlobalCustodyAuthority(): void {
		$this->registerRuntimeRoleFromTemplate(
			'aims_test_warehouse_operator',
			\AIMS_Capabilities::ROLE_WAREHOUSE_USER,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			),
			'Test Warehouse Operator'
		);

		TestState::set_current_user_id( 50 );
		TestState::set_user_capabilities(
			50,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			)
		);
		TestState::set_user(
			50,
			(object) array(
				'ID'           => 50,
				'display_name' => 'Warehouse Operator',
				'roles'        => array( 'aims_test_warehouse_operator' ),
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertTrue( $service->can_manage_inventory_transfers( 50 ) );
		$this->assertTrue( $service->can_manage_transfer_nodes( 50, 'vendor', 10, 'warehouse', 20 ) );
		$this->assertTrue( $service->can_override_transfer_route( 50, 'direct_collection' ) );
	}

	public function testBypassCapabilityCanBeGrantedToAnotherInventoryRole(): void {
		$this->registerRuntimeRoleFromTemplate(
			'aims_test_trusted_operator',
			\AIMS_Capabilities::ROLE_MANAGER_USER,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			),
			'Test Trusted Operator'
		);

		TestState::set_current_user_id( 56 );
		TestState::set_user(
			56,
			(object) array(
				'ID'           => 56,
				'display_name' => 'Trusted Operator',
				'roles'        => array( 'aims_test_trusted_operator' ),
			)
		);
		TestState::set_user_capabilities(
			56,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertTrue( $service->can_manage_inventory_transfers( 56 ) );
		$this->assertTrue( $service->can_override_transfer_route( 56, 'recovery' ) );
		$this->assertTrue( $service->can_manage_transfer_nodes( 56, 'stitcher', 30, 'vendor', 40, 'direct_collection' ) );
	}

	public function testNarrowerStorageAndBucketCapsDoNotGrantGlobalTransferAuthority(): void {
		$this->registerRuntimeRoleFromTemplate(
			'aims_test_scoped_supervisor',
			\AIMS_Capabilities::ROLE_SUPERVISOR_USER,
			array(
				\AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS,
				\AIMS_Capabilities::CAP_MANAGE_PHYSICAL_BUCKETS,
			),
			'Test Scoped Supervisor'
		);

		TestState::set_current_user_id( 51 );
		TestState::set_user(
			51,
			(object) array(
				'ID'           => 51,
				'display_name' => 'Scoped Operator',
				'roles'        => array( 'aims_test_scoped_supervisor' ),
			)
		);
		TestState::set_user_capabilities(
			51,
			array(
				\AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS,
				\AIMS_Capabilities::CAP_MANAGE_PHYSICAL_BUCKETS,
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertFalse( $service->can_manage_inventory_transfers( 51 ) );
		$this->assertFalse( $service->can_manage_transfer_nodes( 51, 'warehouse', 10, 'warehouse', 20 ) );
	}

	public function testCanOverrideTransferRouteRequiresElevatedInventoryAccess(): void {
		TestState::set_current_user_id( 52 );
		TestState::set_user_capabilities(
			52,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertFalse( $service->can_override_transfer_route( 52, 'direct_collection' ) );
	}

	public function testCanManageTransferNodesAllowsResolvedSourceAndTargetEndpoints(): void {
		TestState::set_current_user_id( 53 );
		TestState::set_user_capabilities(
			53,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);

		$endpoint_directory = new class() extends \AIMS_Inventory_Endpoint_Directory_Service {
			public function __construct() {}

			public function resolve_endpoint_from_node( int $node_id, string $node_type = '', int $user_id = 0 ): array {
				if ( 10 === $node_id && 'vendor' === $node_type ) {
					return array(
						'endpoint_key' => 'vendor_10',
						'endpoint_type' => 'vendor',
						'node_id' => 10,
					);
				}

				if ( 20 === $node_id && 'warehouse' === $node_type ) {
					return array(
						'endpoint_key' => 'warehouse_20',
						'endpoint_type' => 'warehouse',
						'node_id' => 20,
					);
				}

				return array();
			}
		};

		$service = new \AIMS_Inventory_Transfer_Authorization_Service( $endpoint_directory, new \AIMS_Person_Identity_Service() );

		$this->assertTrue( $service->can_manage_transfer_nodes( 53, 'vendor', 10, 'warehouse', 20 ) );
	}

	public function testCanManageTransferNodesRejectsUnresolvedTargetEventNode(): void {
		TestState::set_current_user_id( 54 );
		TestState::set_user_capabilities(
			54,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);

		$endpoint_directory = new class() extends \AIMS_Inventory_Endpoint_Directory_Service {
			public function __construct() {}

			public function resolve_endpoint_from_node( int $node_id, string $node_type = '', int $user_id = 0 ): array {
				if ( 10 === $node_id && 'vendor' === $node_type ) {
					return array(
						'endpoint_key' => 'vendor_10',
						'endpoint_type' => 'vendor',
						'node_id' => 10,
					);
				}

				return array();
			}
		};

		$service = new \AIMS_Inventory_Transfer_Authorization_Service( $endpoint_directory, new \AIMS_Person_Identity_Service() );

		$this->assertFalse( $service->can_manage_transfer_nodes( 54, 'vendor', 10, 'event', 99 ) );
	}

	public function testCanOverrideTransferRouteAllowsElevatedOperators(): void {
		TestState::set_current_user_id( 55 );
		TestState::set_user_capabilities(
			55,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertTrue( $service->can_override_transfer_route( 55, 'recovery' ) );
		$this->assertTrue( $service->can_use_exceptional_transfer_type( 55, 'termination_collection' ) );
		$this->assertTrue( $service->is_exceptional_transfer_type( 'direct_collection' ) );
		$this->assertSame( 'recovery', $service->normalize_transfer_type( 'Recovery' ) );
	}

	public function testCanActOnCustodyNodeGrantedViaScopeCustodyResponsibilityAssignment(): void {
		update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );
		// User 60 has basic inventory capability but no global authority and no endpoint resolution.
		// However, they have a SCOPE_CUSTODY responsibility assignment for node_id 77.
		TestState::set_current_user_id( 60 );
		TestState::set_user_capabilities( 60, array( \AIMS_Capabilities::CAP_MANAGE_INVENTORY ) );

		// Queue active assignments for user 60: one CUSTODY scope for node 77.
		$this->wpdb()->queue_results( array(
			array(
				'id'                 => 1,
				'user_id'            => 60,
				'responsibility_key' => 'vendor_manage_inventory',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_CUSTODY,
				'scope_ref_id'       => 77,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
		) );

		$endpoint_directory = new class() extends \AIMS_Inventory_Endpoint_Directory_Service {
			public function __construct() {}
			public function resolve_endpoint_from_node( int $node_id, string $node_type = '', int $user_id = 0 ): array {
				return array(); // no endpoint resolution
			}
		};

		$service = new \AIMS_Inventory_Transfer_Authorization_Service(
			$endpoint_directory,
			new \AIMS_Person_Identity_Service(),
			new \AIMS_Responsibility_Authorization_Service()
		);

		$this->assertTrue( $service->can_act_on_custody_node( 60, 'vendor', 77, 'dispatch' ), 'SCOPE_CUSTODY assignment should grant custody node access.' );
	}

	public function testCanActOnCustodyNodeDeniedWhenEndpointNotInScopeCustodyAssignment(): void {
		update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );
		// User 61 has a SCOPE_CUSTODY assignment for node 88 only, not node 99.
		TestState::set_current_user_id( 61 );
		TestState::set_user_capabilities( 61, array( \AIMS_Capabilities::CAP_MANAGE_INVENTORY ) );

		// Queue active assignments: CUSTODY for node 88 only.
		$this->wpdb()->queue_results( array(
			array(
				'id'                 => 2,
				'user_id'            => 61,
				'responsibility_key' => 'vendor_manage_inventory',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_CUSTODY,
				'scope_ref_id'       => 88,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
		) );

		$endpoint_directory = new class() extends \AIMS_Inventory_Endpoint_Directory_Service {
			public function __construct() {}
			public function resolve_endpoint_from_node( int $node_id, string $node_type = '', int $user_id = 0 ): array {
				return array();
			}
		};

		$service = new \AIMS_Inventory_Transfer_Authorization_Service(
			$endpoint_directory,
			new \AIMS_Person_Identity_Service(),
			new \AIMS_Responsibility_Authorization_Service()
		);

		$this->assertFalse( $service->can_act_on_custody_node( 61, 'vendor', 99, 'dispatch' ), 'Node 99 should not be accessible — only node 88 is in scope.' );
	}
}
