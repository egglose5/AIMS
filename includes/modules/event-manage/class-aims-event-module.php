<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Module implements AIMS_Module {
	private const PLANNING_PAGE = 'aims-event-planning';
	private $public_demand_controller;
	private $requests_history_controller;
	private $public_projection_controller;
	private $public_projection_data_provider;
	private $planning_actions;
	private $event_planning_access_service;

	public function __construct( $event_planning_access_service = null ) {
		$this->event_planning_access_service = $event_planning_access_service;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_public_hooks' ) );
		add_action( 'admin_post_aims_save_event_public_projection', array( $this, 'handle_public_projection_save' ) );
		add_action( 'admin_post_aims_event_save', array( $this, 'handle_event_save' ) );
		add_action( 'admin_post_aims_event_archive', array( $this, 'handle_event_archive' ) );
		$this->get_planning_actions()->register();
	}

	public function register_public_hooks(): void {
		$this->get_public_demand_controller()->register();
		$this->get_requests_history_controller()->register();
		$this->get_public_projection_controller()->register();
	}

	public function render_shell(): void {
		echo '<div class="wrap">';
		echo '<h1>Events</h1>';
		echo '<p>Events are the bridge between Square runtime assignment, vendor participation, physical bucket commitment, and public projection. Every operational path should resolve through `event_id` rather than direct Square-to-bucket coupling.</p>';
		echo '</div>';
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

	public function handle_event_save(): void {
		if ( ! $this->can_manage_events() ) {
			wp_die( esc_html__( 'You do not have permission to manage events.', 'ai-man-sys' ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? max( 0, (int) wp_unslash( $_POST['event_id'] ) ) : 0;
		if ( $event_id > 0 && ! $this->can_current_user_mutate_event( $event_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this event.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_event_save' );

		$data     = $this->collect_event_payload();

		if ( '' === $data['event_name'] || '' === $data['start_date'] || '' === $data['end_date'] ) {
			$this->redirect_to_planning( 'error', 'Event name, start date, and end date are required.', $event_id );
		}

		$saved_id = ( new AIMS_Event_Repository() )->save( $data, $event_id );
		$this->redirect_to_planning(
			'success',
			$event_id > 0 ? 'Event updated.' : 'Event created.',
			$saved_id
		);
	}

	public function handle_event_archive(): void {
		if ( ! $this->can_manage_events() ) {
			wp_die( esc_html__( 'You do not have permission to manage events.', 'ai-man-sys' ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? max( 0, (int) wp_unslash( $_POST['event_id'] ) ) : 0;
		if ( $event_id > 0 && ! $this->can_current_user_mutate_event( $event_id ) ) {
			wp_die( esc_html__( 'You do not have permission to archive this event.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_event_archive' );

		if ( $event_id <= 0 ) {
			$this->redirect_to_planning( 'error', 'Missing event id.' );
		}

		$event = ( new AIMS_Event_Repository() )->find( $event_id );
		if ( ! is_array( $event ) ) {
			$this->redirect_to_planning( 'error', 'Event not found.' );
		}

		$event['status'] = 'archived';
		( new AIMS_Event_Repository() )->save( $event, $event_id );
		$this->redirect_to_planning( 'success', 'Event archived.' );
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

	private function can_manage_events(): bool {
		return current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENTS )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING );
	}

	private function can_current_user_mutate_event( int $event_id ): bool {
		$event_id = max( 0, $event_id );
		if ( $event_id <= 0 ) {
			return false;
		}

		$access_service = $this->get_event_planning_access_service();
		if ( ! is_object( $access_service ) ) {
			return true;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( method_exists( $access_service, 'can_view_all_events' ) && (bool) $access_service->can_view_all_events( $user_id ) ) {
			return true;
		}

		$authorized_event_ids = array();

		foreach ( array( 'get_authorized_event_ids', 'get_authorized_events' ) as $method ) {
			if ( ! method_exists( $access_service, $method ) ) {
				continue;
			}

			$result = $access_service->{$method}( $user_id );
			if ( ! is_array( $result ) ) {
				continue;
			}

			foreach ( $result as $item ) {
				if ( is_array( $item ) ) {
					$authorized_event_ids[] = (int) ( $item['id'] ?? 0 );
					continue;
				}

				$authorized_event_ids[] = (int) $item;
			}

			break;
		}

		$authorized_event_ids = array_values( array_filter( array_unique( $authorized_event_ids ) ) );

		return in_array( $event_id, $authorized_event_ids, true );
	}

	private function get_event_planning_access_service() {
		if ( null === $this->event_planning_access_service && class_exists( 'AIMS_Event_Planning_Access_Service' ) ) {
			$this->event_planning_access_service = new AIMS_Event_Planning_Access_Service();
		}

		return $this->event_planning_access_service;
	}

	private function collect_event_payload(): array {
		return array(
			'event_name'         => sanitize_text_field( wp_unslash( $_POST['event_name'] ?? '' ) ),
			'event_code'         => sanitize_key( wp_unslash( $_POST['event_code'] ?? '' ) ),
			'status'             => sanitize_key( wp_unslash( $_POST['status'] ?? 'draft' ) ),
			'start_date'         => sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ),
			'end_date'           => sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) ),
			'location_name'      => sanitize_text_field( wp_unslash( $_POST['location_name'] ?? '' ) ),
			'square_location_id' => sanitize_text_field( wp_unslash( $_POST['square_location_id'] ?? '' ) ),
		);
	}

	private function redirect_to_planning( string $status, string $message, int $event_id = 0 ): void {
		$params = array(
			'page'                      => self::PLANNING_PAGE,
			'aims_event_manage_status'  => $status,
			'aims_event_manage_message' => $message,
		);

		if ( $event_id > 0 && 'error' === $status ) {
			$params['event_id'] = $event_id;
		}

		$redirect = add_query_arg( $params, admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}
}

