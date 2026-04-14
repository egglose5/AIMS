<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_WP_WC_Integration_Bridge {
	public const ACCOUNT_ENDPOINT    = 'aims-workspace';
	public const WORKFLOW_QUERY_VAR  = 'aims_workflow';
	public const DASHBOARD_WIDGET_ID = 'aims_workspace_widget';

	private $contract_service;

	public function __construct( AIMS_Wholesale_Contract_Service $contract_service = null ) {
		$this->contract_service = $contract_service ?: new AIMS_Wholesale_Contract_Service();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'register_account_menu_item' ), 35, 1 );
		add_action( 'woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'render_account_endpoint' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 80, 1 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
	}

	public function register_endpoint(): void {
		if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
			return;
		}

		$mask = ( defined( 'EP_ROOT' ) ? (int) EP_ROOT : 0 ) | ( defined( 'EP_PAGES' ) ? (int) EP_PAGES : 0 );
		add_rewrite_endpoint( self::ACCOUNT_ENDPOINT, $mask > 0 ? $mask : 1 );
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = self::ACCOUNT_ENDPOINT;
		$vars[] = self::WORKFLOW_QUERY_VAR;

		return array_values( array_unique( $vars ) );
	}

	public function register_account_menu_item( array $items ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! $this->has_workspace_access( $user_id ) ) {
			return $items;
		}

		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}

		$items[ self::ACCOUNT_ENDPOINT ] = __( 'AIMS Workspace', 'ai-man-sys' );

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	public function render_account_endpoint(): void {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		echo $this->render_workspace_markup(
			$user_id,
			array(
				'context'               => 'account',
				'allow_rendered_surface'=> true,
			)
		);
	}

	public function register_dashboard_widget(): void {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! $this->has_workspace_access( $user_id ) || ! function_exists( 'wp_add_dashboard_widget' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::DASHBOARD_WIDGET_ID,
			__( 'AIMS Workspace', 'ai-man-sys' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget(): void {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		echo $this->render_workspace_markup(
			$user_id,
			array(
				'context'               => 'dashboard',
				'allow_rendered_surface'=> false,
			)
		);
	}

	public function add_admin_bar_menu( $admin_bar ): void {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! $this->has_workspace_access( $user_id ) || ! is_object( $admin_bar ) || ! method_exists( $admin_bar, 'add_node' ) ) {
			return;
		}

		$workspace_url = $this->resolve_workspace_url();
		$dashboard_url = $this->can_access_admin_dashboard( $user_id )
			? admin_url( 'admin.php?page=' . AIMS_Admin_Menu::MENU_SLUG )
			: $workspace_url;

		$admin_bar->add_node(
			array(
				'id'    => 'aims',
				'title' => __( 'AIMS', 'ai-man-sys' ),
				'href'  => esc_url( $dashboard_url ),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'aims-workspace',
				'parent' => 'aims',
				'title'  => __( 'Workspace', 'ai-man-sys' ),
				'href'   => esc_url( $workspace_url ),
			)
		);

		if ( $this->can_access_admin_dashboard( $user_id ) ) {
			$admin_bar->add_node(
				array(
					'id'     => 'aims-dashboard',
					'parent' => 'aims',
					'title'  => __( 'Dashboard', 'ai-man-sys' ),
					'href'   => esc_url( $dashboard_url ),
				)
			);
		}

		foreach ( $this->get_workspace_surfaces_for_user( $user_id ) as $surface ) {
			$key = (string) $surface->get( 'key', '' );
			if ( '' === $key ) {
				continue;
			}

			$admin_bar->add_node(
				array(
					'id'     => 'aims-' . $key,
					'parent' => 'aims',
					'title'  => (string) $surface->get( 'title', $key ),
					'href'   => esc_url( $this->resolve_surface_url( $key ) ),
				)
			);
		}
	}

	private function render_workspace_markup( int $user_id, array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				'context'                => 'account',
				'allow_rendered_surface' => false,
			)
		);

		$surfaces        = $this->get_workspace_surfaces_for_user( $user_id );
		$quick_links     = $this->get_quick_links_for_user( $user_id );
		$active_workflow = isset( $_GET[ self::WORKFLOW_QUERY_VAR ] )
			? sanitize_key( (string) wp_unslash( $_GET[ self::WORKFLOW_QUERY_VAR ] ) )
			: '';
		$active_surface  = isset( $surfaces[ $active_workflow ] ) ? $surfaces[ $active_workflow ] : null;

		ob_start();
		echo '<div class="aims-native-workspace aims-native-workspace--' . esc_attr( (string) $args['context'] ) . '">';
		echo '<p><strong>' . esc_html__( 'AIMS keeps the operational truth while WordPress and WooCommerce stay familiar.', 'ai-man-sys' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Use this workspace for the day-to-day gaps base WordPress does not cover on its own: warehouse counts, vendor execution, stitch work, and contract-aware wholesale access.', 'ai-man-sys' ) . '</p>';

		if ( ! empty( $quick_links ) ) {
			echo '<div class="aims-native-workspace__section">';
			echo '<h4>' . esc_html__( 'Quick Access', 'ai-man-sys' ) . '</h4>';
			echo '<ul>';
			foreach ( $quick_links as $link ) {
				echo '<li><a href="' . esc_url( (string) $link['href'] ) . '">' . esc_html( (string) $link['label'] ) . '</a> <span style="color:#646970;">' . esc_html( (string) $link['description'] ) . '</span></li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		if ( ! empty( $surfaces ) ) {
			echo '<div class="aims-native-workspace__section">';
			echo '<h4>' . esc_html__( 'Workflow Surfaces', 'ai-man-sys' ) . '</h4>';
			echo '<ul>';
			foreach ( $surfaces as $surface ) {
				$key   = (string) $surface->get( 'key', '' );
				$title = (string) $surface->get( 'title', $key );
				echo '<li><a href="' . esc_url( $this->resolve_surface_url( $key ) ) . '">' . esc_html( $title ) . '</a> <span style="color:#646970;">' . esc_html( (string) $surface->get( 'description', '' ) ) . '</span></li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		if ( ! empty( $args['allow_rendered_surface'] ) && $active_surface instanceof AIMS_Admin_Meta_Object ) {
			echo '<div class="aims-native-workspace__section">';
			echo '<h4>' . esc_html( (string) $active_surface->get( 'title', '' ) ) . '</h4>';
			echo do_shortcode( (string) $active_surface->get( 'shortcode', '' ) );
			echo '</div>';
		}

		echo '<div class="aims-native-workspace__section">';
		echo '<h4>' . esc_html__( 'Theme Editor Entry Points', 'ai-man-sys' ) . '</h4>';
		echo '<p>' . esc_html__( 'Customer-facing and context-heavy flows still stay native to the active theme: use the AIMS Workflow Hub page template, the AIMS Workflow Surface widget, or direct AIMS shortcodes where the site already expects them.', 'ai-man-sys' ) . '</p>';
		echo '<p><code>[aims_workflow_hub]</code> <span style="color:#646970;">' . esc_html__( 'renders the theme-editor-friendly workflow index without exposing the role editor.', 'ai-man-sys' ) . '</span></p>';
		echo '</div>';

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, AIMS_Admin_Meta_Object>
	 */
	private function get_workspace_surfaces_for_user( int $user_id ): array {
		$keys      = array(
			'vendor_portal_navigation',
			'vendor_event_checkin',
			'stitch_portal',
			'cycle_count_portal',
			'wholesale_portal',
		);
		$surfaces  = array();

		foreach ( $keys as $key ) {
			if ( ! $this->user_can_access_surface( $user_id, $key ) ) {
				continue;
			}

			$surface = AIMS_Workflow_Surface_Registry::get_surface( $key );
			if ( $surface instanceof AIMS_Admin_Meta_Object ) {
				$surfaces[ $key ] = $surface;
			}
		}

		return $surfaces;
	}

	private function has_workspace_access( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->can_access_admin_dashboard( $user_id )
			|| $this->contract_service->is_wholesale_customer( $user_id )
			|| ! empty( $this->get_workspace_surfaces_for_user( $user_id ) );
	}

	private function user_can_access_surface( int $user_id, string $key ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		switch ( sanitize_key( $key ) ) {
			case 'vendor_portal_navigation':
			case 'vendor_event_checkin':
				return user_can( $user_id, AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL )
					|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_VENDORS )
					|| user_can( $user_id, AIMS_Capabilities::CAP_RESP_VENDOR_SUBMIT_CHECKIN );

			case 'stitch_portal':
				return user_can( $user_id, AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL )
					|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_STITCH )
					|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_STITCH_ORDERS )
					|| user_can( $user_id, AIMS_Capabilities::CAP_RESP_STITCH_ORDER_MANAGEMENT );

			case 'cycle_count_portal':
				return user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_INVENTORY )
					|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE )
					|| user_can( $user_id, AIMS_Capabilities::CAP_VIEW_INVENTORY_SHELL );

			case 'wholesale_portal':
				return $this->contract_service->is_wholesale_customer( $user_id );
		}

		return false;
	}

	private function can_access_admin_dashboard( int $user_id ): bool {
		return $user_id > 0 && (
			user_can( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| user_can( $user_id, AIMS_Capabilities::CAP_VIEW_DASHBOARD )
			|| user_can( $user_id, 'manage_options' )
		);
	}

	private function resolve_workspace_url(): string {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return (string) wc_get_account_endpoint_url( self::ACCOUNT_ENDPOINT );
		}

		return home_url( self::ACCOUNT_ENDPOINT . '/' );
	}

	private function resolve_surface_url( string $key ): string {
		$key = sanitize_key( $key );

		if ( 'wholesale_portal' === $key && function_exists( 'wc_get_account_endpoint_url' ) ) {
			return (string) wc_get_account_endpoint_url( 'aims-wholesale' );
		}

		return add_query_arg(
			array(
				self::WORKFLOW_QUERY_VAR => $key,
			),
			$this->resolve_workspace_url()
		);
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function get_quick_links_for_user( int $user_id ): array {
		$links = array();

		if ( $this->can_access_admin_dashboard( $user_id ) ) {
			$links[] = array(
				'label'       => __( 'AIMS Dashboard', 'ai-man-sys' ),
				'href'        => admin_url( 'admin.php?page=' . AIMS_Admin_Menu::MENU_SLUG ),
				'description' => __( 'Use wp-admin for owner-level control, inventory health, and core sync visibility.', 'ai-man-sys' ),
			);
		}

		if ( $this->contract_service->is_wholesale_customer( $user_id ) ) {
			$links[] = array(
				'label'       => __( 'Wholesale Account', 'ai-man-sys' ),
				'href'        => $this->resolve_surface_url( 'wholesale_portal' ),
				'description' => __( 'Keep reorders, contract terms, and customer-specific pricing inside WooCommerce My Account.', 'ai-man-sys' ),
			);
		}

		if ( ! empty( $this->get_workspace_surfaces_for_user( $user_id ) ) ) {
			$links[] = array(
				'label'       => __( 'AIMS Workspace', 'ai-man-sys' ),
				'href'        => $this->resolve_workspace_url(),
				'description' => __( 'Open the native WooCommerce workspace for operational AIMS tasks.', 'ai-man-sys' ),
			);
		}

		return $links;
	}
}
