<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class InventoryEndpointDirectoryServiceTest extends \AIMS\Tests\TestCase {
	public function testRuntimeEndpointsSurfaceAuthorizedWarehouseSupervisorAndVendorChoices(): void {
		TestState::set_current_user_id( 52 );
		TestState::set_user_capabilities(
			52,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS,
				\AIMS_Capabilities::CAP_MANAGE_PHYSICAL_BUCKETS,
				\AIMS_Capabilities::CAP_MANAGE_EVENT_BUCKETS,
				\AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL,
				\AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING,
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL,
				\AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL,
				\AIMS_Capabilities::CAP_MANAGE_VENDOR_ACCESS,
			)
		);
		TestState::set_user(
			52,
			(object) array(
				'ID'           => 52,
				'display_name' => 'Warehouse Operator',
				'roles'        => array( \AIMS_Capabilities::ROLE_WAREHOUSE_USER, \AIMS_Capabilities::ROLE_SUPERVISOR_USER ),
			)
		);
		TestState::set_user(
			81,
			(object) array(
				'ID'           => 81,
				'display_name' => 'Abby',
				'roles'        => array( \AIMS_Capabilities::ROLE_SUPERVISOR_USER ),
			)
		);
		TestState::set_user_capabilities(
			81,
			array(
				\AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL,
			)
		);
		TestState::set_user(
			91,
			(object) array(
				'ID'           => 91,
				'display_name' => 'Melissa',
				'roles'        => array( \AIMS_Capabilities::ROLE_VENDOR_USER ),
			)
		);
		TestState::set_user_capabilities(
			91,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL,
				\AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL,
			)
		);

		$service = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints = $service->get_runtime_endpoints( 52 );
		$choices   = $service->get_endpoint_choices( 52 );
		$suggestions = $service->get_route_suggestions( 52 );
		$resolved = $service->resolve_runtime_endpoint( 52, 'warehouse_main' );

		$this->assertArrayHasKey( 'warehouse', $endpoints );
		$this->assertArrayHasKey( 'supervisor', $endpoints );
		$this->assertArrayHasKey( 'warehouse_main', $endpoints );
		$this->assertArrayHasKey( 'supervisor_81', $endpoints );
		$this->assertArrayHasKey( 'vendor_91', $endpoints );
		$this->assertSame( 'Main Warehouse', $choices['warehouse'] );
		$this->assertSame( 'warehouse_main', $resolved['endpoint_key'] );
		$this->assertNotEmpty( $suggestions );

		foreach ( $suggestions as $suggestion ) {
			$this->assertNotSame( $suggestion['source_endpoint_key'], $suggestion['target_endpoint_key'] );
		}
	}

	public function testWarehouseOperatorRoleExplicitlySurfacesWarehouseRuntimeEndpoint(): void {
		TestState::set_current_user_id( 64 );
		TestState::set_user_capabilities(
			64,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);
		TestState::set_user(
			64,
			(object) array(
				'ID'           => 64,
				'display_name' => 'Warehouse Lead',
				'roles'        => array( \AIMS_Capabilities::ROLE_WAREHOUSE_USER ),
			)
		);

		$service  = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints = $service->get_runtime_endpoints( 64 );
		$resolved  = $service->resolve_runtime_endpoint( 64 );

		$this->assertArrayHasKey( 'warehouse', $endpoints );
		$this->assertArrayHasKey( 'warehouse_main', $endpoints );
		$this->assertTrue( ! empty( $endpoints['warehouse_main']['is_current'] ) );
		$this->assertSame( 'warehouse_main', $resolved['endpoint_key'] );
		$this->assertSame( 'warehouse', $resolved['node_type'] );
	}

	public function testCapabilityOnlyUserSurfacesWarehouseRuntimeEndpointWithoutLegacyRole(): void {
		TestState::set_current_user_id( 67 );
		TestState::set_user_capabilities(
			67,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS,
			)
		);
		TestState::set_user(
			67,
			(object) array(
				'ID'           => 67,
				'display_name' => 'Warehouse Capability User',
				'roles'        => array(),
			)
		);

		$service   = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints = $service->get_runtime_endpoints( 67 );
		$resolved  = $service->resolve_runtime_endpoint( 67 );

		$this->assertArrayHasKey( 'warehouse', $endpoints );
		$this->assertArrayHasKey( 'warehouse_main', $endpoints );
		$this->assertSame( 'warehouse_main', $resolved['endpoint_key'] );
		$this->assertSame( 'warehouse', $resolved['node_type'] );
	}

	public function testBypassCapabilitySurfacesWarehouseAndTransferTargetsForTrustedOperator(): void {
		TestState::set_current_user_id( 65 );
		TestState::set_user_capabilities(
			65,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			)
		);
		TestState::set_user(
			65,
			(object) array(
				'ID'           => 65,
				'display_name' => 'Trusted Operator',
				'roles'        => array( \AIMS_Capabilities::ROLE_MANAGER_USER ),
			)
		);
		TestState::set_user(
			82,
			(object) array(
				'ID'           => 82,
				'display_name' => 'Abby',
				'roles'        => array( \AIMS_Capabilities::ROLE_SUPERVISOR_USER ),
			)
		);
		TestState::set_user_capabilities(
			82,
			array(
				\AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL,
				\AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING,
			)
		);
		TestState::set_user(
			92,
			(object) array(
				'ID'           => 92,
				'display_name' => 'Melissa',
				'roles'        => array( \AIMS_Capabilities::ROLE_VENDOR_USER ),
			)
		);
		TestState::set_user_capabilities(
			92,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL,
				\AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL,
			)
		);

		$service   = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints = $service->get_runtime_endpoints( 65 );

		$this->assertArrayHasKey( 'warehouse_main', $endpoints );
		$this->assertArrayHasKey( 'supervisor_82', $endpoints );
		$this->assertArrayHasKey( 'vendor_92', $endpoints );
	}

	public function testResolveEndpointFromNodeHonorsExplicitNodeType(): void {
		TestState::set_current_user_id( 61 );
		TestState::set_user(
			61,
			(object) array(
				'ID'           => 61,
				'display_name' => 'Supervisor User',
				'roles'        => array( \AIMS_Capabilities::ROLE_SUPERVISOR_USER ),
			)
		);

		$service = new \AIMS_Inventory_Endpoint_Directory_Service();

		$this->assertSame( 11, $service->resolve_endpoint_from_node( 11, 'vendor', 0 )['node_id'] );
		$this->assertSame( 'supervisor', $service->resolve_endpoint_from_node( 11, 'supervisor', 0 )['endpoint_type'] );
		$this->assertSame( 'warehouse', $service->resolve_endpoint_from_node( 11, 'warehouse', 0 )['endpoint_type'] );
	}

	public function testUnauthorizedUserDoesNotSurfaceWarehouseRuntimeEndpoint(): void {
		TestState::set_current_user_id( 62 );
		TestState::set_user_capabilities(
			62,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL,
			)
		);
		TestState::set_user(
			62,
			(object) array(
				'ID'           => 62,
				'display_name' => 'Vendor User',
				'roles'        => array( \AIMS_Capabilities::ROLE_VENDOR_USER ),
			)
		);

		$service   = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints  = $service->get_runtime_endpoints( 62 );

		$this->assertArrayHasKey( 'vendor', $endpoints );
		$this->assertArrayNotHasKey( 'warehouse', $endpoints );
		$this->assertArrayNotHasKey( 'warehouse_main', $endpoints );
		$this->assertSame( array(), $service->get_route_suggestions( 62 ) );
	}

	public function testCustomWarehouseTemplateRoleSurfacesWarehouseEndpoint(): void {
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_warehouse_ops',
			'Warehouse Ops',
			\AIMS_Capabilities::ROLE_WAREHOUSE_USER,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY => true,
			)
		);

		TestState::set_current_user_id( 66 );
		TestState::set_user(
			66,
			(object) array(
				'ID'           => 66,
				'display_name' => 'Warehouse Ops',
				'roles'        => array( 'aims_custom_warehouse_ops' ),
			)
		);

		$service   = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints = $service->get_runtime_endpoints( 66 );

		$this->assertArrayHasKey( 'warehouse_main', $endpoints );
	}

	public function testStitchPortalCapabilitySurfacesStitcherEndpointInsteadOfVendorEndpoint(): void {
		TestState::set_current_user_id( 68 );
		TestState::set_user_capabilities(
			68,
			array(
				\AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL,
			)
		);
		TestState::set_user(
			68,
			(object) array(
				'ID'           => 68,
				'display_name' => 'Stitch Operator',
				'roles'        => array(),
			)
		);

		$service   = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints = $service->get_runtime_endpoints( 68 );
		$resolved  = $service->resolve_runtime_endpoint( 68 );

		$this->assertArrayHasKey( 'stitcher', $endpoints );
		$this->assertArrayHasKey( 'stitcher_68', $endpoints );
		$this->assertArrayNotHasKey( 'vendor', $endpoints );
		$this->assertSame( 'stitcher_68', $resolved['endpoint_key'] );
		$this->assertSame( 'stitcher', $resolved['node_type'] );
	}
}
