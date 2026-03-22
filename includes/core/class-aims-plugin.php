<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Plugin {
	const OPTION_SCHEMA_VERSION = 'aims_schema_version';
	const OPTION_INSTALLED_AT   = 'aims_installed_at';
	const SCHEMA_VERSION        = '0.1.0';

	private static $instance = null;

	private $installer;
	private $capabilities;
	private $admin_menu;
	private $vendor_access;
	private $vendor_module;

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
		$this->installer    = new AIMS_Installer( new AIMS_Schema() );
		$this->capabilities = new AIMS_Capabilities();
		$this->admin_menu   = new AIMS_Admin_Menu();
		$vendor_repository = new AIMS_Vendor_Repository();
		$this->vendor_access = new AIMS_Vendor_Access_Service(
			new AIMS_Vendor_User_Access_Repository(),
			$vendor_repository
		);
		$this->vendor_module = new AIMS_Vendor_Module(
			new AIMS_Vendor_Service(
				$vendor_repository,
				$this->vendor_access
			)
		);
	}

	public function boot(): void {
		add_action( 'init', array( $this->capabilities, 'register' ) );
		add_action( 'init', array( $this->installer, 'maybe_install' ), 5 );
		add_action( 'admin_menu', array( $this->admin_menu, 'register' ) );
		add_action( 'admin_menu', array( $this->admin_menu, 'prune_unrelated_menus' ), 999 );
		add_action( 'admin_init', array( $this->admin_menu, 'maybe_redirect_shell_users' ) );
		add_action( 'admin_bar_menu', array( $this->admin_menu, 'prune_admin_bar' ), 999 );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		$this->vendor_module->register();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-man-sys',
			false,
			dirname( AIMS_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
