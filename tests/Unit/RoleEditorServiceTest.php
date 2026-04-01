<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class RoleEditorServiceTest extends \AIMS\Tests\TestCase {
	public function testSaveRoleCreatesCustomRoleFromTemplate(): void {
		$service = new \AIMS_Role_Editor_Service();

		$result = $service->save_role(
			array(
				'role_name'    => 'Vendor Plus',
				'role_slug'    => 'aims_custom_vendor_plus',
				'template_key' => \AIMS_Capabilities::ROLE_VENDOR_USER,
				'role_caps'    => array(
					\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL,
					\AIMS_Capabilities::CAP_RESP_VENDOR_VIEW_COMMISSION,
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'aims_custom_vendor_plus', $result['role']['role_slug'] );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_VENDOR, $result['role']['person_subtypes'] );

		$stored = \AIMS_Capabilities::get_custom_role_registry();
		$this->assertArrayHasKey( 'aims_custom_vendor_plus', $stored );
		$this->assertArrayHasKey( \AIMS_Capabilities::CAP_RESP_VENDOR_VIEW_COMMISSION, $stored['aims_custom_vendor_plus']['caps'] );
	}

	public function testDeleteRoleRemovesCustomRole(): void {
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_temp_role',
			'Temp Role',
			\AIMS_Capabilities::ROLE_MANAGER_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_REPORTS => true,
			)
		);

		$service = new \AIMS_Role_Editor_Service();

		$this->assertTrue( $service->delete_role( 'aims_custom_temp_role' ) );
		$this->assertArrayNotHasKey( 'aims_custom_temp_role', \AIMS_Capabilities::get_custom_role_registry() );
	}
}
