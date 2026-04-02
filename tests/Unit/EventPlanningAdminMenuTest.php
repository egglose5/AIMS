<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class EventPlanningAdminMenuTest extends \AIMS\Tests\TestCase {
	public function testRegisterAddsDashboardAndSettingsUnderTheAimsMenu(): void {
		TestState::set_current_user_id( 1 );
		TestState::set_user_capabilities( 1, array( \AIMS_Capabilities::CAP_MANAGE ) );

		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$menu_calls = TestState::get_hook_calls( 'add_menu_page' );
		$this->assertNotEmpty( $menu_calls );
		$this->assertSame( 'AIMS', $menu_calls[0]['args'][0] );
		$this->assertSame( \AIMS_Capabilities::CAP_MANAGE, $menu_calls[0]['args'][2] );
		$this->assertSame( \AIMS_Admin_Menu::MENU_SLUG, $menu_calls[0]['args'][3] );

		$submenu_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return \AIMS_Admin_Menu::MENU_SLUG === (string) ( $call['args'][0] ?? '' );
				}
			)
		);

		$this->assertCount( 3, $submenu_calls );
		$this->assertSame( 'Dashboard', $submenu_calls[0]['args'][1] );
		$this->assertSame( \AIMS_Admin_Menu::MENU_SLUG, $submenu_calls[0]['args'][4] );
		$this->assertSame( 'Settings', $submenu_calls[1]['args'][1] );
		$this->assertSame( \AIMS_Admin_Menu::SETTINGS_PAGE_SLUG, $submenu_calls[1]['args'][4] );
		$this->assertSame( 'Activity Log', $submenu_calls[2]['args'][1] );
		$this->assertSame( \AIMS_Admin_Menu::ACTIVITY_PAGE_SLUG, $submenu_calls[2]['args'][4] );
	}

	public function testRegisterAddsThinClientAdminHooks(): void {
		TestState::set_current_user_id( 5 );
		TestState::set_user_capabilities( 5, array( 'manage_options' ) );

		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_init' ) );
		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_post_aims_submit_remote_move' ) );
		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_post_aims_register_remote_bucket' ) );
		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_post_aims_receive_remote_fifo' ) );
		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_post_aims_move_remote_custody' ) );
		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_post_aims_pick_remote_fifo' ) );
		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_post_aims_sync_remote_manifest' ) );
		$this->assertNotEmpty( TestState::get_hook_calls( 'admin_post_aims_trigger_remote_archive' ) );
	}

	public function testRegisterFallsBackToManageOptionsWhenAimsManageCapabilityIsMissing(): void {
		TestState::set_current_user_id( 9 );
		TestState::set_user_capabilities( 9, array( 'manage_options' ) );

		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$menu_calls = TestState::get_hook_calls( 'add_menu_page' );
		$this->assertNotEmpty( $menu_calls );
		$this->assertSame( 'manage_options', $menu_calls[0]['args'][2] );
	}

	public function testRegisterDoesNotExposeLegacyEventsReportsOrVendorSubmenus(): void {
		TestState::set_current_user_id( 12 );
		TestState::set_user_capabilities(
			12,
			array(
				\AIMS_Capabilities::CAP_MANAGE,
				\AIMS_Capabilities::CAP_RESP_REPORTS_VIEW,
				\AIMS_Capabilities::CAP_RESP_VENDOR_MANAGEMENT,
			)
		);

		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$submenu_calls = TestState::get_hook_calls( 'add_submenu_page' );
		foreach ( $submenu_calls as $call ) {
			$this->assertNotContains( (string) ( $call['args'][4] ?? '' ), array( 'aims-vendors', 'aims-reports', 'aims-event-planning', \AIMS_Role_Editor_Page::PAGE_SLUG ) );
		}
	}

	public function testHandleSubmitRemoteMoveWritesPluginSideAuditProof(): void {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-admin-audit-' . uniqid( '', true );

		TestState::set_current_user_id( 40 );
		TestState::set_user_capabilities( 40, array( \AIMS_Capabilities::CAP_MANAGE ) );
		TestState::set_throw_on_redirect( true );
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'ok' => true,
					)
				),
			)
		);
		TestState::update_option( \AIMS_Plugin::OPTION_API_URL, 'https://aims-core.test' );
		TestState::update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token' );

		$_POST = array(
			'sku'           => 'SKU-9',
			'from_location' => 'truck',
			'to_location'   => 'pax-east',
			'quantity'      => 1,
		);

		$service = new \AIMS_Audit_Log_Service( $directory );
		$menu    = new \AIMS_Admin_Menu( null, $service );

		try {
			$menu->handle_submit_remote_move();
			$this->fail( 'Expected redirect exception.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringStartsWith( 'redirect:', $exception->getMessage() );
		}

		$rows = $service->get_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 40, $rows[0]['user_id'] );
		$this->assertSame( \AIMS_Capabilities::CAP_MANAGE, $rows[0]['capability_key'] );
		$this->assertSame( 'movement_send', $rows[0]['action_key'] );
		$this->assertSame( 'SKU-9', $rows[0]['reference_id'] );
		$this->assertSame( 'success', $rows[0]['status'] );
	}

	public function testRenderActivityLogShowsStructuredRows(): void {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-admin-log-view-' . uniqid( '', true );

		TestState::set_current_user_id( 77 );
		TestState::set_user(
			77,
			(object) array(
				'ID'           => 77,
				'display_name' => 'Mom Ops',
				'user_login'   => 'mom',
			)
		);

		$service = new \AIMS_Audit_Log_Service( $directory );
		$service->record_action( \AIMS_Capabilities::CAP_MANAGE, 'bucket_register', 'BIN-77' );

		$menu = new \AIMS_Admin_Menu( null, $service );

		ob_start();
		$menu->render_activity_log();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'AIMS Activity Log', $output );
		$this->assertStringContainsString( 'Mom Ops', $output );
		$this->assertStringContainsString( 'bucket_register', $output );
		$this->assertStringContainsString( 'BIN-77', $output );
	}
}
