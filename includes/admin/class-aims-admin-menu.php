<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Menu {
	const MENU_SLUG           = 'aims';
	const ACTIVITY_PAGE_SLUG  = 'aims-activity-log';
	const SETTINGS_PAGE_SLUG  = 'aims-settings';
	const SETTINGS_GROUP      = 'aims_headless_settings';
	const NOTICE_QUERY_ARG    = 'aims_notice';
	private $surface_authorization;
	private $audit_log_service;

	public function __construct( AIMS_Surface_Authorization_Service $surface_authorization = null, AIMS_Audit_Log_Service $audit_log_service = null ) {
		$this->surface_authorization = $surface_authorization ?: new AIMS_Surface_Authorization_Service();
		$this->audit_log_service     = $audit_log_service ?: new AIMS_Audit_Log_Service();
	}

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

		add_submenu_page(
			self::MENU_SLUG,
			'Activity Log',
			'Activity Log',
			$this->get_menu_capability(),
			self::ACTIVITY_PAGE_SLUG,
			array( $this, 'render_activity_log' )
		);

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_aims_submit_remote_move', array( $this, 'handle_submit_remote_move' ) );
		add_action( 'admin_post_aims_register_remote_bucket', array( $this, 'handle_register_remote_bucket' ) );
		add_action( 'admin_post_aims_receive_remote_fifo', array( $this, 'handle_receive_remote_fifo' ) );
		add_action( 'admin_post_aims_move_remote_custody', array( $this, 'handle_move_remote_custody' ) );
		add_action( 'admin_post_aims_pick_remote_fifo', array( $this, 'handle_pick_remote_fifo' ) );
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
		$buckets       = $client->get_buckets();
		$availability  = array(
			'success' => false,
			'json'    => array(),
		);
		$availability_query = $this->get_fifo_availability_query();
		$notice        = $this->get_notice();

		if ( $client->is_configured() && '' !== (string) ( $availability_query['sku'] ?? '' ) ) {
			$availability = $client->get_fifo_availability( $availability_query );
		}

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
		echo '<h2>Bucket Registry</h2>';
		$this->render_remote_bucket_form();
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Current Buckets</h2>';
		$this->render_remote_bucket_list( $buckets );
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Receive Inventory</h2>';
		$this->render_remote_fifo_receive_form();
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Custody Move</h2>';
		$this->render_remote_custody_form();
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>FIFO Availability</h2>';
		$this->render_remote_fifo_availability( $availability, $availability_query );
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>FIFO Pick</h2>';
		$this->render_remote_fifo_pick_form();
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

	public function render_activity_log(): void {
		$page = new AIMS_Audit_Log_Page(
			new AIMS_Audit_Log_Data_Provider( $this->audit_log_service )
		);

		$page->render();
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
		$this->record_audit_event( 'movement_send', (string) $payload['sku'], ! empty( $response['success'] ) );
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'movement_sent' : 'movement_failed',
		) );
	}

	public function handle_register_remote_bucket(): void {
		$this->require_capability();
		check_admin_referer( 'aims_register_remote_bucket' );

		$payload = array(
			'bucket_code'      => $this->sanitize_request_string( $_POST['bucket_code'] ?? '' ),
			'bucket_label'     => $this->sanitize_request_string( $_POST['bucket_label'] ?? '' ),
			'bucket_type'      => $this->sanitize_request_string( $_POST['bucket_type'] ?? 'physical' ),
			'status'           => $this->sanitize_request_string( $_POST['status'] ?? 'active' ),
			'show_id'          => $this->sanitize_request_string( $_POST['show_id'] ?? '' ),
			'current_location' => $this->sanitize_request_string( $_POST['current_location'] ?? '' ),
			'current_custody'  => $this->sanitize_request_string( $_POST['current_custody'] ?? '' ),
		);

		$response = AIMS_Headless_Api_Client::from_plugin_options()->register_bucket( $payload );
		$this->record_audit_event( 'bucket_register', (string) $payload['bucket_code'], ! empty( $response['success'] ) );
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'bucket_saved' : 'bucket_failed',
		) );
	}

	public function handle_receive_remote_fifo(): void {
		$this->require_capability();
		check_admin_referer( 'aims_receive_remote_fifo' );

		$payload = array(
			'bucket_code'       => $this->sanitize_request_string( $_POST['bucket_code'] ?? '' ),
			'sku'               => $this->sanitize_request_string( $_POST['sku'] ?? '' ),
			'show_id'           => $this->sanitize_request_string( $_POST['show_id'] ?? '' ),
			'current_location'  => $this->sanitize_request_string( $_POST['current_location'] ?? '' ),
			'current_custody'   => $this->sanitize_request_string( $_POST['current_custody'] ?? '' ),
			'receipt_reference' => $this->sanitize_request_string( $_POST['receipt_reference'] ?? '' ),
			'source_reference'  => $this->sanitize_request_string( $_POST['source_reference'] ?? '' ),
			'quantity'          => $this->sanitize_request_float( $_POST['quantity'] ?? 0 ),
			'unit_cost'         => $this->sanitize_request_float( $_POST['unit_cost'] ?? 0 ),
		);

		$response = AIMS_Headless_Api_Client::from_plugin_options()->receive_fifo( $payload );
		$this->record_audit_event(
			'inbound_receive',
			'' !== (string) $payload['receipt_reference'] ? (string) $payload['receipt_reference'] : (string) $payload['sku'],
			! empty( $response['success'] )
		);
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'receipt_sent' : 'receipt_failed',
		) );
	}

	public function handle_move_remote_custody(): void {
		$this->require_capability();
		check_admin_referer( 'aims_move_remote_custody' );

		$payload = array(
			'bucket_code'    => $this->sanitize_request_string( $_POST['bucket_code'] ?? '' ),
			'from_location'  => $this->sanitize_request_string( $_POST['from_location'] ?? '' ),
			'to_location'    => $this->sanitize_request_string( $_POST['to_location'] ?? '' ),
			'from_custody'   => $this->sanitize_request_string( $_POST['from_custody'] ?? '' ),
			'to_custody'     => $this->sanitize_request_string( $_POST['to_custody'] ?? '' ),
			'reference_type' => $this->sanitize_request_string( $_POST['reference_type'] ?? '' ),
			'reference_id'   => $this->sanitize_request_string( $_POST['reference_id'] ?? '' ),
			'movement_type'  => $this->sanitize_request_string( $_POST['movement_type'] ?? 'custody_transfer' ),
			'note'           => $this->sanitize_request_string( $_POST['note'] ?? '' ),
		);

		$response = AIMS_Headless_Api_Client::from_plugin_options()->move_custody( $payload );
		$this->record_audit_event( 'custody_move', (string) $payload['bucket_code'], ! empty( $response['success'] ) );
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'custody_moved' : 'custody_failed',
		) );
	}

	public function handle_pick_remote_fifo(): void {
		$this->require_capability();
		check_admin_referer( 'aims_pick_remote_fifo' );

		$payload = array(
			'sku'               => $this->sanitize_request_string( $_POST['sku'] ?? '' ),
			'show_id'           => $this->sanitize_request_string( $_POST['show_id'] ?? '' ),
			'request_reference' => $this->sanitize_request_string( $_POST['request_reference'] ?? '' ),
			'quantity'          => $this->sanitize_request_float( $_POST['quantity'] ?? 0 ),
			'amount_paid'       => $this->sanitize_request_float( $_POST['amount_paid'] ?? 0 ),
			'tax_amount'        => $this->sanitize_request_float( $_POST['tax_amount'] ?? 0 ),
		);

		$response = AIMS_Headless_Api_Client::from_plugin_options()->pick_fifo( $payload );
		$this->record_audit_event(
			'fifo_pick',
			'' !== (string) $payload['request_reference'] ? (string) $payload['request_reference'] : (string) $payload['sku'],
			! empty( $response['success'] )
		);
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => $response['success'] ? 'fifo_picked' : 'fifo_pick_failed',
			'aims_fifo_sku'        => $payload['sku'],
			'aims_fifo_show_id'    => $payload['show_id'],
		) );
	}

	public function handle_trigger_remote_archive(): void {
		$this->require_capability();
		check_admin_referer( 'aims_trigger_remote_archive' );

		$response = AIMS_Headless_Api_Client::from_plugin_options()->trigger_archive();
		$this->record_audit_event( 'archive_trigger', 'archive', ! empty( $response['success'] ) );
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

		$this->record_audit_event(
			'manifest_sync',
			is_array( $manifest['json'] ?? null ) ? (string) ( $manifest['json']['manifest_uuid'] ?? 'manifest' ) : 'manifest',
			! empty( $response['success'] )
		);

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

	private function render_remote_bucket_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_register_remote_bucket" />';
		wp_nonce_field( 'aims_register_remote_bucket' );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-bucket-code">Bucket Code</label></th><td><input id="aims-bucket-code" type="text" class="regular-text" name="bucket_code" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-bucket-label">Bucket Label</label></th><td><input id="aims-bucket-label" type="text" class="regular-text" name="bucket_label" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-bucket-type">Bucket Type</label></th><td><input id="aims-bucket-type" type="text" class="regular-text" name="bucket_type" value="physical" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-bucket-status">Status</label></th><td><input id="aims-bucket-status" type="text" class="regular-text" name="status" value="active" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-bucket-show-id">Show ID</label></th><td><input id="aims-bucket-show-id" type="text" class="regular-text" name="show_id" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-bucket-current-location">Current Location</label></th><td><input id="aims-bucket-current-location" type="text" class="regular-text" name="current_location" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-bucket-current-custody">Current Custody</label></th><td><input id="aims-bucket-current-custody" type="text" class="regular-text" name="current_custody" /></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Register Bucket', 'secondary' );
		echo '</form>';
	}

	private function render_remote_bucket_list( array $buckets ): void {
		if ( empty( $buckets['success'] ) ) {
			echo '<p><strong>Connection error:</strong> ' . esc_html( (string) ( $buckets['message'] ?? 'Unable to load buckets from AIMS Core.' ) ) . '</p>';
			return;
		}

		$rows = is_array( $buckets['json']['buckets'] ?? null ) ? $buckets['json']['buckets'] : array();
		if ( empty( $rows ) ) {
			echo '<p>No buckets returned by AIMS Core yet.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Bucket</th><th>Type</th><th>Show</th><th>Location</th><th>Custody</th><th>On Hand</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['bucket_code'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['bucket_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['show_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['current_location'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['current_custody'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['on_hand_quantity'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_remote_fifo_receive_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_receive_remote_fifo" />';
		wp_nonce_field( 'aims_receive_remote_fifo' );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-receive-bucket-code">Bucket Code</label></th><td><input id="aims-receive-bucket-code" type="text" class="regular-text" name="bucket_code" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-sku">SKU</label></th><td><input id="aims-receive-sku" type="text" class="regular-text" name="sku" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-show-id">Show ID</label></th><td><input id="aims-receive-show-id" type="text" class="regular-text" name="show_id" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-location">Current Location</label></th><td><input id="aims-receive-location" type="text" class="regular-text" name="current_location" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-custody">Current Custody</label></th><td><input id="aims-receive-custody" type="text" class="regular-text" name="current_custody" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-reference">Receipt Reference</label></th><td><input id="aims-receive-reference" type="text" class="regular-text" name="receipt_reference" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-source-reference">Source Reference</label></th><td><input id="aims-receive-source-reference" type="text" class="regular-text" name="source_reference" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-quantity">Quantity</label></th><td><input id="aims-receive-quantity" type="number" min="0.0001" step="0.0001" name="quantity" value="1" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-receive-unit-cost">Unit Cost</label></th><td><input id="aims-receive-unit-cost" type="number" min="0" step="0.01" name="unit_cost" value="0.00" required /></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Receive Inventory', 'secondary' );
		echo '</form>';
	}

	private function render_remote_custody_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_move_remote_custody" />';
		wp_nonce_field( 'aims_move_remote_custody' );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-custody-bucket-code">Bucket Code</label></th><td><input id="aims-custody-bucket-code" type="text" class="regular-text" name="bucket_code" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-from-location">From Location</label></th><td><input id="aims-custody-from-location" type="text" class="regular-text" name="from_location" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-to-location">To Location</label></th><td><input id="aims-custody-to-location" type="text" class="regular-text" name="to_location" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-from-custody">From Custody</label></th><td><input id="aims-custody-from-custody" type="text" class="regular-text" name="from_custody" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-to-custody">To Custody</label></th><td><input id="aims-custody-to-custody" type="text" class="regular-text" name="to_custody" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-reference-type">Reference Type</label></th><td><input id="aims-custody-reference-type" type="text" class="regular-text" name="reference_type" value="custody_transfer" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-reference-id">Reference ID</label></th><td><input id="aims-custody-reference-id" type="text" class="regular-text" name="reference_id" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-movement-type">Movement Type</label></th><td><input id="aims-custody-movement-type" type="text" class="regular-text" name="movement_type" value="custody_transfer" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-custody-note">Note</label></th><td><input id="aims-custody-note" type="text" class="regular-text" name="note" /></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Move Custody', 'secondary' );
		echo '</form>';
	}

	private function render_remote_fifo_availability( array $availability, array $query ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-fifo-sku">SKU</label></th><td><input id="aims-fifo-sku" type="text" class="regular-text" name="aims_fifo_sku" value="' . esc_attr( (string) ( $query['sku'] ?? '' ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-fifo-show-id">Show ID</label></th><td><input id="aims-fifo-show-id" type="text" class="regular-text" name="aims_fifo_show_id" value="' . esc_attr( (string) ( $query['show_id'] ?? '' ) ) . '" /></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Lookup FIFO Availability', 'secondary', '', false );
		echo '</form>';

		if ( '' === (string) ( $query['sku'] ?? '' ) ) {
			echo '<p>Enter a SKU to query FIFO availability from AIMS Core.</p>';
			return;
		}

		if ( empty( $availability['success'] ) ) {
			echo '<p><strong>Lookup error:</strong> ' . esc_html( (string) ( $availability['message'] ?? 'Unable to load FIFO availability.' ) ) . '</p>';
			return;
		}

		$rows = is_array( $availability['json']['availability'] ?? null ) ? $availability['json']['availability'] : array();
		if ( empty( $rows ) ) {
			echo '<p>No eligible FIFO lots are available for this SKU.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Lot</th><th>Bucket</th><th>Show</th><th>Remaining</th><th>Unit Cost</th><th>Received</th><th>Location</th><th>Custody</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['lot_uuid'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['bucket_code'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['show_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['remaining_quantity'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['unit_cost'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['received_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['current_location'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['current_custody'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_remote_fifo_pick_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_pick_remote_fifo" />';
		wp_nonce_field( 'aims_pick_remote_fifo' );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-pick-sku">SKU</label></th><td><input id="aims-pick-sku" type="text" class="regular-text" name="sku" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-pick-show-id">Show ID</label></th><td><input id="aims-pick-show-id" type="text" class="regular-text" name="show_id" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-pick-request-reference">Request Reference</label></th><td><input id="aims-pick-request-reference" type="text" class="regular-text" name="request_reference" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-pick-quantity">Quantity</label></th><td><input id="aims-pick-quantity" type="number" min="0.0001" step="0.0001" name="quantity" value="1" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-pick-amount-paid">Amount Paid</label></th><td><input id="aims-pick-amount-paid" type="number" min="0" step="0.01" name="amount_paid" value="0.00" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-pick-tax-amount">Tax Amount</label></th><td><input id="aims-pick-tax-amount" type="number" min="0" step="0.01" name="tax_amount" value="0.00" /></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Run FIFO Pick', 'primary' );
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
			'bucket_saved'   => array( 'success', 'Bucket saved in AIMS Core.' ),
			'bucket_failed'  => array( 'error', 'Bucket save failed.' ),
			'receipt_sent'   => array( 'success', 'Inbound receipt sent to AIMS Core.' ),
			'receipt_failed' => array( 'error', 'Inbound receipt failed.' ),
			'custody_moved'  => array( 'success', 'Custody move sent to AIMS Core.' ),
			'custody_failed' => array( 'error', 'Custody move failed.' ),
			'fifo_picked'    => array( 'success', 'FIFO pick completed in AIMS Core.' ),
			'fifo_pick_failed' => array( 'error', 'FIFO pick failed.' ),
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

	private function sanitize_request_float( $value ): float {
		return round( (float) wp_unslash( $value ), 4 );
	}

	private function get_fifo_availability_query(): array {
		return array(
			'sku'     => isset( $_GET['aims_fifo_sku'] ) ? $this->sanitize_request_string( $_GET['aims_fifo_sku'] ) : '',
			'show_id' => isset( $_GET['aims_fifo_show_id'] ) ? $this->sanitize_request_string( $_GET['aims_fifo_show_id'] ) : '',
		);
	}

	private function record_audit_event( string $action_key, string $reference_id, bool $success ): void {
		$this->audit_log_service->record_action(
			$this->get_menu_capability(),
			$action_key,
			$reference_id,
			array(
				'status'  => $success ? 'success' : 'failed',
				'surface' => AIMS_Capabilities::SURFACE_WP_ADMIN,
			)
		);
	}

	private function require_capability(): void {
		if ( ! $this->surface_authorization->current_user_can_for_surface( $this->get_menu_capability(), AIMS_Capabilities::SURFACE_WP_ADMIN ) ) {
			wp_die( esc_html__( 'You do not have permission to access the AIMS dashboard.', 'ai-man-sys' ) );
		}
	}

	private function get_menu_capability(): string {
		return current_user_can( AIMS_Capabilities::CAP_MANAGE ) ? AIMS_Capabilities::CAP_MANAGE : 'manage_options';
	}
}
