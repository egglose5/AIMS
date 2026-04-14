<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Plugin {
	const OPTION_SCHEMA_VERSION               = 'aims_schema_version';
	const OPTION_INSTALLED_AT                 = 'aims_installed_at';
	const OPTION_API_URL                      = 'aims_api_url';
	const OPTION_API_TOKEN                    = 'aims_api_token';
	const OPTION_LOW_STOCK_THRESHOLD          = 'aims_low_stock_threshold';
	const OPTION_CUSTOMER_SPEND_WINDOW_DAYS   = 'aims_customer_spend_window_days';
	const OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT = 'aims_customer_spend_qualify_amount';
	const SCHEMA_VERSION                      = '0.16.0';

	private static $instance = null;

	private $admin_menu;
	private $square_thin_client_sync;
	private $laser_batch_rest_controller;
	private $hot_db_archive_monitor;
	private $cycle_count_controller;
	private $wholesale_customer_portal_controller;
	private $integration_rest_controller;
	private $barcode_scanner;
	private $event_module;
	private $vendor_module;
	private $stitch_module;
	private $workflow_surface_registry;

	public static function instance(): AIMS_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		self::ensure_default_options();
		self::maybe_install_schema();
		AIMS_Capabilities::register_roles_and_caps();

		$errors = array();

		if ( ! extension_loaded( 'pdo' ) || ! extension_loaded( 'pdo_sqlite' ) ) {
			$errors[] = __( 'AIMS requires the PDO and PDO_SQLITE PHP extensions.', 'ai-man-sys' );
		}

		$core_dir = AIMS_PLUGIN_PATH . 'ames-core/';
		if ( ! is_dir( $core_dir ) ) {
			$errors[] = __( 'ames-core directory is missing from the plugin. Please upload all plugin files.', 'ai-man-sys' );
		} else {
			foreach ( array( 'sink', 'vault', 'logs', 'config' ) as $subdir ) {
				$path = $core_dir . $subdir . '/';

				if ( ! is_dir( $path ) && ! mkdir( $path, 0755, true ) ) {
					$errors[] = sprintf( __( 'Failed to create directory: %s', 'ai-man-sys' ), $path );
					continue;
				}

				if ( ! is_writable( $path ) && ! @chmod( $path, 0755 ) ) {
					$errors[] = sprintf( __( 'Directory not writable: %s', 'ai-man-sys' ), $path );
				}
			}

			$env_example = $core_dir . '.env.example';
			$env_file    = $core_dir . '.env';

			if ( file_exists( $env_example ) && ! file_exists( $env_file ) ) {
				if ( ! copy( $env_example, $env_file ) ) {
					$errors[] = __( 'Failed to copy .env.example to .env in ames-core.', 'ai-man-sys' );
				} else {
					$env          = (string) file_get_contents( $env_file );
					$replacements = array(
						'AIMS_SHARED_SECRET='  => 'AIMS_SHARED_SECRET=' . wp_generate_password( 32, true, true ),
						'AIMS_ARCHIVE_SECRET=' => 'AIMS_ARCHIVE_SECRET=' . wp_generate_password( 32, true, true ),
						'AIMS_ENCRYPTION_KEY=' => 'AIMS_ENCRYPTION_KEY=' . wp_generate_password( 32, true, true ),
					);

					foreach ( $replacements as $needle => $replace ) {
						$env = (string) preg_replace( '/^' . preg_quote( $needle, '/' ) . '.*$/m', $replace, $env );
					}

					file_put_contents( $env_file, $env );
				}
			}

			if ( file_exists( $env_file ) ) {
				$env = (string) file_get_contents( $env_file );

				if ( '' === (string) get_option( self::OPTION_API_URL, '' ) ) {
					update_option( self::OPTION_API_URL, site_url( '/ames-core' ), false );
				}

				if ( preg_match( '/^AIMS_SHARED_SECRET=(.*)$/m', $env, $matches ) && '' === (string) get_option( self::OPTION_API_TOKEN, '' ) ) {
					update_option( self::OPTION_API_TOKEN, trim( (string) $matches[1] ), false );
				}
			}
		}

		if ( ! empty( $errors ) ) {
			update_option( 'aims_activation_errors', $errors );
		} else {
			delete_option( 'aims_activation_errors' );
		}

		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			$mask = ( defined( 'EP_ROOT' ) ? (int) EP_ROOT : 0 ) | ( defined( 'EP_PAGES' ) ? (int) EP_PAGES : 0 );
			add_rewrite_endpoint( 'aims-wholesale', $mask > 0 ? $mask : 1 );
		}

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	public static function uninstall(): void {
		self::cleanup_options();
		AIMS_Capabilities::cleanup();
	}

	public static function get_option_keys(): array {
		return array(
			self::OPTION_SCHEMA_VERSION,
			self::OPTION_INSTALLED_AT,
			self::OPTION_API_URL,
			self::OPTION_API_TOKEN,
			self::OPTION_LOW_STOCK_THRESHOLD,
			self::OPTION_CUSTOMER_SPEND_WINDOW_DAYS,
			self::OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT,
		);
	}

	public static function ensure_default_options(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( '' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) ) {
			update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
		}

		if ( '' === (string) get_option( self::OPTION_INSTALLED_AT, '' ) ) {
			update_option( self::OPTION_INSTALLED_AT, current_time( 'mysql' ), false );
		}

		if ( false === get_option( self::OPTION_API_URL, false ) ) {
			update_option( self::OPTION_API_URL, '', false );
		}

		if ( false === get_option( self::OPTION_API_TOKEN, false ) ) {
			update_option( self::OPTION_API_TOKEN, '', false );
		}

		if ( false === get_option( self::OPTION_LOW_STOCK_THRESHOLD, false ) ) {
			update_option( self::OPTION_LOW_STOCK_THRESHOLD, 5, false );
		}

		if ( false === get_option( self::OPTION_CUSTOMER_SPEND_WINDOW_DAYS, false ) ) {
			update_option( self::OPTION_CUSTOMER_SPEND_WINDOW_DAYS, 30, false );
		}

		if ( false === get_option( self::OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT, false ) ) {
			update_option( self::OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT, 0, false );
		}
	}

	public static function cleanup_options(): void {
		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}

		foreach ( self::get_option_keys() as $option_name ) {
			delete_option( $option_name );
		}
	}

	public static function get_api_url(): string {
		return self::sanitize_api_url( (string) get_option( self::OPTION_API_URL, '' ) );
	}

	public static function get_api_token(): string {
		return trim( (string) get_option( self::OPTION_API_TOKEN, '' ) );
	}

	public static function get_headless_config(): array {
		return array(
			'api_url'   => self::get_api_url(),
			'api_token' => self::get_api_token(),
		);
	}

	public static function get_low_stock_threshold(): int {
		return self::sanitize_low_stock_threshold( (string) get_option( self::OPTION_LOW_STOCK_THRESHOLD, '5' ) );
	}

	public static function get_customer_spend_window_days(): int {
		return self::sanitize_customer_spend_window_days( (string) get_option( self::OPTION_CUSTOMER_SPEND_WINDOW_DAYS, '30' ) );
	}

	public static function get_customer_spend_qualify_amount(): float {
		return self::sanitize_customer_spend_qualify_amount( (string) get_option( self::OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT, '0' ) );
	}

	public static function sanitize_api_url( string $value ): string {
		return untrailingslashit( esc_url_raw( trim( $value ) ) );
	}

	public static function sanitize_api_token( string $value ): string {
		return trim( sanitize_text_field( $value ) );
	}

	public static function sanitize_low_stock_threshold( string $value ): int {
		return min( 1000000, absint( $value ) );
	}

	public static function sanitize_customer_spend_window_days( string $value ): int {
		$normalized = absint( $value );
		if ( $normalized <= 0 ) {
			return 30;
		}

		return min( 3650, $normalized );
	}

	public static function sanitize_customer_spend_qualify_amount( string $value ): float {
		$normalized = round( (float) $value, 2 );
		if ( $normalized < 0 ) {
			return 0.0;
		}

		return min( 1000000000.0, $normalized );
	}

	public static function get_public_workflow_definitions(): array {
		if ( ! class_exists( 'AIMS_Workflow_Surface_Registry' ) || ! method_exists( 'AIMS_Workflow_Surface_Registry', 'get_definitions' ) ) {
			return array();
		}

		return AIMS_Workflow_Surface_Registry::get_definitions();
	}

	private function __construct() {
		$this->admin_menu                           = new AIMS_Admin_Menu();
		$this->square_thin_client_sync              = new AIMS_Square_Thin_Client_Sync_Service();
		$this->laser_batch_rest_controller          = new AIMS_Laser_Batch_Rest_Controller();
		$this->hot_db_archive_monitor               = new AIMS_Hot_Db_Archive_Monitor_Service();
		$this->cycle_count_controller               = new AIMS_Cycle_Count_Controller();
		$this->wholesale_customer_portal_controller = new AIMS_Wholesale_Customer_Portal_Controller();
		$this->integration_rest_controller          = new AIMS_Integration_Rest_Controller();
		$this->barcode_scanner                      = new AIMS_Barcode_Scanner();
		$this->event_module                         = class_exists( 'AIMS_Event_Module' ) ? new AIMS_Event_Module() : null;
		$this->vendor_module                        = class_exists( 'AIMS_Vendor_Module' ) && class_exists( 'AIMS_Vendor_Service' )
			? new AIMS_Vendor_Module( new AIMS_Vendor_Service() )
			: null;
		$this->stitch_module                        = class_exists( 'AIMS_Stitch_Module' ) ? new AIMS_Stitch_Module() : null;
		$this->workflow_surface_registry            = class_exists( 'AIMS_Workflow_Surface_Registry' ) ? new AIMS_Workflow_Surface_Registry() : null;
	}

	public function boot(): void {
		self::maybe_install_schema();

		if ( class_exists( 'AIMS_Product_Cost_Woo_Cogs_Sync_Service' ) ) {
			AIMS_Product_Cost_Woo_Cogs_Sync_Service::register_hooks();
		}

		add_action( 'admin_menu', array( $this->admin_menu, 'register' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		$this->boot_component( $this->square_thin_client_sync, 'boot' );
		$this->boot_component( $this->laser_batch_rest_controller, 'register' );
		$this->boot_component( $this->hot_db_archive_monitor, 'boot' );
		$this->boot_component( $this->cycle_count_controller, 'register' );
		$this->boot_component( $this->wholesale_customer_portal_controller, 'register' );
		$this->boot_component( $this->integration_rest_controller, 'register' );
		$this->boot_component( $this->event_module, 'register' );
		$this->boot_component( $this->vendor_module, 'register' );
		$this->boot_component( $this->stitch_module, 'register' );
		$this->boot_component( $this->workflow_surface_registry, 'register' );

		add_action( 'rest_api_init', array( $this, 'register_square_webhook_endpoint' ) );
		add_action( 'admin_notices', array( $this, 'render_activation_notices' ) );
	}

	public function register_square_webhook_endpoint(): void {
		register_rest_route(
			'aims/v1',
			'/square/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_square_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_square_webhook( $request ) {
		$payload = is_object( $request ) && method_exists( $request, 'get_json_params' )
			? $request->get_json_params()
			: array();

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array(
					'error' => 'Invalid payload',
				),
				400
			);
		}

		if ( ! class_exists( 'AIMS_Square_Webhook_Intake_Service' ) ) {
			return new WP_REST_Response(
				array(
					'error' => 'Webhook service unavailable',
				),
				500
			);
		}

		$service = new AIMS_Square_Webhook_Intake_Service(
			new AIMS_Square_Import_Queue_Repository(),
			new AIMS_Square_Raw_Event_Service(),
			new AIMS_Square_Normalization_Service()
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'result'  => $service->ingest_order_payload( $payload ),
			),
			200
		);
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-man-sys',
			false,
			dirname( AIMS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	public function render_activation_notices(): void {
		$errors = get_option( 'aims_activation_errors', array() );
		if ( ! is_array( $errors ) || empty( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'AIMS Plugin Setup Issues:', 'ai-man-sys' ) . '</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( (string) $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	private function boot_component( $component, string $method ): void {
		if ( is_object( $component ) && method_exists( $component, $method ) ) {
			$component->{$method}();
		}
	}

	private static function maybe_install_schema(): void {
		if ( ! class_exists( 'AIMS_Installer' ) || ! class_exists( 'AIMS_Schema' ) ) {
			return;
		}

		$upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! file_exists( $upgrade_path ) ) {
			return;
		}

		$installer = new AIMS_Installer( new AIMS_Schema() );
		$installer->maybe_install();
	}
}
