<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class PersonIdentityServiceTest extends \AIMS\Tests\TestCase {
	public function testIsAimsPersonTrueForAimsRoleUser(): void {
		TestState::set_user(
			101,
			(object) array(
				'ID'    => 101,
				'roles' => array( 'aims_vendor_user' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();

		$this->assertTrue( $service->is_aims_person( 101 ) );
	}

	public function testIsAimsPersonFalseForGenericWooUser(): void {
		TestState::set_user(
			102,
			(object) array(
				'ID'    => 102,
				'roles' => array( 'customer' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();

		$this->assertFalse( $service->is_aims_person( 102 ) );
	}

	public function testSubtypesResolveVendorAndManager(): void {
		TestState::set_user(
			103,
			(object) array(
				'ID'    => 103,
				'roles' => array( 'aims_vendor_user', 'aims_manager_user' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();
		$subtypes = $service->get_person_subtypes( 103 );

		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_VENDOR, $subtypes );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_MANAGER, $subtypes );
	}

	public function testSubtypesResolveStitcher(): void {
		TestState::set_user(
			104,
			(object) array(
				'ID'    => 104,
				'roles' => array( 'aims_stitch_user' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();
		$subtypes = $service->get_person_subtypes( 104 );

		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_STITCH, $subtypes );
	}

	public function testSubtypesResolveWarehouseOperator(): void {
		TestState::set_user(
			105,
			(object) array(
				'ID'    => 105,
				'roles' => array( \AIMS_Capabilities::ROLE_WAREHOUSE_USER ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();
		$subtypes = $service->get_person_subtypes( 105 );

		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE, $subtypes );
	}

	public function testAimsRoleListIncludesWarehouseOperators(): void {
		$roles = \AIMS_Capabilities::get_aims_role_slugs();

		$this->assertContains( \AIMS_Capabilities::ROLE_WAREHOUSE_USER, $roles );
		$this->assertArrayHasKey( \AIMS_Capabilities::ROLE_WAREHOUSE_USER, \AIMS_Capabilities::get_portal_roles() );
	}

	public function testCustomRoleBasedOnWarehouseTemplateResolvesWarehouseSubtype(): void {
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_ops_lead',
			'Ops Lead',
			\AIMS_Capabilities::ROLE_WAREHOUSE_USER,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY => true,
			)
		);

		TestState::set_user(
			106,
			(object) array(
				'ID'    => 106,
				'roles' => array( 'aims_custom_ops_lead' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();

		$this->assertTrue( $service->is_aims_person( 106 ) );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE, $service->get_person_subtypes( 106 ) );
	}
}
