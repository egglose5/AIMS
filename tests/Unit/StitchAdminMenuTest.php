<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchAdminMenuTest extends \AIMS\Tests\TestCase {
	public function testRegisterDoesNotExposeLegacyStitchMenusInThinClientMode(): void {
		TestState::set_current_user_id( 12 );
		TestState::set_user_capabilities(
			12,
			array(
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

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

		$this->assertNotEmpty( $submenu_calls );
		$this->assertSame( 'Dashboard', $submenu_calls[0]['args'][1] );
		$this->assertSame( 'Settings', $submenu_calls[1]['args'][1] );

		foreach ( $submenu_calls as $call ) {
			$this->assertNotSame( 'Stitch Jobs', (string) ( $call['args'][1] ?? '' ) );
			$this->assertNotSame( 'Stitch Job Workspace', (string) ( $call['args'][1] ?? '' ) );
		}
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
