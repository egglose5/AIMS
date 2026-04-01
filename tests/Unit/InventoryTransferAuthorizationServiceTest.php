<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class InventoryTransferAuthorizationServiceTest extends \AIMS\Tests\TestCase {
	public function testCanOverrideTransferRouteRequiresElevatedInventoryAccess(): void {
		TestState::set_current_user_id( 51 );
		TestState::set_user_capabilities(
			51,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertFalse( $service->can_override_transfer_route( 51, 'direct_collection' ) );
	}

	public function testCanOverrideTransferRouteAllowsElevatedOperators(): void {
		TestState::set_current_user_id( 52 );
		TestState::set_user_capabilities(
			52,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

		$service = new \AIMS_Inventory_Transfer_Authorization_Service();

		$this->assertTrue( $service->can_override_transfer_route( 52, 'recovery' ) );
		$this->assertTrue( $service->can_use_exceptional_transfer_type( 52, 'termination_collection' ) );
		$this->assertTrue( $service->is_exceptional_transfer_type( 'direct_collection' ) );
		$this->assertSame( 'recovery', $service->normalize_transfer_type( 'Recovery' ) );
	}
}
