<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class CapabilitiesTemplateModelTest extends \AIMS\Tests\TestCase {
	public function testRegisterRolesAndCapsDoesNotRegisterBuiltInTemplateRoles(): void {
		$capabilities = new \AIMS_Capabilities();
		$capabilities->register();

		$this->assertNull( get_role( \AIMS_Capabilities::ROLE_VENDOR_USER ) );
		$this->assertNull( get_role( \AIMS_Capabilities::ROLE_STITCH_USER ) );
		$this->assertNull( get_role( \AIMS_Capabilities::ROLE_WAREHOUSE_USER ) );
		$this->assertNull( get_role( \AIMS_Capabilities::ROLE_SUPERVISOR_USER ) );
		$this->assertNull( get_role( \AIMS_Capabilities::ROLE_MANAGER_USER ) );
		$this->assertArrayHasKey( \AIMS_Capabilities::ROLE_VENDOR_USER, \AIMS_Capabilities::get_role_templates() );
	}

	public function testRegisterRolesAndCapsSyncsCustomRuntimeRolesOnly(): void {
		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_vendor_ops',
			'Vendor Ops',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
				\AIMS_Capabilities::CAP_RESP_VENDOR_VIEW_COMMISSION => true,
			)
		);

		\AIMS_Capabilities::register_roles_and_caps();

		$runtime_role = get_role( 'aims_custom_vendor_ops' );

		$this->assertInstanceOf( \WP_Role::class, $runtime_role );
		$this->assertArrayHasKey( \AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL, $runtime_role->capabilities );
		$this->assertArrayHasKey( \AIMS_Capabilities::CAP_RESP_VENDOR_VIEW_COMMISSION, $runtime_role->capabilities );
	}

	public function testRegisterRolesAndCapsMigratesAssignedTemplateUsersToRuntimeRoles(): void {
		\AIMS\Tests\Support\TestState::add_role(
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			'AIMS Vendor User',
			(array) ( \AIMS_Capabilities::get_role_templates()[ \AIMS_Capabilities::ROLE_VENDOR_USER ]['caps'] ?? array() )
		);

		\AIMS\Tests\Support\TestState::set_user(
			201,
			(object) array(
				'ID'    => 201,
				'roles' => array( \AIMS_Capabilities::ROLE_VENDOR_USER ),
			)
		);

		\AIMS_Capabilities::register_roles_and_caps();

		$user = get_user_by( 'id', 201 );

		$this->assertIsObject( $user );
		$this->assertContains( 'aims_migrated_vendor_user', (array) ( $user->roles ?? array() ) );
		$this->assertNotContains( \AIMS_Capabilities::ROLE_VENDOR_USER, (array) ( $user->roles ?? array() ) );
		$this->assertArrayHasKey( 'aims_migrated_vendor_user', \AIMS_Capabilities::get_custom_role_registry() );
		$this->assertInstanceOf( \WP_Role::class, get_role( 'aims_migrated_vendor_user' ) );
		$this->assertTrue( ( new \AIMS_Person_Identity_Service() )->is_aims_person( 201 ) );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_VENDOR, ( new \AIMS_Person_Identity_Service() )->get_person_subtypes( 201 ) );
	}
}
