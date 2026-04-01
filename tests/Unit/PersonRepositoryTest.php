<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class PersonRepositoryTest extends \AIMS\Tests\TestCase {
	public function testAllReturnsPeopleResolvedFromTemplateMetadataNotShippedRoleSlugs(): void {
		$role = \AIMS_Capabilities::create_or_update_custom_role(
			'warehouse_ops',
			'Custom Warehouse Ops',
			\AIMS_Capabilities::ROLE_WAREHOUSE_USER,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY => true,
			)
		);
		$role_slug = (string) ( $role['role']['role_slug'] ?? '' );

		TestState::set_user(
			201,
			(object) array(
				'ID'           => 201,
				'display_name' => 'Warehouse Person',
				'user_email'   => 'warehouse@example.test',
				'roles'        => array( $role_slug ),
			)
		);
		TestState::set_user(
			202,
			(object) array(
				'ID'           => 202,
				'display_name' => 'Generic Customer',
				'user_email'   => 'customer@example.test',
				'roles'        => array( 'customer' ),
			)
		);

		$repository = new \AIMS_Person_Repository();
		$people     = $repository->all();

		$this->assertCount( 1, $people );
		$this->assertSame( 201, $people[0]['user_id'] );
		$this->assertContains( \AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE, $people[0]['subtypes'] );
	}

	public function testFindBySubtypeUsesResolvedSubtypeMetadata(): void {
		$warehouse_role = \AIMS_Capabilities::create_or_update_custom_role(
			'warehouse_ops_two',
			'Custom Warehouse Ops Two',
			\AIMS_Capabilities::ROLE_WAREHOUSE_USER,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY => true,
			)
		);
		$warehouse_role_slug = (string) ( $warehouse_role['role']['role_slug'] ?? '' );
		$vendor_role = \AIMS_Capabilities::create_or_update_custom_role(
			'vendor_ops',
			'Custom Vendor Ops',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
			)
		);
		$vendor_role_slug = (string) ( $vendor_role['role']['role_slug'] ?? '' );

		TestState::set_user(
			203,
			(object) array(
				'ID'           => 203,
				'display_name' => 'Vendor Person',
				'user_email'   => 'vendor@example.test',
				'roles'        => array( $vendor_role_slug ),
			)
		);
		TestState::set_user(
			204,
			(object) array(
				'ID'           => 204,
				'display_name' => 'Warehouse Person',
				'user_email'   => 'warehouse2@example.test',
				'roles'        => array( $warehouse_role_slug ),
			)
		);

		$repository = new \AIMS_Person_Repository();
		$people     = $repository->find_by_subtype( \AIMS_Person_Identity_Service::SUBTYPE_VENDOR );

		$this->assertCount( 1, $people );
		$this->assertSame( 203, $people[0]['user_id'] );
	}
}
