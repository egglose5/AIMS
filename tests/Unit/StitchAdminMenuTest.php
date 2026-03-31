<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchAdminMenuTest extends \AIMS\Tests\TestCase {
	public function testRegisterAddsStitchJobsMenuForProducerRole(): void {
		TestState::set_current_user_id( 12 );
		TestState::set_user_capabilities(
			12,
			array(
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

		$auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_manage_event_planning( int $user_id = 0 ): bool {
				return false;
			}

			public function can_manage_vendors( int $user_id = 0 ): bool {
				return false;
			}

			public function can_manage_square_sync( int $user_id = 0 ): bool {
				return false;
			}

			public function can_view_reports( int $user_id = 0 ): bool {
				return false;
			}
		};

		$menu = new \AIMS_Admin_Menu( null, null, null, null, $auth );
		$menu->register();

		$submenu_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims' === (string) ( $call['args'][0] ?? '' );
				}
			)
		);

		$this->assertNotEmpty( $submenu_calls );

		$jobs_call = null;
		$workspace_call = null;

		foreach ( $submenu_calls as $call ) {
			if ( 'Stitch Jobs' === (string) ( $call['args'][1] ?? '' ) ) {
				$jobs_call = $call;
			}

			if ( 'Stitch Job Workspace' === (string) ( $call['args'][1] ?? '' ) ) {
				$workspace_call = $call;
			}
		}

		$this->assertNotNull( $jobs_call );
		$this->assertSame( 'aims-stitch', $jobs_call['args'][4] );
		$this->assertSame( array( $menu, 'render_stitch_jobs' ), $jobs_call['args'][5] );

		$this->assertNotNull( $workspace_call );
		$this->assertSame( \AIMS_Stitch_Workspace_Page::PAGE_SLUG, $workspace_call['args'][4] );
		$this->assertSame( array( $menu, 'render_stitch_workspace' ), $workspace_call['args'][5] );
	}

	public function testRegisterSkipsStitchJobsMenuWhenUserLacksProducerAccess(): void {
		TestState::set_current_user_id( 13 );
		TestState::set_user_capabilities( 13, array( \AIMS_Capabilities::CAP_VIEW_DASHBOARD ) );

		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$submenu_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims' === (string) ( $call['args'][0] ?? '' );
				}
			)
		);

		foreach ( $submenu_calls as $call ) {
			$this->assertNotSame( 'Stitch Jobs', (string) ( $call['args'][1] ?? '' ) );
		}
	}
}
