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

	public function testBuiltInRoleTemplatesRemainTemplateOnly(): void {
		$result = \AIMS_Capabilities::create_or_update_custom_role(
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			'Changed Vendor',
			\AIMS_Capabilities::ROLE_MANAGER_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_REPORTS => true,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Built-in role templates cannot be overwritten.', $result['message'] );

		$service = new \AIMS_Role_Editor_Service();
		$this->assertFalse( $service->delete_role( \AIMS_Capabilities::ROLE_VENDOR_USER ) );
		$this->assertArrayNotHasKey( \AIMS_Capabilities::ROLE_VENDOR_USER, \AIMS_Capabilities::get_custom_role_registry() );
	}

	public function testCustomRoleFromTemplateAppearsAsSeparateEditableDefinition(): void {
		$service = new \AIMS_Role_Editor_Service();
		$result  = $service->save_role(
			array(
				'role_name'    => 'Ops Lead',
				'role_slug'    => 'aims_custom_ops_lead',
				'template_key' => \AIMS_Capabilities::ROLE_WAREHOUSE_USER,
				'role_caps'    => array(
					\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
					\AIMS_Capabilities::CAP_VIEW_REPORTS,
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$model = $service->get_page_model( 'aims_custom_ops_lead' );

		$this->assertArrayHasKey( \AIMS_Capabilities::ROLE_WAREHOUSE_USER, $model['templates'] );
		$this->assertArrayHasKey( 'aims_custom_ops_lead', $model['custom_roles'] );
		$this->assertArrayHasKey( \AIMS_Capabilities::SURFACE_WP_ADMIN, $model['supported_surfaces'] );
		$this->assertArrayHasKey( \AIMS_Capabilities::SURFACE_MOBILE_APP, $model['supported_surfaces'] );
		$this->assertSame( 'aims_custom_ops_lead', $model['editing_role']['role_slug'] );
		$this->assertSame( \AIMS_Capabilities::ROLE_WAREHOUSE_USER, $model['editing_role']['template_key'] );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE, $model['editing_role']['person_subtypes'] );

		$definitions = \AIMS_Capabilities::get_role_definitions();
		$this->assertTrue( (bool) $definitions[ \AIMS_Capabilities::ROLE_WAREHOUSE_USER ]['is_builtin_template'] );
		$this->assertSame( 'AIMS Main Warehouse Operator', $definitions[ \AIMS_Capabilities::ROLE_WAREHOUSE_USER ]['role_name'] );
		$this->assertSame( \AIMS_Capabilities::ROLE_WAREHOUSE_USER, $definitions['aims_custom_ops_lead']['template_key'] );
		$this->assertContains( 'aims_custom_ops_lead', \AIMS_Capabilities::get_role_slugs_for_person_subtype( \AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE ) );
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

	public function testSupportedSurfacesExposeWpAdminAndMobileApp(): void {
		$service = new \AIMS_Role_Editor_Service();
		$model   = $service->get_page_model();

		$this->assertSame( 'WordPress Dashboard', $model['supported_surfaces'][ \AIMS_Capabilities::SURFACE_WP_ADMIN ] );
		$this->assertSame( 'Mobile App', $model['supported_surfaces'][ \AIMS_Capabilities::SURFACE_MOBILE_APP ] );
	}
}
