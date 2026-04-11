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

	public function testRenderDashboardShowsHotDataPressureGauge(): void {
		TestState::set_current_user_id( 88 );
		TestState::set_user_capabilities( 88, array( \AIMS_Capabilities::CAP_MANAGE ) );
		TestState::update_option( \AIMS_Plugin::OPTION_API_URL, 'https://aims-core.test' );
		TestState::update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token' );
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'manifest_uuid' => 'manifest-1',
						'generated_at'  => '2026-04-02T12:00:00Z',
						'summary'       => array(
							'merged_items' => 5,
						),
						'buckets'       => array(),
					)
				),
			)
		);

		$health = new class() extends \AIMS_Hot_Db_Health_Service {
			public function __construct() {}

			public function get_dashboard_snapshot(): array {
				return array(
					'band'                       => 'yellow',
					'band_label'                 => 'Yellow',
					'band_color'                 => '#f9a825',
					'total_hot_rows'             => 120000,
					'usage_percent'              => 48,
					'capacity_target'            => 250000,
					'thresholds'                 => array(
						'green'  => 100000,
						'yellow' => 250000,
						'target' => 250000,
					),
					'estimated_order_equivalent' => 25000,
					'counts'                     => array(
						'square_sales'            => 100000,
						'bucket_inventory_moves'  => 10000,
						'fulfillment_allocations' => 5000,
						'inventory_movements'     => 5000,
					),
					'message'                    => 'This stack is entering its caution band. AIMS is still doing its job, but line growth is starting to matter.',
				);
			}
		};

		$menu = new \AIMS_Admin_Menu( null, new \AIMS_Audit_Log_Service( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-dashboard-audit-' . uniqid( '', true ) ), $health );

		ob_start();
		$menu->render_dashboard();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Hot Data Pressure', $output );
		$this->assertStringContainsString( 'Yellow Band', $output );
		$this->assertStringContainsString( '48% of hot-row target', $output );
		$this->assertStringContainsString( 'Square sale lines', $output );
		$this->assertStringContainsString( 'Red at 250,000 and above', $output );
	}

	public function testRenderDashboardShowsRecentColdStorageSummary(): void {
		TestState::set_current_user_id( 89 );
		TestState::set_user_capabilities( 89, array( \AIMS_Capabilities::CAP_MANAGE ) );
		TestState::update_option( \AIMS_Plugin::OPTION_API_URL, 'https://aims-core.test' );
		TestState::update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token' );
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'manifest_uuid' => 'manifest-archive',
						'generated_at'  => '2026-04-11T12:00:00Z',
						'summary'       => array(
							'merged_items' => 5,
						),
						'buckets'       => array(),
						'archive_manifests' => array(
							array(
								'show_id'       => 'SHOW-42',
								'row_count'     => 12,
								'segment_count' => 2,
								'active_from'   => '2026-04-01T09:15:00Z',
								'active_to'     => '2026-04-10T16:45:00Z',
							),
						),
					)
				),
			)
		);

		$menu = new \AIMS_Admin_Menu( null, new \AIMS_Audit_Log_Service( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-dashboard-cold-' . uniqid( '', true ) ) );

		ob_start();
		$menu->render_dashboard();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Recent Cold Archive Windows', $output );
		$this->assertStringContainsString( 'SHOW-42', $output );
		$this->assertStringContainsString( '2026-04-01T09:15:00Z to 2026-04-10T16:45:00Z', $output );
		$this->assertStringContainsString( '2 segment(s)', $output );
	}

	public function testHandleSyncRemoteManifestBlocksDuringLiveEventWindow(): void {
		TestState::set_current_user_id( 91 );
		TestState::set_user_capabilities( 91, array( \AIMS_Capabilities::CAP_MANAGE ) );
		TestState::set_throw_on_redirect( true );
		TestState::update_option( \AIMS_Plugin::OPTION_API_URL, 'https://aims-core.test' );
		TestState::update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token' );

		$policy = new class() extends \AIMS_Square_Location_Push_Policy_Service {
			public function __construct() {}

			public function get_manifest_sync_gate(): array {
				return array(
					'allowed'       => false,
					'active_events' => array(
						array(
							'event_name' => 'PAX East',
							'start_date' => '2026-04-02',
							'end_date'   => '2026-04-04',
						),
					),
					'message'       => 'Square location pushes are locked while a live event window is active.',
				);
			}
		};

		$menu = new \AIMS_Admin_Menu( null, new \AIMS_Audit_Log_Service( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-manifest-lock-' . uniqid( '', true ) ), null, $policy );

		try {
			$menu->handle_sync_remote_manifest();
			$this->fail( 'Expected redirect exception.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'manifest_sync_locked', $exception->getMessage() );
		}

		$this->assertCount( 0, TestState::get_remote_requests() );
	}

	public function testRenderDashboardExplainsManifestPushIsLockedDuringLiveShowWindow(): void {
		TestState::set_current_user_id( 92 );
		TestState::set_user_capabilities( 92, array( \AIMS_Capabilities::CAP_MANAGE ) );
		TestState::update_option( \AIMS_Plugin::OPTION_API_URL, 'https://aims-core.test' );
		TestState::update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token' );
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'manifest_uuid' => 'manifest-2',
						'generated_at'  => '2026-04-02T12:00:00Z',
						'summary'       => array(
							'merged_items' => 3,
						),
						'buckets'       => array(),
					)
				),
			)
		);

		$policy = new class() extends \AIMS_Square_Location_Push_Policy_Service {
			public function __construct() {}

			public function get_manifest_sync_gate(): array {
				return array(
					'allowed'       => false,
					'active_events' => array(
						array(
							'event_name' => 'PAX East',
							'start_date' => '2026-04-02',
							'end_date'   => '2026-04-04',
						),
					),
					'message'       => 'Square location pushes are locked while a live event window is active.',
				);
			}
		};

		$menu = new \AIMS_Admin_Menu( null, new \AIMS_Audit_Log_Service( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-dashboard-manifest-' . uniqid( '', true ) ), null, $policy );

		ob_start();
		$menu->render_dashboard();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Square inventory pushes stay manual on purpose', $output );
		$this->assertStringContainsString( 'PAX East (2026-04-02 to 2026-04-04)', $output );
		$this->assertStringContainsString( 'disabled="disabled"', $output );
	}
}
