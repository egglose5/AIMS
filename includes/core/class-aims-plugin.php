<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Plugin {
	const OPTION_SCHEMA_VERSION = 'aims_schema_version';
	const OPTION_INSTALLED_AT   = 'aims_installed_at';
	const OPTION_API_URL        = 'aims_api_url';
	const OPTION_API_TOKEN      = 'aims_api_token';
	const SCHEMA_VERSION        = '0.16.0';

	private static $instance = null;

	private $admin_menu;
	private $square_thin_client_sync;
	private $laser_batch_rest_controller;
	private $hot_db_archive_monitor;
	private $cycle_count_controller;

	public static function instance(): AIMS_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		self::ensure_default_options();
		AIMS_Capabilities::register_roles_and_caps();
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

	public static function sanitize_api_url( string $value ): string {
		return untrailingslashit( esc_url_raw( trim( $value ) ) );
	}

	public static function sanitize_api_token( string $value ): string {
		return trim( sanitize_text_field( $value ) );
	}

	private function __construct() {
		$this->admin_menu                  = new AIMS_Admin_Menu();
		$this->square_thin_client_sync     = new AIMS_Square_Thin_Client_Sync_Service();
		$this->laser_batch_rest_controller = new AIMS_Laser_Batch_Rest_Controller();
		$this->hot_db_archive_monitor      = new AIMS_Hot_Db_Archive_Monitor_Service();
		$this->cycle_count_controller      = new AIMS_Cycle_Count_Controller();
	}

	public function boot(): void {
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
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-man-sys',
			false,
			dirname( AIMS_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
