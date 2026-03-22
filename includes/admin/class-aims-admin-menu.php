<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Menu {
	const MENU_SLUG = 'aims';
	const DASHBOARD_PAGE = 'admin.php?page=aims';

	private $event_participation_page;
	private $stitch_queue_page;
	private $shipping_queue_page;
	private $supervisor_inventory_page;

	public function __construct(
		AIMS_Event_Participation_Page $event_participation_page = null,
		AIMS_Stitch_Queue_Page $stitch_queue_page = null,
		AIMS_Shipping_Queue_Page $shipping_queue_page = null,
		AIMS_Supervisor_Inventory_Page $supervisor_inventory_page = null
	) {
		$this->event_participation_page = $event_participation_page;
		$this->stitch_queue_page        = $stitch_queue_page;
		$this->shipping_queue_page      = $shipping_queue_page;
		$this->supervisor_inventory_page = $supervisor_inventory_page;
	}

	public function register(): void {
		add_menu_page(
			'AIMS',
			'AIMS',
			AIMS_Capabilities::CAP_ACCESS_SHELL,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-database-view',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Vendors',
			'Vendors',
			AIMS_Capabilities::CAP_PORTAL_VENDORS,
			'aims-vendors',
			array( $this, 'render_vendors_shell' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Stitch Work',
			'Stitch Work',
			AIMS_Capabilities::CAP_PORTAL_STITCH,
			'aims-stitch-work',
			array( $this, 'render_stitch_shell' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Event Participation',
			'Event Participation',
			AIMS_Capabilities::CAP_PORTAL_EVENTS,
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
			AIMS_Capabilities::CAP_PORTAL_BUCKETS,
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

		remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );
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
			'post-new.php?post_type=page',
			'edit-comments.php',
			'customize.php',
			'nav-menus.php',
			'themes.php',
			'site-editor.php',
			'plugins.php',
			'users.php',
			'tools.php',
			'options-general.php',
			'woocommerce-marketing',
			'woocommerce',
			'wc-admin',
			'admin.php?page=wc-admin',
			'admin.php?page=wc-orders',
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
		$shortcuts = $this->get_shell_shortcuts();
		$this->render_shell_frame(
			'AIMS',
			'This is the AIMS container for your role.',
			'Use the scoped navigation to reach only the modules you are meant to operate.',
			$shortcuts
		);
	}

	public function render_vendors_shell(): void {
		$this->render_shell_frame(
			'Vendor Operations',
			'Vendor-facing workspace',
			'Use this area for vendor records, bucket assignment, and vendor-specific operational work.'
		);
	}

	public function render_event_participation_shell(): void {
		if ( null !== $this->event_participation_page ) {
			$this->event_participation_page->render();
			return;
		}

		// Do not self-build this surface here; the bootstrap must inject the protected page object.
		wp_die( esc_html__( 'AIMS event participation is not wired.', 'ai-man-sys' ) );
	}

	public function render_stitch_shell(): void {
		if ( null !== $this->stitch_queue_page ) {
			$this->stitch_queue_page->render();
			return;
		}

		// Do not self-build this surface here; the bootstrap must inject the protected page object.
		wp_die( esc_html__( 'AIMS stitch queue is not wired.', 'ai-man-sys' ) );
	}

	public function render_square_sync_shell(): void {
		$this->render_shell_frame(
			'Square Intake',
			'Integration workspace',
			'Native Square ingestion will land here with queueing, dedupe, logging, and undo-safe stock controls.'
		);
	}

	public function render_needs_shipping_queue(): void {
		if ( null !== $this->shipping_queue_page ) {
			$this->shipping_queue_page->render();
			return;
		}

		// Do not self-build this surface here; the bootstrap must inject the protected page object.
		wp_die( esc_html__( 'AIMS shipping queue is not wired.', 'ai-man-sys' ) );
	}

	public function render_supervisor_inventory(): void {
		if ( null !== $this->supervisor_inventory_page ) {
			$this->supervisor_inventory_page->render();
			return;
		}

		// Do not self-build this surface here; the bootstrap must inject the protected page object.
		wp_die( esc_html__( 'AIMS supervisor inventory is not wired.', 'ai-man-sys' ) );
	}

	public function render_reports_shell(): void {
		$this->render_shell_frame(
			'Reports &amp; Analytics',
			'Operational reporting workspace',
			'Reporting will be built directly on top of native AIMS operational and sync tables.'
		);
	}

	private function render_shell_frame( string $title, string $subtitle, string $description, array $shortcuts = array() ): void {
		echo '<div class="wrap aims-shell">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p class="description">' . esc_html( $subtitle ) . '</p>';
		echo '<div class="notice notice-info inline" style="margin:16px 0 20px;"><p>' . esc_html( $description ) . '</p></div>';
		if ( ! empty( $shortcuts ) ) {
			echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 20px;">';
			foreach ( $shortcuts as $shortcut ) {
				$label = isset( $shortcut['label'] ) ? (string) $shortcut['label'] : '';
				$url   = isset( $shortcut['url'] ) ? (string) $shortcut['url'] : '';
				if ( '' === $label || '' === $url ) {
					continue;
				}
				echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private function get_shell_shortcuts(): array {
		$shortcuts = array();

		if ( current_user_can( AIMS_Capabilities::CAP_PORTAL_VENDORS ) ) {
			$shortcuts[] = array(
				'label' => 'Vendor Operations',
				'url'   => admin_url( 'admin.php?page=aims-vendors' ),
			);
		}

		if ( current_user_can( AIMS_Capabilities::CAP_PORTAL_STITCH ) ) {
			$shortcuts[] = array(
				'label' => 'Stitch Queue',
				'url'   => admin_url( 'admin.php?page=aims-stitch-work' ),
			);
		}

		if ( current_user_can( AIMS_Capabilities::CAP_PORTAL_EVENTS ) ) {
			$shortcuts[] = array(
				'label' => 'Event Participation',
				'url'   => admin_url( 'admin.php?page=aims-event-participation' ),
			);
		}

		if ( current_user_can( AIMS_Capabilities::CAP_PORTAL_BUCKETS ) ) {
			$shortcuts[] = array(
				'label' => 'Supervisor Inventory',
				'url'   => admin_url( 'admin.php?page=aims-supervisor-inventory' ),
			);
		}

		if ( current_user_can( AIMS_Capabilities::CAP_RUN_SYNC ) ) {
			$shortcuts[] = array(
				'label' => 'Square Intake',
				'url'   => admin_url( 'admin.php?page=aims-square-sync' ),
			);
		}

		if ( current_user_can( AIMS_Capabilities::CAP_VIEW_REPORTS ) ) {
			$shortcuts[] = array(
				'label' => 'Reports',
				'url'   => admin_url( 'admin.php?page=aims-reports' ),
			);
		}

		return $shortcuts;
	}

	private function should_use_shell_navigation(): bool {
		return current_user_can( AIMS_Capabilities::CAP_ACCESS_SHELL )
			&& AIMS_Capabilities::current_user_is_shell_user()
			&& ! current_user_can( 'manage_options' )
			&& ! current_user_can( 'manage_woocommerce' );
	}
}
