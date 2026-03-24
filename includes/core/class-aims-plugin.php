<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Plugin {
	const OPTION_SCHEMA_VERSION = 'aims_schema_version';
	const OPTION_INSTALLED_AT   = 'aims_installed_at';
	const SCHEMA_VERSION        = '0.3.7';

	private static $instance = null;

	private $installer;
	private $capabilities;
	private $admin_menu;
	private $vendor_module;
	private $event_module;
	private $square_sync_module;
	private $reports_module;
	private $modules = array();

	public static function instance(): AIMS_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		$installer = new AIMS_Installer( new AIMS_Schema() );
		$installer->install();
		AIMS_Capabilities::register_roles_and_caps();
	}

	public static function uninstall(): void {
		$installer = new AIMS_Installer( new AIMS_Schema() );
		$installer->uninstall();
		AIMS_Capabilities::cleanup();
	}

	public static function get_option_keys(): array {
		return array(
			self::OPTION_SCHEMA_VERSION,
			self::OPTION_INSTALLED_AT,
		);
	}

	private function __construct() {
		$this->installer          = new AIMS_Installer( new AIMS_Schema() );
		$this->capabilities       = new AIMS_Capabilities();
		$this->vendor_module      = new AIMS_Vendor_Module(
			new AIMS_Vendor_Service(
				new AIMS_Vendor_Repository()
			)
		);
		$this->event_module       = new AIMS_Event_Module();
		$this->square_sync_module = new AIMS_Square_Sync_Module();
		$this->reports_module     = new AIMS_Reports_Module();
		$this->modules            = array(
			$this->vendor_module,
			$this->event_module,
			$this->square_sync_module,
			$this->reports_module,
		);
		$this->admin_menu         = new AIMS_Admin_Menu(
			$this->vendor_module,
			$this->square_sync_module,
			$this->reports_module
		);
	}

	public function boot(): void {
		add_action( 'init', array( $this->capabilities, 'register' ) );
		add_action( 'init', array( $this->installer, 'maybe_install' ), 5 );
		add_action( 'init', array( $this, 'harden_schema_constraints' ), 6 );
		add_action( 'admin_menu', array( $this->admin_menu, 'register' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		foreach ( $this->modules as $module ) {
			if ( $module instanceof AIMS_Module ) {
				$module->register();
			}
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-man-sys',
			false,
			dirname( AIMS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	public function harden_schema_constraints(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aims_event_bucket_assignments';
		$index_name = 'active_event_bucket';

		$index = $wpdb->get_row(
			$wpdb->prepare(
				'SHOW INDEX FROM `' . $table_name . '` WHERE Key_name = %s',
				$index_name
			),
			ARRAY_A
		);

		if ( empty( $index ) ) {
			return;
		}

		if ( ! isset( $index['Non_unique'] ) || 0 !== (int) $index['Non_unique'] ) {
			return;
		}

		$wpdb->query( 'ALTER TABLE `' . $table_name . '` DROP INDEX `' . $index_name . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'ALTER TABLE `' . $table_name . '` ADD KEY `event_bucket_active_lookup` (`event_id`, `is_active`, `display_order`)' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'ALTER TABLE `' . $table_name . '` ADD KEY `bucket_active_lookup` (`physical_bucket_id`, `is_active`)' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'ALTER TABLE `' . $table_name . '` ADD KEY `event_bucket_history_lookup` (`event_id`, `physical_bucket_id`, `assigned_at`)' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
