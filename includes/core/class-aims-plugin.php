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
	private $auth_context;
	private $audit;
	private $vendor_repository;
	private $bucket_repository;
	private $event_repository;
	private $vendor_access;
	private $bucket_access;
	private $scope_resolver;
	private $event_financials;
	private $event_automation;
	private $shipping_workflow;
	private $inventory_service;
	private $stitch_workflow;
	private $admin_menu;
	private $vendor_module;
	private $event_participation_provider;
	private $supervisor_inventory_provider;
	private $shipping_queue_provider;
	private $stitch_queue_provider;
	private $event_participation_page;
	private $supervisor_inventory_page;
	private $shipping_queue_page;
	private $stitch_queue_page;

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
		$this->auth_context = new AIMS_Auth_Context_Service();
		$this->audit        = new AIMS_Audit_Service();
		// Keep protected services on one shared graph so auth, access, and audit resolve the same way everywhere.
		$this->vendor_repository = new AIMS_Vendor_Repository();
		$this->bucket_repository = new AIMS_Inventory_Bucket_Repository();
		$this->event_repository  = new AIMS_Event_Repository();
		$this->bucket_access = new AIMS_Bucket_Access_Service(
			new AIMS_Bucket_Access_Repository(),
			$this->bucket_repository,
			$this->audit,
			$this->auth_context
		);
		$this->vendor_access = new AIMS_Vendor_Access_Service(
			new AIMS_Vendor_User_Access_Repository(),
			$this->vendor_repository,
			$this->audit,
			$this->auth_context
		);
		$this->scope_resolver = new AIMS_Admin_Scope_Resolver(
			new AIMS_Bucket_Access_Repository(),
			$this->bucket_repository,
			$this->vendor_access,
			$this->vendor_repository,
			$this->auth_context
		);
		$this->event_financials = new AIMS_Event_Financial_Service(
			$this->event_repository,
			new AIMS_Square_Sale_Repository(),
			new AIMS_Event_Expense_Repository(),
			new AIMS_Vendor_Event_Assignment_Repository(),
			new AIMS_Product_Cost_Service(
				new AIMS_Product_Cost_Rule_Repository()
			)
		);
		$this->event_automation = new AIMS_Event_Automation_Service(
			$this->event_repository,
			new AIMS_Square_Sale_Repository(),
			new AIMS_Vendor_Event_Assignment_Repository(),
			$this->event_financials,
			$this->vendor_access,
			$this->audit,
			$this->auth_context
		);
		$this->shipping_workflow = new AIMS_Shipping_Workflow_Service(
			new AIMS_Square_Sale_Repository(),
			new AIMS_Sale_Fulfillment_Allocation_Repository()
		);
		$this->inventory_service = new AIMS_Inventory_Service(
			$this->bucket_repository,
			new AIMS_Inventory_Movement_Repository(),
			$this->bucket_access,
			$this->audit,
			$this->auth_context
		);
		$this->stitch_workflow = new AIMS_Stitch_Workflow_Service(
			new AIMS_Stitch_Job_Repository(),
			$this->audit,
			$this->auth_context
		);
		$this->event_participation_provider = new AIMS_Event_Participation_Data_Provider(
			$this->event_automation,
			$this->event_repository,
			new AIMS_Vendor_Event_Assignment_Repository(),
			$this->vendor_repository,
			$this->scope_resolver
		);
		$this->supervisor_inventory_provider = new AIMS_Supervisor_Inventory_Data_Provider(
			$this->bucket_repository,
			$this->vendor_repository,
			$this->event_repository,
			$this->scope_resolver,
			$this->inventory_service,
			$this->bucket_access
		);
		$this->shipping_queue_provider = new AIMS_Shipping_Queue_Data_Provider(
			new AIMS_Square_Sale_Repository(),
			$this->event_repository,
			new AIMS_Customer_Repository(),
			$this->scope_resolver
		);
		$this->stitch_queue_provider = new AIMS_Stitch_Queue_Data_Provider(
			$this->stitch_workflow
		);
		$this->event_participation_page = new AIMS_Event_Participation_Page(
			$this->event_participation_provider,
			$this->event_automation
		);
		$this->supervisor_inventory_page = new AIMS_Supervisor_Inventory_Page(
			$this->supervisor_inventory_provider
		);
		$this->shipping_queue_page = new AIMS_Shipping_Queue_Page(
			$this->shipping_queue_provider
		);
		$this->stitch_queue_page = new AIMS_Stitch_Queue_Page(
			$this->stitch_queue_provider
		);
		$this->admin_menu   = new AIMS_Admin_Menu(
			$this->event_participation_page,
			$this->stitch_queue_page,
			$this->shipping_queue_page,
			$this->supervisor_inventory_page
		);
		$this->vendor_module = new AIMS_Vendor_Module(
			new AIMS_Vendor_Service(
				$this->vendor_repository,
				$this->vendor_access,
				$this->audit,
				$this->auth_context
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
