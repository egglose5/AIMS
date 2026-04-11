<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Portal_Navigation_Service {
	private const UPCOMING_EVENTS_LIMIT = 100;

	private $vendor_service;
	private $vendor_event_assignments;
	private $events_repository;
	private $auth_service;
	private $person_identity;
	private $public_event_catalog;

	public function __construct(
		AIMS_Vendor_Service $vendor_service = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Event_Repository $events_repository = null,
		AIMS_Responsibility_Authorization_Service $auth_service = null,
		AIMS_Person_Identity_Service $person_identity = null,
		AIMS_Public_Event_Catalog_Repository $public_event_catalog = null
	) {
		$this->vendor_service           = $vendor_service ?: new AIMS_Vendor_Service();
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->events_repository        = $events_repository ?: new AIMS_Event_Repository();
		$this->auth_service             = $auth_service ?: new AIMS_Responsibility_Authorization_Service();
		$this->person_identity          = $person_identity ?: new AIMS_Person_Identity_Service();
		$this->public_event_catalog     = $public_event_catalog ?: new AIMS_Public_Event_Catalog_Repository();
	}

	public function get_nav_model( array $request = array() ): array {
		$current_user_id  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$return_url       = $this->get_current_url();
		$assigned_vendors = $this->get_user_assigned_vendors( $current_user_id );
		$authorized       = $this->get_user_authorized_events( $current_user_id, $assigned_vendors );
		$available        = $this->get_available_events( $current_user_id, $assigned_vendors );

		return array(
			'logged_in'         => $current_user_id > 0,
			'current_user_id'   => $current_user_id,
			'assigned_vendors'  => $assigned_vendors,
			'authorized_events' => $authorized,
			'available_events'  => $available,
			'selected_event_id' => $this->resolve_selected_event_id( $request, array_merge( $authorized, $available ) ),
			'status_message'    => $this->get_status_message( $request ),
			'return_url'        => $return_url,
			'login_url'         => function_exists( 'wp_login_url' ) ? wp_login_url( $return_url ) : '',
		);
	}

	public function join_show( array $request = array() ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return $this->failure_response( 'You must be signed in to join a show.' );
		}

		$assigned_vendors = $this->get_user_assigned_vendors( $user_id );
		if ( empty( $assigned_vendors ) ) {
			return $this->failure_response( 'This account is not linked to an active vendor profile.' );
		}

		$vendor_options = $this->build_vendor_options( $assigned_vendors );
		$vendor_ids     = array_map(
			static function ( array $vendor ): int {
				return (int) ( $vendor['vendor_id'] ?? 0 );
			},
			$vendor_options
		);

		$vendor_id = isset( $request['vendor_id'] ) ? (int) $request['vendor_id'] : 0;
		if ( $vendor_id <= 0 ) {
			$vendor_id = ! empty( $vendor_ids ) ? (int) $vendor_ids[0] : 0;
		}

		if ( $vendor_id <= 0 || ! in_array( $vendor_id, $vendor_ids, true ) ) {
			return $this->failure_response( 'Select a valid vendor profile before joining a show.' );
		}

		$event_id = isset( $request['event_id'] ) ? (int) $request['event_id'] : 0;
		if ( $event_id <= 0 ) {
			return $this->failure_response( 'Select an upcoming show to join.' );
		}

		$event = $this->find_event_in_list( $event_id, $this->get_available_events( $user_id, $assigned_vendors ) );
		if ( empty( $event ) || empty( $event['can_join'] ) ) {
			return $this->failure_response( 'That show is no longer available to join.' );
		}

		if ( $this->vendor_is_already_assigned_to_event( $vendor_id, $event_id ) ) {
			return $this->failure_response( 'You are already assigned to that show.' );
		}

		$assignment_id = method_exists( $this->vendor_event_assignments, 'save' )
			? (int) $this->vendor_event_assignments->save(
				array(
					'event_id'           => $event_id,
					'vendor_id'          => $vendor_id,
					'assignment_status'  => 'assigned',
					'fulfillment_status' => 'pending',
					'commission_rate'    => 0,
					'notes'              => sprintf(
						'Vendor self-joined via vendor portal on %s.',
						function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' )
					),
				)
			) : 0;

		if ( $assignment_id <= 0 ) {
			return $this->failure_response( 'The show could not be joined right now. Please try again.' );
		}

		$event_name = sanitize_text_field( (string) ( $event['event_name'] ?? 'the show' ) );

		return array(
			'success'       => true,
			'message'       => sprintf( 'You joined %s.', $event_name ),
			'event_id'      => $event_id,
			'vendor_id'     => $vendor_id,
			'assignment_id' => $assignment_id,
		);
	}

	private function get_user_assigned_vendors( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( ! $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR ) ) {
			return array();
		}

		$vendors = array();

		foreach ( (array) $this->vendor_service->list_vendors() as $vendor ) {
			if ( ! is_array( $vendor ) ) {
				continue;
			}

			$vendor_user_id = (int) ( $vendor['user_id'] ?? 0 );
			if ( $vendor_user_id === $user_id ) {
				$vendors[] = $vendor;
			}
		}

		return $vendors;
	}

	private function get_user_authorized_events( int $user_id, array $assigned_vendors = array() ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( empty( $assigned_vendors ) ) {
			$assigned_vendors = $this->get_user_assigned_vendors( $user_id );
		}

		if ( empty( $assigned_vendors ) ) {
			return array();
		}

		$authorized_events     = array();
		$now_timestamp         = $this->resolve_now_timestamp();
		$pre_event_window_days = 3;

		foreach ( $assigned_vendors as $vendor ) {
			$vendor_id = (int) ( $vendor['user_id'] ?? 0 );
			if ( $vendor_id <= 0 ) {
				continue;
			}

			$assignments = method_exists( $this->vendor_event_assignments, 'get_for_vendor' )
				? (array) $this->vendor_event_assignments->get_for_vendor( $vendor_id )
				: array();

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

				$event_start            = (string) ( $event['event_start_date'] ?? $event['start_date'] ?? '' );
				$event_start_timestamp  = strtotime( $event_start );
				$window_start_timestamp = false !== $event_start_timestamp
					? $event_start_timestamp - ( $pre_event_window_days * 24 * 60 * 60 )
					: 0;

				$authorized_events[ $event_id ] = array(
					'event_id'         => $event_id,
					'event_name'       => sanitize_text_field( (string) ( $event['event_name'] ?? '' ) ),
					'event_start_date' => $event_start,
					'location_name'    => sanitize_text_field( (string) ( $event['location_name'] ?? '' ) ),
					'vendor_id'        => $vendor_id,
					'vendor_name'      => sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) ),
					'can_checkin'      => $this->can_user_submit_checkin( $user_id ) && false !== $event_start_timestamp && $now_timestamp >= $window_start_timestamp && $now_timestamp < $event_start_timestamp,
					'is_upcoming'      => false !== $event_start_timestamp ? $now_timestamp < $event_start_timestamp : false,
					'is_past'          => false !== $event_start_timestamp ? $now_timestamp >= $event_start_timestamp : false,
					'checkin_url'      => $this->build_checkin_url( $event_id ),
				);
			}
		}

		usort(
			$authorized_events,
			static function ( array $left, array $right ): int {
				$left_can_checkin  = ! empty( $left['can_checkin'] ) ? 1 : 0;
				$right_can_checkin = ! empty( $right['can_checkin'] ) ? 1 : 0;
				if ( $left_can_checkin !== $right_can_checkin ) {
					return $right_can_checkin <=> $left_can_checkin;
				}

				$left_upcoming  = ! empty( $left['is_upcoming'] ) ? 1 : 0;
				$right_upcoming = ! empty( $right['is_upcoming'] ) ? 1 : 0;
				if ( $left_upcoming !== $right_upcoming ) {
					return $right_upcoming <=> $left_upcoming;
				}

				$left_start  = (string) ( $left['event_start_date'] ?? '' );
				$right_start = (string) ( $right['event_start_date'] ?? '' );
				return strcmp( $left_start, $right_start );
			}
		);

		return array_values( $authorized_events );
	}

	private function get_available_events( int $user_id, array $assigned_vendors = array() ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( empty( $assigned_vendors ) ) {
			$assigned_vendors = $this->get_user_assigned_vendors( $user_id );
		}

		if ( empty( $assigned_vendors ) || ! is_object( $this->public_event_catalog ) || ! method_exists( $this->public_event_catalog, 'get_public_events' ) ) {
			return array();
		}

		$events         = array();
		$joined_event_ids = array();
		$now_timestamp  = $this->resolve_now_timestamp();
		$vendor_options = $this->build_vendor_options( $assigned_vendors );
		$default_vendor = ! empty( $vendor_options ) ? $vendor_options[0] : array();

		foreach ( $vendor_options as $vendor ) {
			$vendor_id = (int) ( $vendor['vendor_id'] ?? 0 );
			if ( $vendor_id <= 0 || ! method_exists( $this->vendor_event_assignments, 'get_for_vendor' ) ) {
				continue;
			}

			foreach ( (array) $this->vendor_event_assignments->get_for_vendor( $vendor_id ) as $assignment ) {
				if ( ! is_array( $assignment ) ) {
					continue;
				}

				$event_id = (int) ( $assignment['event_id'] ?? 0 );
				if ( $event_id > 0 ) {
					$joined_event_ids[ $event_id ] = true;
				}
			}
		}

		foreach ( (array) $this->public_event_catalog->get_public_events(
			array(
				'limit'         => self::UPCOMING_EVENTS_LIMIT,
				'public_status' => AIMS_Public_Event_Catalog_Repository::STATUS_PUBLISHED,
			)
		) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_id = (int) ( $event['event_id'] ?? $event['id'] ?? 0 );
			if ( $event_id <= 0 || isset( $joined_event_ids[ $event_id ] ) ) {
				continue;
			}

			$start_date      = (string) ( $event['start_date'] ?? $event['event_start_date'] ?? '' );
			$end_date        = (string) ( $event['end_date'] ?? '' );
			$start_timestamp = strtotime( $start_date );

			if ( false === $start_timestamp || $start_timestamp <= $now_timestamp ) {
				continue;
			}

			$events[ $event_id ] = array(
				'event_id'         => $event_id,
				'event_name'       => sanitize_text_field( (string) ( $event['event_name'] ?? $event['public_title'] ?? '' ) ),
				'event_start_date' => $start_date,
				'event_end_date'   => $end_date,
				'location_name'    => sanitize_text_field( (string) ( $event['location_name'] ?? $event['venue_name'] ?? '' ) ),
				'public_summary'   => wp_kses_post( (string) ( $event['public_summary'] ?? '' ) ),
				'date_range_label' => sanitize_text_field( (string) ( $event['date_range_label'] ?? $this->build_date_range_label( $start_date, $end_date ) ) ),
				'can_join'         => ! empty( $default_vendor['vendor_id'] ),
				'vendor_id'        => (int) ( $default_vendor['vendor_id'] ?? 0 ),
				'vendor_name'      => sanitize_text_field( (string) ( $default_vendor['vendor_name'] ?? '' ) ),
				'vendor_options'   => $vendor_options,
			);
		}

		usort(
			$events,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['event_start_date'] ?? '' ), (string) ( $right['event_start_date'] ?? '' ) );
			}
		);

		return array_values( $events );
	}

	private function build_vendor_options( array $assigned_vendors ): array {
		$options = array();

		foreach ( $assigned_vendors as $vendor ) {
			if ( ! is_array( $vendor ) ) {
				continue;
			}

			$vendor_id = (int) ( $vendor['user_id'] ?? 0 );
			if ( $vendor_id <= 0 || isset( $options[ $vendor_id ] ) ) {
				continue;
			}

			$options[ $vendor_id ] = array(
				'vendor_id'   => $vendor_id,
				'vendor_name' => sanitize_text_field( (string) ( $vendor['vendor_name'] ?? ( 'Vendor #' . $vendor_id ) ) ),
			);
		}

		return array_values( $options );
	}

	private function vendor_is_already_assigned_to_event( int $vendor_id, int $event_id ): bool {
		if ( $vendor_id <= 0 || $event_id <= 0 || ! method_exists( $this->vendor_event_assignments, 'get_for_vendor' ) ) {
			return false;
		}

		foreach ( (array) $this->vendor_event_assignments->get_for_vendor( $vendor_id ) as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			if ( (int) ( $assignment['event_id'] ?? 0 ) === $event_id ) {
				return true;
			}
		}

		return false;
	}

	private function resolve_selected_event_id( array $request, array $events ): int {
		$requested_id = isset( $request['event_id'] ) ? (int) wp_unslash( $request['event_id'] ) : 0;

		if ( $requested_id <= 0 ) {
			return 0;
		}

		foreach ( $events as $event ) {
			if ( (int) ( $event['event_id'] ?? 0 ) === $requested_id ) {
				return $requested_id;
			}
		}

		return 0;
	}

	private function find_event_in_list( int $event_id, array $events ): array {
		foreach ( $events as $event ) {
			if ( (int) ( $event['event_id'] ?? 0 ) === $event_id ) {
				return is_array( $event ) ? $event : array();
			}
		}

		return array();
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

	private function can_user_submit_checkin( int $user_id ): bool {
		if ( $user_id <= 0 || ! is_object( $this->auth_service ) ) {
			return false;
		}

		if ( ! method_exists( $this->auth_service, 'can_submit_vendor_checkin' ) ) {
			return false;
		}

		return (bool) $this->auth_service->can_submit_vendor_checkin( $user_id );
	}

	private function build_date_range_label( string $start_date, string $end_date ): string {
		$start_timestamp = strtotime( $start_date );
		$end_timestamp   = strtotime( $end_date );

		if ( false !== $start_timestamp && false !== $end_timestamp && gmdate( 'Y-m-d', $start_timestamp ) === gmdate( 'Y-m-d', $end_timestamp ) ) {
			return gmdate( 'F j, Y', $start_timestamp );
		}

		if ( false !== $start_timestamp && false !== $end_timestamp ) {
			return gmdate( 'F j, Y', $start_timestamp ) . ' - ' . gmdate( 'F j, Y', $end_timestamp );
		}

		if ( false !== $start_timestamp ) {
			return gmdate( 'F j, Y', $start_timestamp );
		}

		if ( false !== $end_timestamp ) {
			return gmdate( 'F j, Y', $end_timestamp );
		}

		return '';
	}

	private function get_current_url(): string {
		$scheme = function_exists( 'is_ssl' ) && is_ssl() ? 'https://' : 'http://';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		return esc_url_raw( $scheme . $host . $uri );
	}

	private function get_status_message( array $request ): array {
		$status  = sanitize_key( wp_unslash( $request['aims_vendor_portal_status'] ?? '' ) );
		$message = sanitize_text_field( wp_unslash( $request['aims_vendor_portal_message'] ?? '' ) );

		if ( '' === $status || '' === $message ) {
			return array();
		}

		return array(
			'status'  => $status,
			'message' => $message,
		);
	}

	private function failure_response( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}

	private function resolve_now_timestamp(): int {
		$now       = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$timestamp = strtotime( $now );

		return false === $timestamp ? time() : (int) $timestamp;
	}
}
