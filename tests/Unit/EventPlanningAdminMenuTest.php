<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class EventPlanningAdminMenuTest extends \AIMS\Tests\TestCase {
	public function testRegisterAddsThePlanningWorkspaceUnderTheEventsMenu(): void {
		TestState::set_current_user_id( 1 );
		TestState::set_user_capabilities( 1, array( \AIMS_Capabilities::CAP_VIEW_EVENTS_SHELL ) );

		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$submenu_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims-events' === (string) ( $call['args'][0] ?? '' );
				}
			)
		);

		$this->assertNotEmpty( $submenu_calls );

		$planning_call = null;
		$workspace_call = null;

		foreach ( $submenu_calls as $call ) {
			if ( 'Event Planning' === (string) ( $call['args'][1] ?? '' ) ) {
				$planning_call = $call;
			}

			if ( 'Event Planning Workspace' === (string) ( $call['args'][1] ?? '' ) ) {
				$workspace_call = $call;
			}
		}

		$this->assertNotNull( $planning_call );
		$this->assertSame( 'aims-event-planning', $planning_call['args'][4] );
		$this->assertSame( array( $menu, 'render_event_planning' ), $planning_call['args'][5] );

		$this->assertNotNull( $workspace_call );
		$this->assertSame( \AIMS_Event_Planning_Workspace_Page::PAGE_SLUG, $workspace_call['args'][4] );
		$this->assertSame( array( $menu, 'render_event_planning_workspace' ), $workspace_call['args'][5] );

		$remove_calls = TestState::get_hook_calls( 'remove_submenu_page' );
		$this->assertNotEmpty( $remove_calls );
		$this->assertSame( 'aims-events', $remove_calls[0]['args'][0] );
		$this->assertSame( \AIMS_Event_Planning_Workspace_Page::PAGE_SLUG, $remove_calls[0]['args'][1] );
	}

	public function testVendorsSubmenuAppearsWhenUserHasVendorManagementResponsibility(): void {
		TestState::set_current_user_id( 5 );
		TestState::update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		$repo = new class() extends \AIMS_Responsibility_Assignment_Repository {
			public function has_active_assignments_for_user( int $user_id ): bool { return true; }
			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				return 5 === $user_id && \AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT === $responsibility_key;
			}
			public function get_scope_ref_ids_for_user( int $user_id, string $responsibility_key, string $scope_type ): array { return array(); }
		};

		$auth = new \AIMS_Responsibility_Authorization_Service( $repo );
		$menu = new \AIMS_Admin_Menu( null, null, null, $auth );
		$menu->register();

		$vendor_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims-vendors' === (string) ( $call['args'][4] ?? '' );
				}
			)
		);

		$this->assertNotEmpty( $vendor_calls, 'Vendors submenu should register for users with vendor_management responsibility' );
	}

	public function testVendorsSubmenuHiddenWhenUserLacksVendorResponsibilityAndCapability(): void {
		TestState::set_current_user_id( 7 );
		TestState::update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		$repo = new class() extends \AIMS_Responsibility_Assignment_Repository {
			public function has_active_assignments_for_user( int $user_id ): bool { return false; }
			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool { return false; }
			public function get_scope_ref_ids_for_user( int $user_id, string $responsibility_key, string $scope_type ): array { return array(); }
		};

		$auth = new \AIMS_Responsibility_Authorization_Service( $repo );
		$menu = new \AIMS_Admin_Menu( null, null, null, $auth );
		$menu->register();

		$vendor_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims-vendors' === (string) ( $call['args'][4] ?? '' );
				}
			)
		);

		$this->assertEmpty( $vendor_calls, 'Vendors submenu should not register when user has neither responsibility nor capability' );
	}

	public function testReportsSubmenuAppearsWhenUserHasReportsViewResponsibility(): void {
		TestState::set_current_user_id( 9 );
		TestState::update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		$repo = new class() extends \AIMS_Responsibility_Assignment_Repository {
			public function has_active_assignments_for_user( int $user_id ): bool { return true; }
			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				return 9 === $user_id && \AIMS_Responsibility_Authorization_Service::RESP_REPORTS_VIEW === $responsibility_key;
			}
			public function get_scope_ref_ids_for_user( int $user_id, string $responsibility_key, string $scope_type ): array { return array(); }
		};

		$auth = new \AIMS_Responsibility_Authorization_Service( $repo );
		$menu = new \AIMS_Admin_Menu( null, null, null, $auth );
		$menu->register();

		$reports_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims-reports' === (string) ( $call['args'][4] ?? '' );
				}
			)
		);

		$this->assertNotEmpty( $reports_calls, 'Reports submenu should register for users with reports_view responsibility' );
		$this->assertSame( 'read', $reports_calls[0]['args'][3], 'Responsibility-gated items should use read capability' );
	}

	public function testLegacyCapabilityFallbackRegistersVendorsSubmenu(): void {
		TestState::set_current_user_id( 11 );
		TestState::set_user_capabilities( 11, array( \AIMS_Capabilities::CAP_MANAGE_VENDORS ) );

		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$vendor_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims-vendors' === (string) ( $call['args'][4] ?? '' );
				}
			)
		);

		$this->assertNotEmpty( $vendor_calls, 'Vendors submenu should register for users with CAP_MANAGE_VENDORS (legacy fallback)' );
	}
}
