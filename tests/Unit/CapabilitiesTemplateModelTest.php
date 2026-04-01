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
}
