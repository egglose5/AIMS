<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Module {
	private $public_demand_controller;
	private $requests_history_controller;

	public function register(): void {
		add_action( 'init', array( $this, 'register_public_hooks' ) );
		add_action( 'admin_init', array( $this, 'register_foundation_notices' ) );
	}

	public function register_public_hooks(): void {
		$this->get_public_demand_controller()->register();
		$this->get_requests_history_controller()->register();
	}

	public function register_foundation_notices(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( AIMS_Capabilities::CAP_VIEW_EVENTS_SHELL ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( 'aims-events', 'aims-event-customer-demand', 'aims-event-demand-summary' ), true ) ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_foundation_notice' ) );
	}

	public function render_shell(): void {
		echo '<div class="wrap">';
		echo '<h1>Events</h1>';
		echo '<p>Events are the bridge between Square runtime assignment, vendor participation, and physical bucket commitment. Every operational path should resolve through `event_id` rather than direct Square-to-bucket coupling.</p>';
		echo '</div>';
	}

	public function render_foundation_notice(): void {
		echo '<div class="notice notice-info"><p>';
		echo esc_html( 'Events foundation is active. Event bucket assignments, customer demand planning, and event-centric reporting will use `event_id` as the shared bridge.' );
		echo '</p></div>';
	}

	private function get_public_demand_controller(): AIMS_Event_Demand_Intake_Controller {
		if ( null === $this->public_demand_controller ) {
			$this->public_demand_controller = new AIMS_Event_Demand_Intake_Controller();
		}

		return $this->public_demand_controller;
	}

	private function get_requests_history_controller(): AIMS_Event_Requests_History_Controller {
		if ( null === $this->requests_history_controller ) {
			$this->requests_history_controller = new AIMS_Event_Requests_History_Controller();
		}

		return $this->requests_history_controller;
	}
}

