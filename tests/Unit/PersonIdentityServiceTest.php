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
}
