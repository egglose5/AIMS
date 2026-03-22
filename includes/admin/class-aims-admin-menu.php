<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Menu {
	const MENU_SLUG = 'aims';
	const DASHBOARD_PAGE = 'admin.php?page=aims';

	public function register(): void {
		add_menu_page(
			'AIMS',
			'AIMS',
			AIMS_Capabilities::CAP_ACCESS_ADMIN,
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
			'Stitch Work',
			'Stitch Work',
			AIMS_Capabilities::CAP_MANAGE_STITCH,
			'aims-stitch-work',
			array( $this, 'render_stitch_shell' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Event Participation',
			'Event Participation',
			AIMS_Capabilities::CAP_MANAGE_EVENTS,
			'aims-event-participation',
			array( $this, 'render_event_participation_shell' )
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
			'Supervisor Inventory',
			'Supervisor Inventory',
			AIMS_Capabilities::CAP_VIEW_BUCKETS,
			'aims-supervisor-inventory',
			array( $this, 'render_supervisor_inventory' )
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

	public function prune_unrelated_menus(): void {
		if ( ! $this->should_use_shell_navigation() ) {
			return;
		}

		$menu_slugs = array(
			'index.php',
			'edit.php',
			'upload.php',
			'edit.php?post_type=page',
			'edit-comments.php',
			'themes.php',
			'plugins.php',
			'users.php',
			'tools.php',
			'options-general.php',
			'woocommerce',
			'wc-admin',
			'edit.php?post_type=product',
			'edit.php?post_type=shop_order',
			'edit.php?post_type=shop_coupon',
		);

		foreach ( $menu_slugs as $menu_slug ) {
			remove_menu_page( $menu_slug );
		}
	}

	public function maybe_redirect_shell_users(): void {
		if ( ! $this->should_use_shell_navigation() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		global $pagenow;

		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'admin.php' === $pagenow && 0 === strpos( $current_page, 'aims' ) ) {
			return;
		}

		if ( in_array( $pagenow, array( 'profile.php', 'user-edit.php', 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}

		wp_safe_redirect( admin_url( self::DASHBOARD_PAGE ) );
		exit;
	}

	public function prune_admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! $this->should_use_shell_navigation() ) {
			return;
		}

		$bar->remove_node( 'wp-logo' );
		$bar->remove_node( 'comments' );
		$bar->remove_node( 'new-content' );
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>AIMS</h1><p>This is the AIMS container. Use the scoped AIMS navigation to access the modules available to your role.</p></div>';
	}

	public function render_vendors_shell(): void {
		echo '<div class="wrap"><h1>Vendor Manage</h1><p>The vendor module foundation is active. Vendor access control, bucket assignment, and vendor operations UI will be implemented here next.</p></div>';
	}

	public function render_event_participation_shell(): void {
		$page = new AIMS_Event_Participation_Page( new AIMS_Event_Participation_Data_Provider() );
		$page->render();
	}

	public function render_stitch_shell(): void {
		echo '<div class="wrap"><h1>Stitch Work</h1><p>The stitch workflow is presented here as a scoped AIMS surface for stitchers. Authorization remains enforced in services and action handlers.</p></div>';
	}

	public function render_square_sync_shell(): void {
		echo '<div class="wrap"><h1>Square Sync</h1><p>Native AIMS Square ingestion will be implemented here with queueing, dedupe, logging, and undo-safe stock controls before any live stock mutations are enabled.</p></div>';
	}

	public function render_needs_shipping_queue(): void {
		$page = new AIMS_Shipping_Queue_Page( new AIMS_Shipping_Queue_Data_Provider() );
		$page->render();
	}

	public function render_supervisor_inventory(): void {
		$page = new AIMS_Supervisor_Inventory_Page( new AIMS_Supervisor_Inventory_Data_Provider() );
		$page->render();
	}

	public function render_reports_shell(): void {
		echo '<div class="wrap"><h1>Reports &amp; Analytics</h1><p>AIMS reporting repositories will be built directly on top of native AIMS operational and sync tables in a later phase.</p></div>';
	}

	private function should_use_shell_navigation(): bool {
		return current_user_can( AIMS_Capabilities::CAP_ACCESS_ADMIN )
			&& ! current_user_can( 'manage_options' )
			&& ! current_user_can( 'manage_woocommerce' );
	}
}
