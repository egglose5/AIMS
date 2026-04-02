<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class SurfaceAuthorizationServiceTest extends \AIMS\Tests\TestCase {
	public function testBaselineCapabilityFallsBackToUserCapabilityWhenNoOverridesExist(): void {
		TestState::set_user(
			14,
			(object) array(
				'ID'    => 14,
				'roles' => array( 'administrator' ),
			)
		);
		TestState::set_user_capabilities( 14, array( \AIMS_Capabilities::CAP_MANAGE_INVENTORY ) );

		$repo = new class() extends \AIMS_User_Surface_Capability_Repository {
			public function get_active_rules_for_user( int $user_id ): array {
				return array();
			}
		};

		$service = new \AIMS_Surface_Authorization_Service( $repo );

		$this->assertTrue( $service->user_can_for_surface( 14, \AIMS_Capabilities::CAP_MANAGE_INVENTORY, \AIMS_Capabilities::SURFACE_WP_ADMIN ) );
		$this->assertTrue( $service->user_can_for_surface( 14, \AIMS_Capabilities::CAP_MANAGE_INVENTORY, \AIMS_Capabilities::SURFACE_MOBILE_APP ) );
	}

	public function testExplicitMobileDenyBlocksCapabilityWhileWpDashboardStillAllowsIt(): void {
		TestState::set_user(
			21,
			(object) array(
				'ID'    => 21,
				'roles' => array( 'aims_custom_ops' ),
			)
		);
		TestState::set_user_capabilities( 21, array( \AIMS_Capabilities::CAP_MANAGE_INVENTORY ) );

		$repo = new class() extends \AIMS_User_Surface_Capability_Repository {
			public function get_active_rules_for_user( int $user_id ): array {
				if ( 21 !== $user_id ) {
					return array();
				}

				return array(
					array(
						'capability_key' => \AIMS_Capabilities::CAP_MANAGE_INVENTORY,
						'surface'        => \AIMS_Capabilities::SURFACE_MOBILE_APP,
						'scope_type'     => \AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL,
						'scope_ref_id'   => 0,
						'access_mode'    => \AIMS_User_Surface_Capability_Repository::MODE_DENY,
					),
				);
			}
		};

		$service = new \AIMS_Surface_Authorization_Service( $repo );

		$this->assertTrue( $service->user_can_for_surface( 21, \AIMS_Capabilities::CAP_MANAGE_INVENTORY, \AIMS_Capabilities::SURFACE_WP_ADMIN ) );
		$this->assertFalse( $service->user_can_for_surface( 21, \AIMS_Capabilities::CAP_MANAGE_INVENTORY, \AIMS_Capabilities::SURFACE_MOBILE_APP ) );
	}

	public function testExplicitWpAllowCanGrantDashboardAccessWithoutGrantingMobileAccess(): void {
		TestState::set_user(
			31,
			(object) array(
				'ID'    => 31,
				'roles' => array( 'subscriber' ),
			)
		);

		$repo = new class() extends \AIMS_User_Surface_Capability_Repository {
			public function get_active_rules_for_user( int $user_id ): array {
				if ( 31 !== $user_id ) {
					return array();
				}

				return array(
					array(
						'capability_key' => \AIMS_Capabilities::CAP_VIEW_REPORTS,
						'surface'        => \AIMS_Capabilities::SURFACE_WP_ADMIN,
						'scope_type'     => \AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL,
						'scope_ref_id'   => 0,
						'access_mode'    => \AIMS_User_Surface_Capability_Repository::MODE_ALLOW,
					),
				);
			}
		};

		$service = new \AIMS_Surface_Authorization_Service( $repo );

		$this->assertTrue( $service->user_can_for_surface( 31, \AIMS_Capabilities::CAP_VIEW_REPORTS, \AIMS_Capabilities::SURFACE_WP_ADMIN ) );
		$this->assertFalse( $service->user_can_for_surface( 31, \AIMS_Capabilities::CAP_VIEW_REPORTS, \AIMS_Capabilities::SURFACE_MOBILE_APP ) );
	}
}
