<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class InventoryTransferAuthorizationServiceTest extends \AIMS\Tests\TestCase {
	public function testWarehouseOperatorRoleHasGlobalCustodyAuthority(): void {
		TestState::set_current_user_id( 50 );
		TestState::set_user_capabilities(
			50,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);
		TestState::set_user(
			50,
			(object) array(
				'ID'           => 50,
				'display_name' => 'Warehouse Operator',
				'roles'        => array( \AIMS_Capabilities::ROLE_WAREHOUSE_USER ),
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertTrue( $service->can_manage_inventory_transfers( 50 ) );
		$this->assertTrue( $service->can_manage_transfer_nodes( 50, 'vendor', 10, 'warehouse', 20 ) );
		$this->assertTrue( $service->can_override_transfer_route( 50, 'direct_collection' ) );
	}

	public function testNarrowerStorageAndBucketCapsDoNotGrantGlobalTransferAuthority(): void {
		TestState::set_current_user_id( 51 );
		TestState::set_user(
			51,
			(object) array(
				'ID'           => 51,
				'display_name' => 'Scoped Operator',
				'roles'        => array( 'aims_supervisor_user' ),
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
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertTrue( $service->can_override_transfer_route( 55, 'recovery' ) );
		$this->assertTrue( $service->can_use_exceptional_transfer_type( 55, 'termination_collection' ) );
		$this->assertTrue( $service->is_exceptional_transfer_type( 'direct_collection' ) );
		$this->assertSame( 'recovery', $service->normalize_transfer_type( 'Recovery' ) );
	}
}
