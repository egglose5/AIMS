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
	private $hot_db_health_service;
	private $square_location_push_policy;
	private $low_stock_alert_service;
 	private $customer_spend_window_service;
	private $wholesale_contract_service;

	public function __construct( AIMS_Surface_Authorization_Service $surface_authorization = null, AIMS_Audit_Log_Service $audit_log_service = null, AIMS_Hot_Db_Health_Service $hot_db_health_service = null, AIMS_Square_Location_Push_Policy_Service $square_location_push_policy = null, AIMS_Low_Stock_Alert_Service $low_stock_alert_service = null, AIMS_Customer_Spend_Window_Service $customer_spend_window_service = null, AIMS_Wholesale_Contract_Service $wholesale_contract_service = null ) {
		$this->surface_authorization     = $surface_authorization ?: new AIMS_Surface_Authorization_Service();
		$this->audit_log_service         = $audit_log_service ?: new AIMS_Audit_Log_Service();
		$this->hot_db_health_service     = $hot_db_health_service ?: new AIMS_Hot_Db_Health_Service();
		$this->square_location_push_policy = $square_location_push_policy ?: new AIMS_Square_Location_Push_Policy_Service();
		$this->low_stock_alert_service   = $low_stock_alert_service ?: new AIMS_Low_Stock_Alert_Service();
		$this->customer_spend_window_service = $customer_spend_window_service ?: new AIMS_Customer_Spend_Window_Service();
		$this->wholesale_contract_service = $wholesale_contract_service ?: new AIMS_Wholesale_Contract_Service();
	}

	public function register(): void {
			   // Add Role Editor submenu
			   add_submenu_page(
				   self::MENU_SLUG,
				   'Role Editor',
				   'Role Editor',
				   $this->get_menu_capability(),
				   'aims-role-editor',
				   function() {
					   if (class_exists('AIMS_Role_Editor_Page')) {
						   $page = new AIMS_Role_Editor_Page();
						   $page->render();
					   } else {
						   echo '<div class="notice notice-error"><p>Role editor is unavailable.</p></div>';
					   }
				   }
			   );
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
			'Square API',
			'Square API',
			$this->get_menu_capability(),
			'aims-square-api',
			array( $this, 'render_square_api_settings' )
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
	   add_action( 'admin_post_aims_designate_wholesale_customer', array( $this, 'handle_designate_wholesale_customer' ) );
	   // End of register()
}

public function render_square_api_settings() {
	   // Attempt to auto-populate from WooCommerce Square plugin (stub for future extension)
	   $auto_token = '';
	   $auto_location = '';
	   $auto_version = '2026-01-22';
	   // TODO: Add logic to pull from WooCommerce Square plugin if present

	   $core_env = dirname(__DIR__,2) . '/ames-core/.env';
	   $env = file_exists($core_env) ? file_get_contents($core_env) : '';
	   $token = '';
	   $location = '';
	   $version = '';
	   if ($env) {
		   if (preg_match('/AIMS_SQUARE_ACCESS_TOKEN=(.*)/', $env, $m)) $token = trim($m[1]);
		   if (preg_match('/AIMS_SQUARE_LOCATION_ID=(.*)/', $env, $m)) $location = trim($m[1]);
		   if (preg_match('/AIMS_SQUARE_VERSION=(.*)/', $env, $m)) $version = trim($m[1]);
	   }
	   if (isset($_POST['aims_square_api_save'])) {
		   check_admin_referer('aims_square_api_settings');
		   $token = sanitize_text_field($_POST['aims_square_access_token'] ?? '');
		   $location = sanitize_text_field($_POST['aims_square_location_id'] ?? '');
		   $version = sanitize_text_field($_POST['aims_square_version'] ?? '');
		   // Update .env
		   $lines = $env ? explode("\n", $env) : [];
		   $found_token = $found_location = $found_version = false;
		   foreach ($lines as &$line) {
			   if (strpos($line, 'AIMS_SQUARE_ACCESS_TOKEN=') === 0) { $line = 'AIMS_SQUARE_ACCESS_TOKEN=' . $token; $found_token = true; }
			   if (strpos($line, 'AIMS_SQUARE_LOCATION_ID=') === 0) { $line = 'AIMS_SQUARE_LOCATION_ID=' . $location; $found_location = true; }
			   if (strpos($line, 'AIMS_SQUARE_VERSION=') === 0) { $line = 'AIMS_SQUARE_VERSION=' . $version; $found_version = true; }
		   }
		   if (!$found_token) $lines[] = 'AIMS_SQUARE_ACCESS_TOKEN=' . $token;
		   if (!$found_location) $lines[] = 'AIMS_SQUARE_LOCATION_ID=' . $location;
		   if (!$found_version) $lines[] = 'AIMS_SQUARE_VERSION=' . $version;
		   file_put_contents($core_env, implode("\n", $lines));
		   echo '<div class="notice notice-success is-dismissible"><p>Square API credentials saved.</p></div>';
	   }
	   echo '<div class="wrap"><h1>Square API Settings</h1>';
	   echo '<form method="post">';
	   wp_nonce_field('aims_square_api_settings');
	   echo '<table class="form-table">';
	   echo '<tr><th scope="row"><label for="aims_square_access_token">Access Token</label></th><td><input type="text" id="aims_square_access_token" name="aims_square_access_token" value="' . esc_attr($token ?: $auto_token) . '" class="regular-text" /></td></tr>';
	   // Fetch all vendor Square Location IDs for select
	   $vendor_service = class_exists('AIMS_Vendor_User_Metadata_Service') ? new AIMS_Vendor_User_Metadata_Service() : null;
	   $vendor_ids = $vendor_service ? $vendor_service->get_all_vendors('active') : array();
	   $location_options = array();
	   if ($vendor_service) {
		   foreach ($vendor_ids as $vid) {
			   $loc_id = $vendor_service->get_square_location_id($vid);
			   if ($loc_id !== '') {
				   $vendor_name = $vendor_service->get_vendor_name($vid);
				   $location_options[$loc_id] = $vendor_name . ' (' . $loc_id . ')';
			   }
		   }
	   }
	   echo '<tr><th scope="row"><label for="aims_square_location_id">Location ID</label></th><td><select id="aims_square_location_id" name="aims_square_location_id" class="regular-text">';
	   echo '<option value="">Select a vendor location...</option>';
	   foreach ($location_options as $loc_id => $label) {
		   $selected = ($loc_id === ($location ?: $auto_location)) ? ' selected' : '';
		   echo '<option value="' . esc_attr($loc_id) . '"' . $selected . '>' . esc_html($label) . '</option>';
	   }
	   echo '</select></td></tr>';
	   echo '<tr><th scope="row"><label for="aims_square_version">API Version</label></th><td><input type="text" id="aims_square_version" name="aims_square_version" value="' . esc_attr($version ?: $auto_version) . '" class="regular-text" /></td></tr>';
	   echo '</table>';
	   submit_button('Save Credentials', 'primary', 'aims_square_api_save');
	echo '</form></div>';
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

		register_setting(
			self::SETTINGS_GROUP,
			AIMS_Plugin::OPTION_LOW_STOCK_THRESHOLD,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( 'AIMS_Plugin', 'sanitize_low_stock_threshold' ),
				'default'           => 5,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			AIMS_Plugin::OPTION_CUSTOMER_SPEND_WINDOW_DAYS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( 'AIMS_Plugin', 'sanitize_customer_spend_window_days' ),
				'default'           => 30,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			AIMS_Plugin::OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( 'AIMS_Plugin', 'sanitize_customer_spend_qualify_amount' ),
				'default'           => 0,
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

		add_settings_section(
			'aims_inventory_alerts',
			'Inventory Alerts',
			function (): void {
				echo '<p>Configure read-only low-stock warnings for products aggregated across active bucket positions.</p>';
			},
			self::SETTINGS_PAGE_SLUG
		);

		add_settings_field(
			AIMS_Plugin::OPTION_LOW_STOCK_THRESHOLD,
			'Low Stock Threshold',
			array( $this, 'render_low_stock_threshold_field' ),
			self::SETTINGS_PAGE_SLUG,
			'aims_inventory_alerts'
		);

		add_settings_section(
			'aims_customer_spend_window',
			'Customer Spend Window',
			function (): void {
				echo '<p>Set the default rolling timeframe used when evaluating customer spend.</p>';
			},
			self::SETTINGS_PAGE_SLUG
		);

		add_settings_field(
			AIMS_Plugin::OPTION_CUSTOMER_SPEND_WINDOW_DAYS,
			'Default Rolling Window (days)',
			array( $this, 'render_customer_spend_window_days_field' ),
			self::SETTINGS_PAGE_SLUG,
			'aims_customer_spend_window'
		);

		add_settings_field(
			AIMS_Plugin::OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT,
			'Wholesale Qualification Spend',
			array( $this, 'render_customer_spend_qualify_amount_field' ),
			self::SETTINGS_PAGE_SLUG,
			'aims_customer_spend_window'
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
		$history = array(
			'success' => false,
			'json'    => array(),
		);
		$binary_history = array(
			'success' => false,
			'json'    => array(),
		);
		$availability_query = $this->get_fifo_availability_query();
		$notice             = $this->get_notice();
		$hot_db_health      = $this->hot_db_health_service->get_dashboard_snapshot();
		$low_stock_snapshot = $this->low_stock_alert_service->get_dashboard_snapshot();
		$customer_spend_query = $this->get_customer_spend_query();
		$customer_spend_snapshot = $this->customer_spend_window_service->get_dashboard_snapshot(
			(string) ( $customer_spend_query['lookup'] ?? '' ),
			(int) ( $customer_spend_query['window_days'] ?? AIMS_Plugin::get_customer_spend_window_days() )
		);
		$manifest_sync_gate = $this->square_location_push_policy->get_manifest_sync_gate();

		if ( $client->is_configured() && '' !== (string) ( $availability_query['sku'] ?? '' ) ) {
			$availability = $client->get_fifo_availability( $availability_query );
		}

		if ( $client->is_configured() ) {
			$history = $client->get_history(
				array(
					'source' => 'vault',
					'limit'  => 5,
				)
			);
			$binary_history = $client->get_history(
				array(
					'source' => 'binary',
					'limit'  => 5,
				)
			);
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
		echo '<h2>Hot Data Pressure</h2>';
		$this->render_hot_db_health( $hot_db_health );
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Low Stock Alerts</h2>';
		$this->render_low_stock_alerts( $low_stock_snapshot );
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Customer Spend Window</h2>';
		$this->render_customer_spend_window_panel( $customer_spend_snapshot, $customer_spend_query );
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Binary Shadow Status</h2>';
		$this->render_binary_shadow_status( $binary_history );
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
		$this->render_remote_manifest_sync_form( $manifest_sync_gate );
		echo '</div>';

		echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
		echo '<h2>Cold Storage</h2>';
		$this->render_remote_archive_form();
		$this->render_cold_storage_status( $history );
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

	public function render_low_stock_threshold_field(): void {
		printf(
			'<input type="number" min="0" step="1" class="small-text" name="%1$s" value="%2$s" /> <span class="description">Alert when available quantity is less than or equal to this value.</span>',
			esc_attr( AIMS_Plugin::OPTION_LOW_STOCK_THRESHOLD ),
			esc_attr( (string) AIMS_Plugin::get_low_stock_threshold() )
		);
	}

	public function render_customer_spend_window_days_field(): void {
		printf(
			'<input type="number" min="1" step="1" class="small-text" name="%1$s" value="%2$s" /> <span class="description">Used as the default window for customer spend lookups.</span>',
			esc_attr( AIMS_Plugin::OPTION_CUSTOMER_SPEND_WINDOW_DAYS ),
			esc_attr( (string) AIMS_Plugin::get_customer_spend_window_days() )
		);
	}

	public function render_customer_spend_qualify_amount_field(): void {
		printf(
			'<input type="number" min="0" step="0.01" class="small-text" name="%1$s" value="%2$s" /> <span class="description">If customer spend in the rolling window is at least this amount, admin can one-click designate wholesale.</span>',
			esc_attr( AIMS_Plugin::OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT ),
			esc_attr( number_format( AIMS_Plugin::get_customer_spend_qualify_amount(), 2, '.', '' ) )
		);
	}

	public function handle_designate_wholesale_customer(): void {
		$this->require_capability();
		check_admin_referer( 'aims_designate_wholesale_customer' );

		$customer_id = absint( $_POST['customer_id'] ?? 0 );
		$lookup      = $this->sanitize_request_string( $_POST['customer_lookup'] ?? '' );
		$window_days = AIMS_Plugin::sanitize_customer_spend_window_days( (string) wp_unslash( $_POST['window_days'] ?? AIMS_Plugin::get_customer_spend_window_days() ) );

		if ( $customer_id <= 0 ) {
			$this->redirect_back( array(
				self::NOTICE_QUERY_ARG => 'wholesale_designation_failed',
				'aims_customer_lookup' => $lookup,
				'aims_customer_window_days' => $window_days,
			) );
		}

		$snapshot = $this->customer_spend_window_service->get_dashboard_snapshot( $lookup, $window_days );
		$total_spend = (float) ( $snapshot['total_spend'] ?? 0 );
		$threshold   = AIMS_Plugin::get_customer_spend_qualify_amount();
		$qualifies   = $threshold <= 0 || $total_spend >= $threshold;

		if ( ! $qualifies ) {
			$this->record_audit_event( 'customer_wholesale_designation', (string) $customer_id, false );
			$this->redirect_back( array(
				self::NOTICE_QUERY_ARG => 'wholesale_designation_below_threshold',
				'aims_customer_lookup' => $lookup,
				'aims_customer_window_days' => $window_days,
			) );
		}

		$current_contract = $this->wholesale_contract_service->get_contract( $customer_id );
		$this->wholesale_contract_service->save_contract_from_profile(
			$customer_id,
			array(
				AIMS_Wholesale_Contract_Service::META_ENABLED => '1',
				AIMS_Wholesale_Contract_Service::META_ELEVATED_CUSTOMER => ! empty( $current_contract['elevated_customer'] ) ? '1' : '0',
				AIMS_Wholesale_Contract_Service::META_LEAD_TIME_DAYS => (int) ( $current_contract['lead_time_days'] ?? 7 ),
				AIMS_Wholesale_Contract_Service::META_MIN_ORDER_QTY => (int) ( $current_contract['min_order_qty'] ?? 1 ),
				AIMS_Wholesale_Contract_Service::META_TIER_RATES => (string) get_user_meta( $customer_id, AIMS_Wholesale_Contract_Service::META_TIER_RATES, true ),
				AIMS_Wholesale_Contract_Service::META_PAYMENT_TERMS => (string) ( $current_contract['payment_terms'] ?? '' ),
				AIMS_Wholesale_Contract_Service::META_SHIPPING_WINDOW => (string) ( $current_contract['shipping_window'] ?? '' ),
				AIMS_Wholesale_Contract_Service::META_CONTRACT_NOTES => (string) ( $current_contract['contract_notes'] ?? '' ),
			)
		);

		$this->record_audit_event( 'customer_wholesale_designation', (string) $customer_id, true );
		$this->redirect_back( array(
			self::NOTICE_QUERY_ARG => 'wholesale_designated',
			'aims_customer_lookup' => $lookup,
			'aims_customer_window_days' => $window_days,
		) );
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
			'square_location_id' => $this->sanitize_request_string( $_POST['square_location_id'] ?? '' ),
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
			'square_location_id' => $this->sanitize_request_string( $_POST['square_location_id'] ?? '' ),
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

		$gate = $this->square_location_push_policy->get_manifest_sync_gate();
		if ( empty( $gate['allowed'] ) ) {
			$this->record_audit_event( 'manifest_sync', 'manifest', false );
			$this->redirect_back( array(
				self::NOTICE_QUERY_ARG => 'manifest_sync_locked',
			) );
		}

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
		echo '<tr><th scope="row"><label for="aims-sku">SKU</label></th><td><input id="aims-sku" type="text" class="regular-text" name="sku" required autocomplete="off" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-from-location">From Location</label></th><td><input id="aims-from-location" type="text" class="regular-text" name="from_location" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-to-location">To Location</label></th><td><input id="aims-to-location" type="text" class="regular-text" name="to_location" required /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-quantity">Quantity</label></th><td><input id="aims-quantity" type="number" min="1" step="1" name="quantity" value="1" required /></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Send Movement to AIMS Core' );
		echo '</form>';

		// Add AJAX autocomplete for SKU
		add_action('admin_footer', function() {
			if (!function_exists('wc_get_products')) return;
			?>
			<script>
			jQuery(function($){
				$('#aims-sku').autocomplete({
					source: function(request, response) {
						$.ajax({
							url: ajaxurl,
							dataType: 'json',
							data: {
								action: 'aims_sku_autocomplete',
								term: request.term
							},
							success: function(data) {
								response(data);
							}
						});
					},
					minLength: 2
				});
			});
			</script>
			<?php
		});
	}
// AJAX handler for SKU autocomplete
add_action('wp_ajax_aims_sku_autocomplete', function() {
	if (!function_exists('wc_get_products')) {
		wp_send_json([]);
	}
	$term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
	$products = wc_get_products([
		'sku' => $term,
		'limit' => 20,
		'status' => 'publish',
		'orderby' => 'title',
		'order' => 'ASC',
		'search' => $term,
	]);
	$results = [];
	foreach ($products as $product) {
		$results[] = [
			'label' => $product->get_sku() . ' - ' . $product->get_name(),
			'value' => $product->get_sku(),
		];
	}
	wp_send_json($results);
});

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
		   // Square Location ID select for remote bucket form
		   $vendor_service = class_exists('AIMS_Vendor_User_Metadata_Service') ? new AIMS_Vendor_User_Metadata_Service() : null;
		   $vendor_ids = $vendor_service ? $vendor_service->get_all_vendors('active') : array();
		   $location_options = array();
		   if ($vendor_service) {
			   foreach ($vendor_ids as $vid) {
				   $loc_id = $vendor_service->get_square_location_id($vid);
				   if ($loc_id !== '') {
					   $vendor_name = $vendor_service->get_vendor_name($vid);
					   $location_options[$loc_id] = $vendor_name . ' (' . $loc_id . ')';
				   }
			   }
		   }
		   echo '<tr><th scope="row"><label for="aims-bucket-square-location-id">Square Location ID</label></th><td><select id="aims-bucket-square-location-id" name="square_location_id" class="regular-text">';
		   echo '<option value="">Select a vendor location...</option>';
		   foreach ($location_options as $loc_id => $label) {
			   echo '<option value="' . esc_attr($loc_id) . '">' . esc_html($label) . '</option>';
		   }
		   echo '</select></td></tr>';
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

		echo '<table class="widefat striped"><thead><tr><th>Bucket</th><th>Type</th><th>Show</th><th>Square Location</th><th>Location</th><th>Custody</th><th>On Hand</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['bucket_code'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['bucket_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['show_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['square_location_id'] ?? '' ) ) . '</td>';
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
		   // Square Location ID select for FIFO availability form
		   $vendor_service = class_exists('AIMS_Vendor_User_Metadata_Service') ? new AIMS_Vendor_User_Metadata_Service() : null;
		   $vendor_ids = $vendor_service ? $vendor_service->get_all_vendors('active') : array();
		   $location_options = array();
		   if ($vendor_service) {
			   foreach ($vendor_ids as $vid) {
				   $loc_id = $vendor_service->get_square_location_id($vid);
				   if ($loc_id !== '') {
					   $vendor_name = $vendor_service->get_vendor_name($vid);
					   $location_options[$loc_id] = $vendor_name . ' (' . $loc_id . ')';
				   }
			   }
		   }
		   $selected_loc = (string) ( $query['square_location_id'] ?? '' );
		   echo '<tr><th scope="row"><label for="aims-fifo-square-location-id">Square Location ID</label></th><td><select id="aims-fifo-square-location-id" name="aims_fifo_square_location_id" class="regular-text">';
		   echo '<option value="">Select a vendor location...</option>';
		   foreach ($location_options as $loc_id => $label) {
			   $selected = ($loc_id === $selected_loc) ? ' selected' : '';
			   echo '<option value="' . esc_attr($loc_id) . '"' . $selected . '>' . esc_html($label) . '</option>';
		   }
		   echo '</select></td></tr>';
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

		echo '<table class="widefat striped"><thead><tr><th>Lot</th><th>Bucket</th><th>Show</th><th>Square Location</th><th>Remaining</th><th>Unit Cost</th><th>Received</th><th>Location</th><th>Custody</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['lot_uuid'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['bucket_code'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['show_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['square_location_id'] ?? '' ) ) . '</td>';
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
		   // Square Location ID select for FIFO pick form
		   $vendor_service = class_exists('AIMS_Vendor_User_Metadata_Service') ? new AIMS_Vendor_User_Metadata_Service() : null;
		   $vendor_ids = $vendor_service ? $vendor_service->get_all_vendors('active') : array();
		   $location_options = array();
		   if ($vendor_service) {
			   foreach ($vendor_ids as $vid) {
				   $loc_id = $vendor_service->get_square_location_id($vid);
				   if ($loc_id !== '') {
					   $vendor_name = $vendor_service->get_vendor_name($vid);
					   $location_options[$loc_id] = $vendor_name . ' (' . $loc_id . ')';
				   }
			   }
		   }
		   echo '<tr><th scope="row"><label for="aims-pick-square-location-id">Square Location ID</label></th><td><select id="aims-pick-square-location-id" name="square_location_id" class="regular-text">';
		   echo '<option value="">Select a vendor location...</option>';
		   foreach ($location_options as $loc_id => $label) {
			   echo '<option value="' . esc_attr($loc_id) . '">' . esc_html($label) . '</option>';
		   }
		   echo '</select></td></tr>';
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

	private function render_remote_manifest_sync_form( array $gate ): void {
		$active_events = is_array( $gate['active_events'] ?? null ) ? $gate['active_events'] : array();
		echo '<p>Square inventory pushes stay manual on purpose. Use this after planning, not during a live show window. Done well, pre-planning here is what saves roughly 45 minutes on the loading dock.</p>';
		echo '<p>' . esc_html( (string) ( $gate['message'] ?? '' ) ) . '</p>';

		if ( ! empty( $active_events ) ) {
			$labels = array_map(
				static function ( array $event ): string {
					$label = (string) ( $event['event_name'] ?? 'Event' );
					$date  = (string) ( $event['start_date'] ?? '' );
					$end   = (string) ( $event['end_date'] ?? '' );
					if ( '' !== $date && '' !== $end && $date !== $end ) {
						return $label . ' (' . $date . ' to ' . $end . ')';
					}
					if ( '' !== $date ) {
						return $label . ' (' . $date . ')';
					}

					return $label;
				},
				$active_events
			);

			echo '<p><strong>Live event window:</strong> ' . esc_html( implode( ', ', $labels ) ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aims_sync_remote_manifest" />';
		wp_nonce_field( 'aims_sync_remote_manifest' );
		if ( ! empty( $gate['allowed'] ) ) {
			submit_button( 'Sync Manifest', 'primary' );
		} else {
			echo '<p><button type="submit" class="button button-primary" disabled="disabled" aria-disabled="true">Sync Manifest</button></p>';
		}
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

	private function render_cold_storage_status( array $history ): void {
		echo '<div style="margin-top:16px;">';
		echo '<h3>Recent Cold Archive Windows</h3>';

		if ( empty( $history['success'] ) ) {
			echo '<p><strong>Archive visibility:</strong> ' . esc_html( (string) ( $history['message'] ?? 'Archive history is not currently available from AIMS Core.' ) ) . '</p>';
			echo '</div>';
			return;
		}

		$meta      = $this->extract_history_meta( $history );
		$manifests = is_array( $meta['archive_manifests'] ?? null ) ? $meta['archive_manifests'] : array();

		if ( empty( $manifests ) ) {
			echo '<p>No archive manifests are visible yet. Once hot rows are snapped to Parquet, the latest archive windows will show up here.</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:860px;"><thead><tr><th>Show</th><th>Window</th><th>Rows</th><th>Segments</th></tr></thead><tbody>';
		foreach ( $manifests as $manifest ) {
			if ( ! is_array( $manifest ) ) {
				continue;
			}

			$show_id       = (string) ( $manifest['show_id'] ?? 'n/a' );
			$active_from   = (string) ( $manifest['active_from'] ?? '' );
			$active_to     = (string) ( $manifest['active_to'] ?? '' );
			$row_count     = (int) ( $manifest['row_count'] ?? 0 );
			$segment_count = (int) ( $manifest['segment_count'] ?? 0 );
			$window_label  = '' !== $active_from || '' !== $active_to
				? trim( $active_from . ' to ' . $active_to )
				: 'Unknown range';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $show_id ) . '</strong></td>';
			echo '<td>' . esc_html( $window_label ) . '</td>';
			echo '<td>' . esc_html( number_format( $row_count ) ) . ' row(s)</td>';
			echo '<td>' . esc_html( number_format( $segment_count ) ) . ' segment(s)</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_binary_shadow_status( array $history ): void {
		if ( empty( $history['success'] ) ) {
			echo '<p><strong>Binary shadow:</strong> ' . esc_html( (string) ( $history['message'] ?? 'Binary shadow telemetry is not currently available from AIMS Core.' ) ) . '</p>';
			return;
		}

		$meta   = $this->extract_history_meta( $history );
		$rows   = $this->extract_history_rows( $history );
		$shadow = is_array( $meta['binary_shadow'] ?? null ) ? $meta['binary_shadow'] : array();

		if ( empty( $shadow ) ) {
			echo '<p>No binary shadow packets are visible yet. Once sale-side shadow writes land, the packet counters and exception lane will appear here.</p>';
			return;
		}

		$pointerCount   = (int) ( $shadow['pointer_count'] ?? 0 );
		$exceptionCount = (int) ( $shadow['exception_count'] ?? 0 );
		$segmentCount   = (int) ( $shadow['segment_count'] ?? 0 );
		$activeFrom     = (string) ( $shadow['active_from'] ?? '' );
		$activeTo       = (string) ( $shadow['active_to'] ?? '' );
		$segments       = is_array( $shadow['segments'] ?? null ) ? $shadow['segments'] : array();

		echo '<p><strong>Pointers:</strong> ' . esc_html( number_format( $pointerCount ) ) . ' pointer(s)<br />';
		echo '<strong>Exceptions:</strong> ' . esc_html( number_format( $exceptionCount ) ) . ' exception(s)<br />';
		echo '<strong>Segments:</strong> ' . esc_html( number_format( $segmentCount ) ) . ' segment(s)</p>';

		if ( '' !== $activeFrom || '' !== $activeTo ) {
			echo '<p><strong>Observed Window:</strong> ' . esc_html( trim( $activeFrom . ' to ' . $activeTo ) ) . '</p>';
		}

		if ( ! empty( $segments ) ) {
			echo '<p><strong>Active Segment Files:</strong> ' . esc_html( implode( ', ', array_map( 'strval', $segments ) ) ) . '</p>';
		}

		if ( empty( $rows ) ) {
			echo '<p>No rehydrated packet rows matched the current lookup window.</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:920px;"><thead><tr><th>Reference</th><th>SKU</th><th>Price</th><th>Tax</th><th>Event</th><th>Offset</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['reference_id'] ?? 'n/a' ) ) . '</strong><br /><span style="color:#646970;">' . esc_html( (string) ( $row['reference_type'] ?? '' ) ) . '</span></td>';
			echo '<td>' . esc_html( (string) ( $row['sku'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (int) ( $row['price_cents'] ?? 0 ) ) ) . '¢</td>';
			echo '<td>' . esc_html( number_format( (int) ( $row['tax_cents'] ?? 0 ) ) ) . '¢</td>';
			echo '<td>' . esc_html( number_format( (int) ( $row['event_id'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (int) ( $row['byte_offset'] ?? 0 ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_hot_db_health( array $snapshot ): void {
		$band_label    = (string) ( $snapshot['band_label'] ?? 'Green' );
		$band_color    = (string) ( $snapshot['band_color'] ?? '#2e7d32' );
		$usage_percent = max( 0, min( 100, (int) ( $snapshot['usage_percent'] ?? 0 ) ) );
		$total_rows    = (int) ( $snapshot['total_hot_rows'] ?? 0 );
		$target        = (int) ( $snapshot['capacity_target'] ?? 250000 );
		$order_guess   = (int) ( $snapshot['estimated_order_equivalent'] ?? 0 );
		$counts        = is_array( $snapshot['counts'] ?? null ) ? $snapshot['counts'] : array();
		$message       = (string) ( $snapshot['message'] ?? '' );
		$thresholds    = is_array( $snapshot['thresholds'] ?? null ) ? $snapshot['thresholds'] : array();
		$green_limit   = (int) ( $thresholds['green'] ?? 100000 );
		$yellow_limit  = (int) ( $thresholds['yellow'] ?? 250000 );

		echo '<p>This is the real impact view for the hot AIMS tables living beside WordPress. AIMS is meant to tell you what happened, where it happened, and what moved physically or financially, not to hide when the stack is nearing ERP territory.</p>';
		echo '<div style="display:flex;align-items:center;gap:12px;margin:12px 0;">';
		echo '<span aria-hidden="true" style="display:inline-block;width:16px;height:16px;border-radius:50%;background:' . esc_attr( $band_color ) . ';box-shadow:0 0 0 3px rgba(0,0,0,0.06);"></span>';
		echo '<strong>' . esc_html( $band_label ) . ' Band</strong>';
		echo '<span>' . esc_html( $usage_percent ) . '% of hot-row target</span>';
		echo '</div>';
		echo '<div style="height:14px;background:#e5e7eb;border-radius:999px;overflow:hidden;max-width:720px;">';
		echo '<div style="height:14px;width:' . esc_attr( (string) $usage_percent ) . '%;background:' . esc_attr( $band_color ) . ';"></div>';
		echo '</div>';
		echo '<p style="margin-top:12px;">';
		echo '<strong>Hot Rows:</strong> ' . esc_html( number_format( $total_rows ) );
		echo ' <span style="color:#646970;">/ ' . esc_html( number_format( $target ) ) . ' target</span><br />';
		echo '<strong>Estimated Order Equivalent:</strong> ' . esc_html( number_format( $order_guess ) );
		echo ' <span style="color:#646970;">(based on roughly 4 sale lines per order)</span>';
		echo '</p>';
		echo '<p><strong>Comfort Bands:</strong> Green under ' . esc_html( number_format( $green_limit ) ) . ', Yellow from ' . esc_html( number_format( $green_limit ) ) . ' to under ' . esc_html( number_format( $yellow_limit ) ) . ', Red at ' . esc_html( number_format( $yellow_limit ) ) . ' and above.</p>';

		echo '<table class="widefat striped" style="max-width:720px;"><thead><tr><th>Hot Table</th><th>Rows</th></tr></thead><tbody>';
		echo '<tr><td>Square sale lines</td><td>' . esc_html( number_format( (int) ( $counts['square_sales'] ?? 0 ) ) ) . '</td></tr>';
		echo '<tr><td>Bucket custody movements</td><td>' . esc_html( number_format( (int) ( $counts['bucket_inventory_moves'] ?? 0 ) ) ) . '</td></tr>';
		echo '<tr><td>Fulfillment allocations</td><td>' . esc_html( number_format( (int) ( $counts['fulfillment_allocations'] ?? 0 ) ) ) . '</td></tr>';
		echo '<tr><td>Inventory movements</td><td>' . esc_html( number_format( (int) ( $counts['inventory_movements'] ?? 0 ) ) ) . '</td></tr>';
		echo '</tbody></table>';

		if ( '' !== $message ) {
			echo '<p style="margin-top:12px;">' . esc_html( $message ) . '</p>';
		}
	}

	private function render_low_stock_alerts( array $snapshot ): void {
		$threshold      = (int) ( $snapshot['threshold'] ?? AIMS_Plugin::get_low_stock_threshold() );
		$tracked        = (int) ( $snapshot['tracked_products'] ?? 0 );
		$low_stock      = (int) ( $snapshot['low_stock_products'] ?? 0 );
		$active_rows    = (int) ( $snapshot['active_positions'] ?? 0 );
		$alerts         = is_array( $snapshot['alerts'] ?? null ) ? $snapshot['alerts'] : array();

		echo '<p>Alerts are read-only and based on active bucket position availability (`quantity - reserved_quantity`) aggregated by product.</p>';
		echo '<p><strong>Threshold:</strong> ' . esc_html( number_format( $threshold ) ) . ' | <strong>Tracked Products:</strong> ' . esc_html( number_format( $tracked ) ) . ' | <strong>Active Positions:</strong> ' . esc_html( number_format( $active_rows ) ) . '</p>';

		if ( $low_stock <= 0 ) {
			echo '<p><strong>No low-stock products detected.</strong></p>';
			return;
		}

		echo '<p><strong>' . esc_html( number_format( $low_stock ) ) . ' product(s) are at or below threshold.</strong></p>';
		echo '<table class="widefat striped" style="max-width:960px;"><thead><tr><th>Product</th><th>Available</th><th>Total</th><th>Reserved</th><th>Buckets</th><th>Vendors</th><th>Status</th></tr></thead><tbody>';
		foreach ( $alerts as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$status_label = 'low' === (string) ( $item['status'] ?? '' ) ? 'Low' : 'Out';
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $item['product_name'] ?? '' ) ) . ' <span style="color:#646970;">#' . esc_html( (string) ( $item['product_id'] ?? 0 ) ) . '</span></td>';
			echo '<td><strong>' . esc_html( number_format( (float) ( $item['available_quantity'] ?? 0 ), 4 ) ) . '</strong></td>';
			echo '<td>' . esc_html( number_format( (float) ( $item['total_quantity'] ?? 0 ), 4 ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) ( $item['reserved_quantity'] ?? 0 ), 4 ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (int) ( $item['bucket_count'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (int) ( $item['vendor_count'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_customer_spend_window_panel( array $snapshot, array $query ): void {
		$lookup      = (string) ( $query['lookup'] ?? '' );
		$window_days = (int) ( $query['window_days'] ?? AIMS_Plugin::get_customer_spend_window_days() );
		$threshold   = AIMS_Plugin::get_customer_spend_qualify_amount();

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-customer-spend-lookup">Customer</label></th><td><input id="aims-customer-spend-lookup" type="text" class="regular-text" name="aims_customer_lookup" value="' . esc_attr( $lookup ) . '" placeholder="ID, email, or username" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-customer-spend-window-days">Window (days)</label></th><td><input id="aims-customer-spend-window-days" type="number" min="1" step="1" name="aims_customer_window_days" value="' . esc_attr( (string) $window_days ) . '" /></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Evaluate Customer Spend', 'secondary', '', false );
		echo '</form>';

		if ( empty( $snapshot['resolved'] ) ) {
			echo '<p>' . esc_html( (string) ( $snapshot['message'] ?? 'Provide a customer lookup to evaluate spend.' ) ) . '</p>';
			return;
		}

		$customer    = is_array( $snapshot['customer'] ?? null ) ? $snapshot['customer'] : array();
		$total_spend = (float) ( $snapshot['total_spend'] ?? 0 );
		$order_count = (int) ( $snapshot['order_count'] ?? 0 );
		$orders      = is_array( $snapshot['orders'] ?? null ) ? $snapshot['orders'] : array();
		$qualifies   = $threshold <= 0 || $total_spend >= $threshold;
		$already_wholesale = $this->wholesale_contract_service->is_wholesale_customer( (int) ( $customer['id'] ?? 0 ) );

		echo '<p><strong>Customer:</strong> ' . esc_html( (string) ( $customer['display_name'] ?? 'Unknown' ) ) . ' <span style="color:#646970;">#' . esc_html( (string) ( $customer['id'] ?? 0 ) ) . ' • ' . esc_html( (string) ( $customer['email'] ?? '' ) ) . '</span></p>';
		echo '<p><strong>Rolling Window:</strong> ' . esc_html( number_format( $window_days ) ) . ' day(s) | <strong>Spend:</strong> ' . esc_html( number_format( $total_spend, 2 ) ) . ' | <strong>Orders:</strong> ' . esc_html( number_format( $order_count ) ) . '</p>';
		echo '<p><strong>Qualification Threshold:</strong> ' . esc_html( number_format( $threshold, 2 ) ) . ' | <strong>Status:</strong> ' . esc_html( $qualifies ? 'Qualifies' : 'Below threshold' ) . ' | <strong>Wholesale:</strong> ' . esc_html( $already_wholesale ? 'Enabled' : 'Not enabled' ) . '</p>';

		if ( ! $already_wholesale && $qualifies ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:12px;">';
			echo '<input type="hidden" name="action" value="aims_designate_wholesale_customer" />';
			echo '<input type="hidden" name="customer_id" value="' . esc_attr( (string) ( (int) ( $customer['id'] ?? 0 ) ) ) . '" />';
			echo '<input type="hidden" name="customer_lookup" value="' . esc_attr( $lookup ) . '" />';
			echo '<input type="hidden" name="window_days" value="' . esc_attr( (string) $window_days ) . '" />';
			wp_nonce_field( 'aims_designate_wholesale_customer' );
			submit_button( 'Designate as Wholesale', 'primary', '', false );
			echo '</form>';
		}

		if ( empty( $orders ) ) {
			echo '<p>No qualifying WooCommerce orders found for this window.</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:920px;"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody>';
		foreach ( $orders as $order_row ) {
			if ( ! is_array( $order_row ) ) {
				continue;
			}

			echo '<tr>';
			echo '<td>#' . esc_html( (string) ( (int) ( $order_row['order_id'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $order_row['date'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $order_row['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) ( $order_row['total'] ?? 0 ), 2 ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function extract_history_meta( array $history ): array {
		$json = is_array( $history['json'] ?? null ) ? $history['json'] : array();
		$meta = is_array( $json['meta'] ?? null ) ? $json['meta'] : array();

		return array_merge( $json, $meta );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_history_rows( array $history ): array {
		$json = is_array( $history['json'] ?? null ) ? $history['json'] : array();
		$rows = $json['rows'] ?? array();

		return is_array( $rows ) ? $rows : array();
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
			'manifest_sync_locked' => array( 'warning', 'Manifest sync is locked during a live show window. Push Square inventory before the show starts or after it ends.' ),
			'manifest_sync_failed' => array( 'error', 'Manifest sync failed.' ),
			'archive_started'=> array( 'success', 'Archive request sent.' ),
			'archive_failed' => array( 'error', 'Archive request failed.' ),
			'wholesale_designated' => array( 'success', 'Customer designated as wholesale.' ),
			'wholesale_designation_failed' => array( 'error', 'Wholesale designation failed: invalid customer selection.' ),
			'wholesale_designation_below_threshold' => array( 'warning', 'Wholesale designation blocked because customer spend is below the qualification threshold.' ),
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
			'square_location_id' => isset( $_GET['aims_fifo_square_location_id'] ) ? $this->sanitize_request_string( $_GET['aims_fifo_square_location_id'] ) : '',
		);
	}

	private function get_customer_spend_query(): array {
		$window_days = isset( $_GET['aims_customer_window_days'] )
			? AIMS_Plugin::sanitize_customer_spend_window_days( (string) wp_unslash( $_GET['aims_customer_window_days'] ) )
			: AIMS_Plugin::get_customer_spend_window_days();

		return array(
			'lookup'      => isset( $_GET['aims_customer_lookup'] ) ? $this->sanitize_request_string( $_GET['aims_customer_lookup'] ) : '',
			'window_days' => $window_days,
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
