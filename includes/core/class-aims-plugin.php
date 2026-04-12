<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Plugin {
	const OPTION_SCHEMA_VERSION = 'aims_schema_version';
	const OPTION_INSTALLED_AT   = 'aims_installed_at';
	const OPTION_API_URL        = 'aims_api_url';
	const OPTION_API_TOKEN      = 'aims_api_token';
	const OPTION_LOW_STOCK_THRESHOLD = 'aims_low_stock_threshold';
	const OPTION_CUSTOMER_SPEND_WINDOW_DAYS = 'aims_customer_spend_window_days';
	const OPTION_CUSTOMER_SPEND_QUALIFY_AMOUNT = 'aims_customer_spend_qualify_amount';
	const SCHEMA_VERSION        = '0.16.0';

	private static $instance = null;

	private $admin_menu;
	private $square_thin_client_sync;
	private $laser_batch_rest_controller;
	private $hot_db_archive_monitor;
	private $cycle_count_controller;
	private $wholesale_customer_portal_controller;
	private $integration_rest_controller;

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
		// Automated setup for ames-core and runtime dirs
		$errors = array();
		// Check PHP extensions
		if (!extension_loaded('pdo') || !extension_loaded('pdo_sqlite')) {
			$errors[] = __('AIMS requires the PDO and PDO_SQLITE PHP extensions.', 'ai-man-sys');
		}
		// Ensure ames-core exists
		$core_dir = AIMS_PLUGIN_PATH . 'ames-core/';
		if (!is_dir($core_dir)) {
			$errors[] = __('ames-core directory is missing from the plugin. Please upload all plugin files.', 'ai-man-sys');
		} else {
			// Ensure runtime dirs
			foreach (['sink', 'vault', 'logs', 'config'] as $subdir) {
				$path = $core_dir . $subdir . '/';
				if (!is_dir($path)) {
					if (!mkdir($path, 0755, true)) {
						$errors[] = sprintf(__('Failed to create directory: %s', 'ai-man-sys'), $path);
					}
				}
				if (!is_writable($path)) {
					if (!@chmod($path, 0755)) {
						$errors[] = sprintf(__('Directory not writable: %s', 'ai-man-sys'), $path);
					}
				}
			}
			// Copy .env.example to .env if needed
			$env_example = $core_dir . '.env.example';
			$env_file = $core_dir . '.env';
			if (file_exists($env_example) && !file_exists($env_file)) {
				if (!copy($env_example, $env_file)) {
					$errors[] = __('Failed to copy .env.example to .env in ames-core.', 'ai-man-sys');
				} else {
					// Generate secrets if not set
					$env = file_get_contents($env_file);
					$replacements = [
						'AIMS_SHARED_SECRET=' => 'AIMS_SHARED_SECRET=' . wp_generate_password(32, true, true),
						'AIMS_ARCHIVE_SECRET=' => 'AIMS_ARCHIVE_SECRET=' . wp_generate_password(32, true, true),
						'AIMS_ENCRYPTION_KEY=' => 'AIMS_ENCRYPTION_KEY=' . wp_generate_password(32, true, true),
					];
					foreach ($replacements as $needle => $replace) {
						$env = preg_replace('/^' . preg_quote($needle, '/') . '.*$/m', $replace, $env);
					}
					file_put_contents($env_file, $env);
				}
			}
		}
		// Admin notice if errors
		if (!empty($errors)) {
			update_option('aims_activation_errors', $errors);
		} else {
			delete_option('aims_activation_errors');
		}
		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			$mask = ( defined( 'EP_ROOT' ) ? (int) EP_ROOT : 0 ) | ( defined( 'EP_PAGES' ) ? (int) EP_PAGES : 0 );
			add_rewrite_endpoint( 'aims-wholesale', $mask > 0 ? $mask : 1 );
		}
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	// Show admin notice if activation errors exist
	add_action('admin_notices', function() {
		if ($errors = get_option('aims_activation_errors')) {
			echo '<div class="notice notice-error"><p><strong>AIMS Plugin Setup Issues:</strong></p><ul>';
			foreach ($errors as $err) {
				echo '<li>' . esc_html($err) . '</li>';
			}
			echo '</ul></div>';
		}
	});
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
			'api_url'  => self::get_api_url(),
			'api_token'=> self::get_api_token(),
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
		$normalized = absint( $value );

		return min( 1000000, $normalized );
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

	private function __construct() {
		$this->admin_menu                  = new AIMS_Admin_Menu();
		$this->square_thin_client_sync     = new AIMS_Square_Thin_Client_Sync_Service();
		$this->laser_batch_rest_controller = new AIMS_Laser_Batch_Rest_Controller();
		$this->hot_db_archive_monitor      = new AIMS_Hot_Db_Archive_Monitor_Service();
		$this->cycle_count_controller      = new AIMS_Cycle_Count_Controller();
		$this->wholesale_customer_portal_controller = new AIMS_Wholesale_Customer_Portal_Controller();
		$this->integration_rest_controller = new AIMS_Integration_Rest_Controller();
	}

	public function boot(): void {
		self::maybe_install_schema();
		if ( class_exists( 'AIMS_Product_Cost_Woo_Cogs_Sync_Service' ) ) {
			AIMS_Product_Cost_Woo_Cogs_Sync_Service::register_hooks();
		}
		add_action( 'admin_menu', array( $this->admin_menu, 'register' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		if ( is_object( $this->square_thin_client_sync ) && method_exists( $this->square_thin_client_sync, 'boot' ) ) {
			$this->square_thin_client_sync->boot();
		}
		if ( is_object( $this->laser_batch_rest_controller ) && method_exists( $this->laser_batch_rest_controller, 'register' ) ) {
			$this->laser_batch_rest_controller->register();
		}
		if ( is_object( $this->hot_db_archive_monitor ) && method_exists( $this->hot_db_archive_monitor, 'boot' ) ) {
			$this->hot_db_archive_monitor->boot();
		}
		if ( is_object( $this->cycle_count_controller ) && method_exists( $this->cycle_count_controller, 'register' ) ) {
			$this->cycle_count_controller->register();
		}
		if ( is_object( $this->wholesale_customer_portal_controller ) && method_exists( $this->wholesale_customer_portal_controller, 'register' ) ) {
			$this->wholesale_customer_portal_controller->register();
		}
		if ( is_object( $this->integration_rest_controller ) && method_exists( $this->integration_rest_controller, 'register' ) ) {
			$this->integration_rest_controller->register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-man-sys',
			false,
			dirname( AIMS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	private static function maybe_install_schema(): void {
		if ( ! class_exists( 'AIMS_Installer' ) || ! class_exists( 'AIMS_Schema' ) ) {
			return;
		}

		// Tests and non-WordPress contexts can define ABSPATH without core upgrade files.
		$upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! file_exists( $upgrade_path ) ) {
			return;
		}

		$installer = new AIMS_Installer( new AIMS_Schema() );
		$installer->maybe_install();
	}
}
