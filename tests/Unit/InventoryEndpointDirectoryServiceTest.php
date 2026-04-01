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
				'roles'        => array( 'aims_supervisor_user' ),
			)
		);
		TestState::set_user(
			81,
			(object) array(
				'ID'           => 81,
				'display_name' => 'Abby',
				'roles'        => array( 'aims_supervisor_user' ),
			)
		);
		TestState::set_user(
			91,
			(object) array(
				'ID'           => 91,
				'display_name' => 'Melissa',
				'roles'        => array( 'aims_vendor_user' ),
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

	public function testResolveEndpointFromNodeHonorsExplicitNodeType(): void {
		TestState::set_current_user_id( 61 );
		TestState::set_user(
			61,
			(object) array(
				'ID'           => 61,
				'display_name' => 'Supervisor User',
				'roles'        => array( 'aims_supervisor_user' ),
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
				'roles'        => array( 'aims_vendor_user' ),
			)
		);

		$service   = new \AIMS_Inventory_Endpoint_Directory_Service();
		$endpoints  = $service->get_runtime_endpoints( 62 );

		$this->assertArrayHasKey( 'vendor', $endpoints );
		$this->assertArrayNotHasKey( 'warehouse', $endpoints );
		$this->assertArrayNotHasKey( 'warehouse_main', $endpoints );
		$this->assertSame( array(), $service->get_route_suggestions( 62 ) );
	}
}
