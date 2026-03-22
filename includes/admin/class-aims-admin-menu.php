<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Menu {
	const MENU_SLUG = 'aims';

	public function register(): void {
		add_menu_page(
			'AIMS',
			'AIMS',
			AIMS_Capabilities::CAP_MANAGE,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-database-view',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Vendors',
			'Vendors',
			AIMS_Capabilities::CAP_MANAGE_VENDORS,
			'aims-vendors',
			array( $this, 'render_vendors_shell' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Square Sync',
			'Square Sync',
			AIMS_Capabilities::CAP_RUN_SYNC,
			'aims-square-sync',
			array( $this, 'render_square_sync_shell' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Needs Shipping',
			'Needs Shipping',
			AIMS_Capabilities::CAP_RUN_SYNC,
			'aims-needs-shipping',
			array( $this, 'render_needs_shipping_queue' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Reports',
			'Reports',
			AIMS_Capabilities::CAP_VIEW_REPORTS,
			'aims-reports',
			array( $this, 'render_reports_shell' )
		);
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>AIMS</h1><p>Phase 1 foundation is installed. Core modules will attach to this menu as the native AIMS rebuild progresses.</p></div>';
	}

	public function render_vendors_shell(): void {
		echo '<div class="wrap"><h1>Vendor Manage</h1><p>The vendor module foundation is active. Vendor access control, bucket assignment, and vendor operations UI will be implemented here next.</p></div>';
	}

	public function render_square_sync_shell(): void {
		echo '<div class="wrap"><h1>Square Sync</h1><p>Native AIMS Square ingestion will be implemented here with queueing, dedupe, logging, and undo-safe stock controls before any live stock mutations are enabled.</p></div>';
	}

	public function render_needs_shipping_queue(): void {
		$page = new AIMS_Shipping_Queue_Page( new AIMS_Shipping_Queue_Data_Provider() );
		$page->render();
	}

	public function render_reports_shell(): void {
		echo '<div class="wrap"><h1>Reports &amp; Analytics</h1><p>AIMS reporting repositories will be built directly on top of native AIMS operational and sync tables in a later phase.</p></div>';
	}
}
