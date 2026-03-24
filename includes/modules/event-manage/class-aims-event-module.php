<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Module {
	private $public_demand_controller;
	private $requests_history_controller;
	private $public_projection_controller;
	private $public_projection_data_provider;
	private $planning_actions;

	public function register(): void {
		add_action( 'init', array( $this, 'register_public_hooks' ) );
		add_action( 'admin_init', array( $this, 'register_foundation_notices' ) );
		add_action( 'admin_post_aims_save_event_public_projection', array( $this, 'handle_public_projection_save' ) );
		$this->get_planning_actions()->register();
	}

	public function register_public_hooks(): void {
		$this->get_public_demand_controller()->register();
		$this->get_requests_history_controller()->register();
		$this->get_public_projection_controller()->register();
	}

	public function register_foundation_notices(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( AIMS_Capabilities::CAP_VIEW_EVENTS_SHELL ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( 'aims-events', 'aims-event-customer-demand', 'aims-event-demand-summary', 'aims-event-public-projection' ), true ) ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_foundation_notice' ) );
	}

	public function render_shell(): void {
		echo '<div class="wrap">';
		echo '<h1>Events</h1>';
		echo '<p>Events are the bridge between Square runtime assignment, vendor participation, physical bucket commitment, and public projection. Every operational path should resolve through `event_id` rather than direct Square-to-bucket coupling.</p>';
		echo '</div>';
	}

	public function render_foundation_notice(): void {
		echo '<div class="notice notice-info"><p>';
		echo esc_html( 'Events foundation is active. Event bucket assignments, customer demand planning, public projection, and event-centric reporting will use `event_id` as the shared bridge.' );
		echo '</p></div>';
	}

	public function handle_public_projection_save(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_PUBLICATION ) ) {
			wp_die( esc_html__( 'You do not have permission to manage public event projection.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_event_public_projection_save' );

		$event_id = isset( $_POST['event_id'] ) ? max( 0, (int) wp_unslash( $_POST['event_id'] ) ) : 0;
		$result   = $this->get_public_projection_data_provider()->save_projection(
			array(
				'event_id'               => $event_id,
				'public_status'          => sanitize_key( wp_unslash( $_POST['public_status'] ?? '' ) ),
				'public_title'           => sanitize_text_field( wp_unslash( $_POST['public_title'] ?? '' ) ),
				'public_summary'         => wp_kses_post( wp_unslash( $_POST['public_summary'] ?? '' ) ),
				'slug'                   => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
				'venue_name'             => sanitize_text_field( wp_unslash( $_POST['venue_name'] ?? '' ) ),
				'city'                   => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
				'state_region'           => sanitize_text_field( wp_unslash( $_POST['state_region'] ?? '' ) ),
				'hero_image_reference'   => sanitize_text_field( wp_unslash( $_POST['hero_image_reference'] ?? '' ) ),
				'is_featured'            => ! empty( $_POST['is_featured'] ) ? 1 : 0,
				'request_intake_enabled' => ! empty( $_POST['request_intake_enabled'] ) ? 1 : 0,
			)
		);

		$redirect = add_query_arg(
			array(
				'page'                           => 'aims-event-public-projection',
				'event_id'                       => $event_id,
				'aims_public_projection_status'  => ! empty( $result['success'] ) ? 'success' : 'error',
				'aims_public_projection_message' => (string) ( $result['message'] ?? 'Unable to save public projection.' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
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

	private function get_public_projection_controller(): AIMS_Event_Public_Projection_Controller {
		if ( null === $this->public_projection_controller ) {
			$this->public_projection_controller = new AIMS_Event_Public_Projection_Controller();
		}

		return $this->public_projection_controller;
	}

	private function get_public_projection_data_provider(): AIMS_Event_Public_Projection_Data_Provider {
		if ( null === $this->public_projection_data_provider ) {
			$this->public_projection_data_provider = new AIMS_Event_Public_Projection_Data_Provider();
		}

		return $this->public_projection_data_provider;
	}

	private function get_planning_actions(): AIMS_Event_Planning_Actions {
		if ( null === $this->planning_actions ) {
			$this->planning_actions = new AIMS_Event_Planning_Actions(
				new AIMS_Event_Planning_Action_Service()
			);
		}

		return $this->planning_actions;
	}
}

