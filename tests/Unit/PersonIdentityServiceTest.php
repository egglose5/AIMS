<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class PersonIdentityServiceTest extends \AIMS\Tests\TestCase {
	public function testIsAimsPersonTrueForAimsRoleUser(): void {
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_vendor_runtime',
			'Vendor Runtime',
			\AIMS_Capabilities::ROLE_VENDOR_USER
		);

		TestState::set_user(
			101,
			(object) array(
				'ID'    => 101,
				'roles' => array( 'aims_custom_vendor_runtime' ),
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
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_vendor_manager',
			'Vendor Manager',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
			),
			array( \AIMS_Person_Identity_Service::SUBTYPE_MANAGER )
		);

		TestState::set_user(
			103,
			(object) array(
				'ID'    => 103,
				'roles' => array( 'aims_custom_vendor_manager' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();
		$subtypes = $service->get_person_subtypes( 103 );

		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_VENDOR, $subtypes );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_MANAGER, $subtypes );
	}

	public function testSubtypesResolveStitcher(): void {
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_stitch_runtime',
			'Stitch Runtime',
			\AIMS_Capabilities::ROLE_STITCH_USER
		);

		TestState::set_user(
			104,
			(object) array(
				'ID'    => 104,
				'roles' => array( 'aims_custom_stitch_runtime' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();
		$subtypes = $service->get_person_subtypes( 104 );

		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_STITCH, $subtypes );
	}

	public function testSubtypesResolveWarehouseOperator(): void {
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_warehouse_runtime',
			'Warehouse Runtime',
			\AIMS_Capabilities::ROLE_WAREHOUSE_USER
		);

		TestState::set_user(
			105,
			(object) array(
				'ID'    => 105,
				'roles' => array( 'aims_custom_warehouse_runtime' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();
		$subtypes = $service->get_person_subtypes( 105 );

		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE, $subtypes );
	}

	public function testWarehouseRoleTemplateMapsToWarehouseSubtype(): void {
		$template = \AIMS_Capabilities::get_role_definition( \AIMS_Capabilities::ROLE_WAREHOUSE_USER );

		$this->assertIsArray( $template );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE, (array) ( $template['person_subtypes'] ?? array() ) );
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

	public function testCapabilityOnlyExternalRoleResolvesVendorSubtypeWithoutRegistryEntry(): void {
		\AIMS\Tests\Support\TestState::add_role(
			'site_vendor_partner',
			'Site Vendor Partner',
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
				\AIMS_Capabilities::CAP_RESP_VENDOR_SUBMIT_CHECKIN => true,
			)
		);

		TestState::set_user(
			107,
			(object) array(
				'ID'    => 107,
				'roles' => array( 'site_vendor_partner' ),
			)
		);

		$service = new \AIMS_Person_Identity_Service();

		$this->assertTrue( $service->is_aims_person( 107 ) );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_VENDOR, $service->get_person_subtypes( 107 ) );
	}
}
