<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Portal_Navigation_Service {
	private $vendor_service;
	private $vendor_event_assignments;
	private $events_repository;
	private $auth_service;

	public function __construct(
		AIMS_Vendor_Service $vendor_service = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Event_Repository $events_repository = null,
		AIMS_Responsibility_Authorization_Service $auth_service = null
	) {
		$this->vendor_service = $vendor_service ?: new AIMS_Vendor_Service();
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->events_repository = $events_repository ?: new AIMS_Event_Repository();
		$this->auth_service = $auth_service ?: new AIMS_Responsibility_Authorization_Service();
	}

	public function get_nav_model( array $request = array() ): array {
		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return array(
			'logged_in'         => $current_user_id > 0,
			'current_user_id'   => $current_user_id,
			'assigned_vendors'  => $this->get_user_assigned_vendors( $current_user_id ),
			'authorized_events' => $this->get_user_authorized_events( $current_user_id ),
			'selected_event_id' => $this->resolve_selected_event_id( $request, $current_user_id ),
			'login_url'         => function_exists( 'wp_login_url' ) ? wp_login_url() : '',
		);
	}

	private function get_user_assigned_vendors( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$user = get_user_by( 'ID', $user_id );
		if ( ! is_object( $user ) ) {
			return array();
		}

		$user_email = (string) ( $user->user_email ?? '' );
		$vendors = array();

		// Primary: Check if user is directly a vendor (user_id in vendor metadata)
		foreach ( (array) $this->vendor_service->list_vendors() as $vendor ) {
			if ( ! is_array( $vendor ) ) {
				continue;
			}

			// New model: vendor has user_id
			$vendor_user_id = (int) ( $vendor['user_id'] ?? 0 );
			if ( $vendor_user_id === $user_id ) {
				$vendors[] = $vendor;
				continue;
			}

			// Legacy fallback: match by email
			$contact_email = (string) ( $vendor['contact_email'] ?? '' );
			if ( '' !== $contact_email && $contact_email === $user_email ) {
				$vendors[] = $vendor;
			}
		}

		return $vendors;
	}

	private function get_user_authorized_events( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$assigned_vendors = $this->get_user_assigned_vendors( $user_id );
		if ( empty( $assigned_vendors ) ) {
			return array();
		}

		$authorized_events = array();
		$now_timestamp = $this->resolve_now_timestamp();
		$pre_event_window_days = 3;

		foreach ( $assigned_vendors as $vendor ) {
			// Support both new model (user_id) and legacy model (id)
			$vendor_id = (int) ( $vendor['user_id'] ?? $vendor['id'] ?? 0 );
			if ( $vendor_id <= 0 ) {
				continue;
			}

			$assignments = (array) $this->vendor_event_assignments->get_for_vendor( $vendor_id );
			foreach ( $assignments as $assignment ) {
				if ( ! is_array( $assignment ) ) {
					continue;
				}

				$event_id = (int) ( $assignment['event_id'] ?? 0 );
				if ( $event_id <= 0 || isset( $authorized_events[ $event_id ] ) ) {
					continue;
				}

				$event = $this->events_repository->find( $event_id );
				if ( empty( $event ) || ! is_array( $event ) ) {
					continue;
				}

				$event_start = (string) ( $event['event_start_date'] ?? $event['start_date'] ?? '' );
				$event_start_timestamp = strtotime( $event_start );
				$window_start_timestamp = $event_start_timestamp - ( $pre_event_window_days * 24 * 60 * 60 );

				$authorized_events[ $event_id ] = array(
					'event_id'          => $event_id,
					'event_name'        => sanitize_text_field( (string) ( $event['event_name'] ?? '' ) ),
					'event_start_date'  => $event_start,
					'location_name'     => sanitize_text_field( (string) ( $event['location_name'] ?? '' ) ),
					'vendor_id'         => $vendor_id,
					'vendor_name'       => sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) ),
					'can_checkin'       => $now_timestamp >= $window_start_timestamp && $now_timestamp < $event_start_timestamp,
					'is_upcoming'       => $now_timestamp < $event_start_timestamp,
					'is_past'           => $now_timestamp >= $event_start_timestamp,
					'checkin_url'       => $this->build_checkin_url( $event_id ),
				);
			}
		}

		// Sort by upcoming/can checkin status first, then by event start date.
		usort(
			$authorized_events,
			static function ( array $left, array $right ): int {
				$left_can_checkin = ! empty( $left['can_checkin'] ) ? 1 : 0;
				$right_can_checkin = ! empty( $right['can_checkin'] ) ? 1 : 0;
				if ( $left_can_checkin !== $right_can_checkin ) {
					return $right_can_checkin <=> $left_can_checkin;
				}

				$left_upcoming = ! empty( $left['is_upcoming'] ) ? 1 : 0;
				$right_upcoming = ! empty( $right['is_upcoming'] ) ? 1 : 0;
				if ( $left_upcoming !== $right_upcoming ) {
					return $right_upcoming <=> $left_upcoming;
				}

				$left_start = (string) ( $left['event_start_date'] ?? '' );
				$right_start = (string) ( $right['event_start_date'] ?? '' );
				return strcmp( $left_start, $right_start );
			}
		);

		return array_values( $authorized_events );
	}

	private function resolve_selected_event_id( array $request, int $user_id ): int {
		$requested_id = isset( $request['event_id'] ) ? (int) wp_unslash( $request['event_id'] ) : 0;

		if ( $requested_id <= 0 ) {
			return 0;
		}

		$authorized = $this->get_user_authorized_events( $user_id );
		foreach ( $authorized as $event ) {
			if ( (int) ( $event['event_id'] ?? 0 ) === $requested_id ) {
				return $requested_id;
			}
		}

		return 0;
	}

	private function build_checkin_url( int $event_id ): string {
		if ( $event_id <= 0 ) {
			return '';
		}

		return add_query_arg(
			array( 'event_id' => $event_id ),
			home_url( '/' )
		);
	}

	private function resolve_now_timestamp(): int {
		$now = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$timestamp = strtotime( $now );

		return false === $timestamp ? time() : (int) $timestamp;
	}
}
