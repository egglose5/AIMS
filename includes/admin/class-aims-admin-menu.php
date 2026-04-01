<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Menu {
	const MENU_SLUG           = 'aims';
	const SETTINGS_PAGE_SLUG  = 'aims-settings';
	const SETTINGS_GROUP      = 'aims_headless_settings';
	const NOTICE_QUERY_ARG    = 'aims_notice';

	public function register(): void {
		add_menu_page(
			'AIMS',
			'AIMS',
			$this->get_menu_capability(),
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-database-view',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Dashboard',
			'Dashboard',
			$this->get_menu_capability(),
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Settings',
			'Settings',
			$this->get_menu_capability(),
			self::SETTINGS_PAGE_SLUG,
			array( $this, 'render_settings' )
		);

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_aims_submit_remote_move', array( $this, 'handle_submit_remote_move' ) );
		add_action( 'admin_post_aims_sync_remote_manifest', array( $this, 'handle_sync_remote_manifest' ) );
		add_action( 'admin_post_aims_trigger_remote_archive', array( $this, 'handle_trigger_remote_archive' ) );
	}

	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			AIMS_Plugin::OPTION_API_URL,
			array(
				'type'              => 'string',
				'sanitize_callback'  => array( 'AIMS_Plugin', 'sanitize_api_url' ),
				'default'           => '',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			AIMS_Plugin::OPTION_API_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback'  => array( 'AIMS_Plugin', 'sanitize_api_token' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'aims_headless_connection',
			'Headless Connection',
			function (): void {
				echo '<p>Point the WordPress UI at the standalone AIMS core service.</p>';
			},
			self::SETTINGS_PAGE_SLUG
		);

		add_settings_field(
			AIMS_Plugin::OPTION_API_URL,
			'AIMS API URL',
			array( $this, 'render_api_url_field' ),
			self::SETTINGS_PAGE_SLUG,
			'aims_headless_connection'
		);

		add_settings_field(
			AIMS_Plugin::OPTION_API_TOKEN,
			'AIMS Token',
			array( $this, 'render_api_token_field' ),
			self::SETTINGS_PAGE_SLUG,
			'aims_headless_connection'
		);
	}

	public function render_dashboard(): void {
		$client        = AIMS_Headless_Api_Client::from_plugin_options();
		$manifest      = $client->get_manifest();
		$notice        = $this->get_notice();

		echo '<div class="wrap aims-headless-dashboard">';
		echo '<h1>AIMS Control</h1>';
		echo '<p>WordPress is the window. The live inventory truth lives in the headless AIMS core.</p>';

		if ( '' === AIMS_Plugin::get_api_url() || '' === AIMS_Plugin::get_api_token() ) {
			echo '<div class="notice notice-warning"><p>Connect the API URL and token in Settings before using the dashboard.</p></div>';
		}

		if ( '' !== $notice ) {
			$this->render_notice( $notice );
		}

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Core Status</h2>';
		$this->render_manifest_status( $manifest );
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Record a Movement</h2>';
		$this->render_remote_move_form();
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Manifest Sync</h2>';
		$this->render_remote_manifest_sync_form();
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Cold Storage</h2>';
		$this->render_remote_archive_form();
		echo '</div>';

		if ( isset( $_GET['aims_archive_result'] ) ) {
			$archive_reply = sanitize_text_field( wp_unslash( $_GET['aims_archive_result'] ) );
			echo '<div class="notice notice-info"><p>' . esc_html( $archive_reply ) . '</p></div>';
		}

		echo '</div>';
	}

	public function render_settings(): void {
		echo '<div class="wrap">';
		echo '<h1>AIMS Settings</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::SETTINGS_GROUP );
		do_settings_sections( self::SETTINGS_PAGE_SLUG );
		submit_button( 'Save Connection' );
		echo '</form>';
		echo '</div>';
	}

	public function render_api_url_field(): void {
		printf(
			'<input type="url" class="regular-text" name="%1$s" value="%2$s" placeholder="https://aims-core.example.com" />',
			esc_attr( AIMS_Plugin::OPTION_API_URL ),
			esc_attr( AIMS_Plugin::get_api_url() )
		);
	}

	public function render_api_token_field(): void {
		printf(
			'<input type="password" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" placeholder="X-Ames-Token secret" />',
			esc_attr( AIMS_Plugin::OPTION_API_TOKEN ),
			esc_attr( AIMS_Plugin::get_api_token() )
		);
	}

	public function handle_submit_remote_move(): void {
		$this->require_capability();
		check_admin_referer( 'aims_submit_remote_move' );

		$payload = array(
			'sku'            => $this->sanitize_request_string( $_POST['sku'] ?? '' ),
			'from_location'   => $this->sanitize_request_string( $_POST['from_location'] ?? '' ),
			'to_location'     => $this->sanitize_request_string( $_POST['to_location'] ?? '' ),
			'quantity'        => max( 1, absint( $_POST['quantity'] ?? 0 ) ),
		);

		$response = AIMS_Headless_Api_Client::from_plugin_options()->post_move( $payload );
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'movement_sent' : 'movement_failed',
		) );
	}

	public function handle_trigger_remote_archive(): void {
		$this->require_capability();
		check_admin_referer( 'aims_trigger_remote_archive' );

		$response = AIMS_Headless_Api_Client::from_plugin_options()->trigger_archive();
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'archive_started' : 'archive_failed',
			'aims_archive_result'   => $response['success'] ? 'Archive request completed.' : 'Archive request failed.',
		) );
	}

	public function handle_sync_remote_manifest(): void {
		$this->require_capability();
		check_admin_referer( 'aims_sync_remote_manifest' );

		$client   = AIMS_Headless_Api_Client::from_plugin_options();
		$manifest = $client->get_manifest();
		$response = array(
			'success' => false,
		);

		if ( ! empty( $manifest['success'] ) && is_array( $manifest['json'] ?? null ) ) {
			$response = $client->push_manifest( $manifest['json'] );
		}

		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'manifest_synced' : 'manifest_sync_failed',
		) );
	}

	private function render_remote_move_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_submit_remote_move" />';
		wp_nonce_field( 'aims_submit_remote_move' );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-sku">SKU</label></th><td><input id="aims-sku" type="text" class="regular-text" name="sku" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-from-location">From Location</label></th><td><input id="aims-from-location" type="text" class="regular-text" name="from_location" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-to-location">To Location</label></th><td><input id="aims-to-location" type="text" class="regular-text" name="to_location" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-quantity">Quantity</label></th><td><input id="aims-quantity" type="number" min="1" step="1" name="quantity" value="1" required /></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Send Movement to AIMS Core' );
		echo '</form>';
	}

	private function render_remote_archive_form(): void {
		echo '<p>Trigger the PHP-only archive flow in the headless core.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_trigger_remote_archive" />';
		wp_nonce_field( 'aims_trigger_remote_archive' );
		submit_button( 'Archive to Parquet', 'secondary' );
		echo '</form>';
	}

	private function render_remote_manifest_sync_form(): void {
		echo '<p>Fetch the latest manifest from the core, then push it back in a single remote transaction.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_sync_remote_manifest" />';
		wp_nonce_field( 'aims_sync_remote_manifest' );
		submit_button( 'Sync Manifest', 'primary' );
		echo '</form>';
	}

	private function render_manifest_status( array $manifest ): void {
		if ( empty( $manifest['success'] ) ) {
			echo '<p><strong>Connection error:</strong> ' . esc_html( (string) ( $manifest['message'] ?? 'Unable to reach the AIMS core.' ) ) . '</p>';
			return;
		}

		$json = is_array( $manifest['json'] ?? null ) ? $manifest['json'] : array();
		echo '<p><strong>HTTP:</strong> ' . esc_html( (string) ( $manifest['code'] ?? 0 ) ) . '</p>';
		echo '<p><strong>Manifest:</strong> ' . esc_html( (string) ( $json['manifest_uuid'] ?? 'n/a' ) ) . '</p>';
		echo '<p><strong>Generated:</strong> ' . esc_html( (string) ( $json['generated_at'] ?? 'n/a' ) ) . '</p>';
		echo '<p><strong>Items:</strong> ' . esc_html( (string) ( $json['summary']['merged_items'] ?? 0 ) ) . '</p>';
		echo '<pre style="max-height:320px;overflow:auto;background:#fff;padding:12px;border:1px solid #dcdcde;">' . esc_html( wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
	}

	private function render_notice( string $notice ): void {
		$message_map = array(
			'movement_sent'  => array( 'success', 'Movement request sent to the AIMS core.' ),
			'movement_failed'=> array( 'error', 'Movement request failed.' ),
			'manifest_synced'=> array( 'success', 'Manifest sync completed.' ),
			'manifest_sync_failed' => array( 'error', 'Manifest sync failed.' ),
			'archive_started'=> array( 'success', 'Archive request sent.' ),
			'archive_failed' => array( 'error', 'Archive request failed.' ),
		);

		if ( ! isset( $message_map[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $message_map[ $notice ];
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	private function redirect_back( array $query_args = array() ): void {
		$url = add_query_arg(
			array_merge(
				array(
					'page' => self::MENU_SLUG,
				),
				$query_args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function get_notice(): string {
		return isset( $_GET[ self::NOTICE_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NOTICE_QUERY_ARG ] ) ) : '';
	}

	private function sanitize_request_string( $value ): string {
		return sanitize_text_field( (string) wp_unslash( $value ) );
	}

	private function require_capability(): void {
		if ( ! current_user_can( $this->get_menu_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access the AIMS dashboard.', 'ai-man-sys' ) );
		}
	}

	private function get_menu_capability(): string {
		return current_user_can( AIMS_Capabilities::CAP_MANAGE ) ? AIMS_Capabilities::CAP_MANAGE : 'manage_options';
	}
}
