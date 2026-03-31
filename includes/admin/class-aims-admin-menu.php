<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Menu {
	const MENU_SLUG = 'aims';
	private $vendor_module;
	private $square_sync_module;
	private $reports_module;
	private $responsibility_auth;

	public function __construct(
		?AIMS_Vendor_Module $vendor_module = null,
		?AIMS_Square_Sync_Module $square_sync_module = null,
		?AIMS_Reports_Module $reports_module = null,
		?AIMS_Responsibility_Authorization_Service $responsibility_auth = null
	) {
		$this->vendor_module      = $vendor_module ? $vendor_module : new AIMS_Vendor_Module( new AIMS_Vendor_Service() );
		$this->square_sync_module = $square_sync_module ? $square_sync_module : new AIMS_Square_Sync_Module();
		$this->reports_module     = $reports_module ? $reports_module : new AIMS_Reports_Module();
		$this->responsibility_auth = $responsibility_auth;
	}

	public function register(): void {
		add_menu_page(
			'AIMS',
			'AIMS',
			AIMS_Capabilities::CAP_MANAGE,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-database-view',
			56
		);

		if ( $this->user_can_access_events_shell() ) {
			add_menu_page(
				'Events',
				'Events',
				'read',
				'aims-events',
				array( $this, 'render_events_shell' ),
				'dashicons-calendar-alt',
				57
			);

			add_submenu_page(
				'aims-events',
				'Customer Demand',
				'Customer Demand',
				'read',
				'aims-event-customer-demand',
				array( $this, 'render_event_customer_demand' )
			);

			add_submenu_page(
				'aims-events',
				'Demand Summary',
				'Demand Summary',
				'read',
				'aims-event-demand-summary',
				array( $this, 'render_event_demand_summary' )
			);

			add_submenu_page(
				'aims-events',
				'Event Planning',
				'Event Planning',
				'read',
				'aims-event-planning',
				array( $this, 'render_event_planning' )
			);

			add_submenu_page(
				'aims-events',
				'Event Planning Workspace',
				'Event Planning Workspace',
				'read',
				AIMS_Event_Planning_Workspace_Page::PAGE_SLUG,
				array( $this, 'render_event_planning_workspace' )
			);
			remove_submenu_page( 'aims-events', AIMS_Event_Planning_Workspace_Page::PAGE_SLUG );

			add_submenu_page(
				'aims-events',
				'Public Projection',
				'Public Projection',
				AIMS_Capabilities::CAP_MANAGE_EVENT_PUBLICATION,
				'aims-event-public-projection',
				array( $this, 'render_event_public_projection' )
			);
		}

		add_menu_page(
			'Inventory',
			'Inventory',
			AIMS_Capabilities::CAP_VIEW_INVENTORY_SHELL,
			'aims-inventory',
			array( $this, 'render_inventory_shell' ),
			'dashicons-archive',
			58
		);

		if ( $this->user_can_manage_vendors() ) {
			add_submenu_page(
				self::MENU_SLUG,
				'Vendors',
				'Vendors',
				'read',
				'aims-vendors',
				array( $this, 'render_vendors_shell' )
			);
		}

		if ( $this->user_can_manage_square_sync() ) {
			add_submenu_page(
				self::MENU_SLUG,
				'Square Sync',
				'Square Sync',
				'read',
				'aims-square-sync',
				array( $this, 'render_square_sync_shell' )
			);
		}

		add_submenu_page(
			self::MENU_SLUG,
			'Needs Shipping',
			'Needs Shipping',
			AIMS_Capabilities::CAP_MANAGE_FULFILLMENT,
			'aims-needs-shipping',
			array( $this, 'render_needs_shipping_queue' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Unmatched Sales',
			'Unmatched Sales',
			AIMS_Capabilities::CAP_REVIEW_SQUARE_EXCEPTIONS,
			'aims-unmatched-sales',
			array( $this, 'render_unmatched_sales_queue' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Exceptions',
			'Exceptions',
			AIMS_Capabilities::CAP_REVIEW_SQUARE_EXCEPTIONS,
			'aims-square-exceptions',
			array( $this, 'render_square_exceptions_queue' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Vendor Sync Review',
			'Vendor Sync Review',
			AIMS_Capabilities::CAP_REVIEW_VENDOR_SYNC,
			'aims-vendor-sync-review',
			array( $this, 'render_vendor_sync_review' )
		);

		if ( $this->user_can_access_sync_runs() ) {
			add_submenu_page(
				self::MENU_SLUG,
				'Sync Runs / Replay',
				'Sync Runs / Replay',
				'read',
				'aims-square-sync-runs',
				array( $this, 'render_sync_runs_review' )
			);
		}

		if ( $this->user_can_view_reports() ) {
			add_submenu_page(
				self::MENU_SLUG,
				'Reports',
				'Reports',
				'read',
				'aims-reports',
				array( $this, 'render_reports_shell' )
			);
		}
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>AIMS</h1><p>AIMS centralizes vendor operations, event planning and execution, Square ingestion, and reporting through a shared event-centric data model.</p></div>';
	}

	public function render_events_shell(): void {
		$page = new AIMS_Events_Overview_Page( new AIMS_Events_Overview_Data_Provider() );
		$page->render();
	}

	public function render_event_customer_demand(): void {
		$page = new AIMS_Event_Customer_Demand_Page( new AIMS_Event_Customer_Demand_Data_Provider() );
		$page->render();
	}

	public function render_event_demand_summary(): void {
		$page = new AIMS_Event_Demand_Summary_Page( new AIMS_Event_Demand_Summary_Data_Provider() );
		$page->render();
	}

	public function render_event_planning(): void {
		$page = new AIMS_Event_Planning_Events_Page( new AIMS_Event_Planning_Events_Data_Provider() );
		$page->render();
	}

	public function render_event_planning_workspace(): void {
		$page = new AIMS_Event_Planning_Workspace_Page( new AIMS_Event_Planning_Workspace_Data_Provider() );
		$page->render();
	}

	public function render_event_public_projection(): void {
		$page = new AIMS_Event_Public_Projection_Page( new AIMS_Event_Public_Projection_Data_Provider() );
		$page->render();
	}

	public function render_inventory_shell(): void {
		$page = new AIMS_Inventory_Overview_Page( new AIMS_Inventory_Overview_Data_Provider() );
		$page->render();
	}

	public function render_vendors_shell(): void {
		$this->vendor_module->render_shell();
	}

	public function render_square_sync_shell(): void {
		$this->square_sync_module->render_shell();
	}

	public function render_needs_shipping_queue(): void {
		$page = new AIMS_Shipping_Queue_Page( new AIMS_Shipping_Queue_Data_Provider() );
		$page->render();
	}

	public function render_unmatched_sales_queue(): void {
		$page = new AIMS_Square_Unmatched_Sales_Page( new AIMS_Square_Unmatched_Sales_Data_Provider() );
		$page->render();
	}

	public function render_square_exceptions_queue(): void {
		$page = new AIMS_Square_Exceptions_Page( new AIMS_Square_Exceptions_Data_Provider() );
		$page->render();
	}

	public function render_vendor_sync_review(): void {
		$page = new AIMS_Vendor_Square_Sync_Review_Page( new AIMS_Vendor_Square_Sync_Review_Data_Provider() );
		$page->render();
	}

	public function render_sync_runs_review(): void {
		$page = new AIMS_Square_Sync_Runs_Page( new AIMS_Square_Sync_Runs_Data_Provider() );
		$page->render();
	}

	public function render_reports_shell(): void {
		$this->reports_module->render_shell();
	}

	private function user_can_access_events_shell(): bool {
		return $this->responsibility_auth !== null && $this->responsibility_auth->can_manage_event_planning( get_current_user_id() );
	}

	private function user_can_manage_vendors(): bool {
		return $this->responsibility_auth !== null && $this->responsibility_auth->can_manage_vendors( get_current_user_id() );
	}

	private function user_can_manage_square_sync(): bool {
		return $this->responsibility_auth !== null && $this->responsibility_auth->can_manage_square_sync( get_current_user_id() );
	}

	private function user_can_access_sync_runs(): bool {
		if ( $this->responsibility_auth === null ) {
			return false;
		}

		$uid = get_current_user_id();
		return $this->responsibility_auth->can_run_square_sync_replay( $uid ) || $this->responsibility_auth->can_run_square_sync_undo( $uid );
	}

	private function user_can_view_reports(): bool {
		return $this->responsibility_auth !== null && $this->responsibility_auth->can_view_reports( get_current_user_id() );
	}
}
