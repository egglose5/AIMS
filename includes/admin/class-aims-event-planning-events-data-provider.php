<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Events_Data_Provider {
	public const LANDING_PAGE_SLUG  = 'aims-event-planning';
	public const WORKSPACE_PAGE_SLUG = 'aims-event-planning-workspace';

	private $access_service;
	private $events;

	public function __construct( $access_service = null, AIMS_Event_Repository $events = null ) {
		$this->access_service = $access_service ?: ( class_exists( 'AIMS_Event_Planning_Access_Service' ) ? new AIMS_Event_Planning_Access_Service() : null );
		$this->events         = $events ?: new AIMS_Event_Repository();
	}

	public function get_rows(): array {
		$events = $this->get_authorized_events();

		usort(
			$events,
			static function ( array $left, array $right ): int {
				$left_date  = (string) ( $left['start_date'] ?? '' );
				$right_date = (string) ( $right['start_date'] ?? '' );

				if ( '' !== $left_date || '' !== $right_date ) {
					$result = strcmp( $right_date, $left_date );
					if ( 0 !== $result ) {
						return $result;
					}
				}

				return (int) ( $right['id'] ?? 0 ) <=> (int) ( $left['id'] ?? 0 );
			}
		);

		return array_map( array( $this, 'build_row' ), $events );
	}

	public function get_empty_message(): string {
		if ( ! $this->has_access_service() ) {
			return 'Event Planning access service is unavailable.';
		}

		return 'No authorized events are currently available for planning.';
	}

	public function get_workspace_url( int $event_id ): string {
		return add_query_arg(
			array(
				'page'     => self::WORKSPACE_PAGE_SLUG,
				'event_id' => $event_id,
			),
			admin_url( 'admin.php' )
		);
	}

	public function is_event_authorized( int $event_id ): bool {
		$event_id = max( 0, $event_id );
		if ( $event_id <= 0 ) {
			return false;
		}

		foreach ( $this->get_authorized_events() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( $event_id === (int) ( $event['id'] ?? 0 ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_authorized_events(): array {
		if ( ! $this->has_access_service() ) {
			return array();
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$events  = array();

		foreach ( $this->resolve_authorized_payload( $user_id ) as $item ) {
			if ( is_array( $item ) ) {
				$events[] = $item;
				continue;
			}

			$event_id = (int) $item;
			if ( $event_id <= 0 ) {
				continue;
			}

			foreach ( (array) $this->events->all() as $event ) {
				if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
					$events[] = $event;
					break;
				}
			}
		}

		return array_values( $events );
	}

	private function resolve_authorized_payload( int $user_id ): array {
		$service = $this->access_service;
		if ( ! is_object( $service ) ) {
			return array();
		}

		foreach ( array(
			'get_authorized_events_for_user',
			'get_authorized_event_rows_for_user',
			'get_authorized_event_ids_for_user',
			'get_authorized_events',
			'get_events_for_current_user',
		) as $method ) {
			if ( method_exists( $service, $method ) ) {
				try {
					$result = $service->{$method}( $user_id );
				} catch ( ArgumentCountError $e ) {
					$result = $service->{$method}();
				}

				return is_array( $result ) ? $result : array();
			}
		}

		return array();
	}

	private function has_access_service(): bool {
		return is_object( $this->access_service );
	}

	private function build_row( array $event ): array {
		return array(
			'id'                 => (int) ( $event['id'] ?? 0 ),
			'event_code'         => sanitize_text_field( (string) ( $event['event_code'] ?? '' ) ),
			'event_name'         => sanitize_text_field( (string) ( $event['event_name'] ?? '' ) ),
			'status'             => sanitize_key( (string) ( $event['status'] ?? '' ) ),
			'start_date'         => sanitize_text_field( (string) ( $event['start_date'] ?? '' ) ),
			'end_date'           => sanitize_text_field( (string) ( $event['end_date'] ?? '' ) ),
			'location_name'      => sanitize_text_field( (string) ( $event['location_name'] ?? '' ) ),
			'square_location_id' => sanitize_text_field( (string) ( $event['square_location_id'] ?? '' ) ),
			'workspace_url'      => $this->get_workspace_url( (int) ( $event['id'] ?? 0 ) ),
		);
	}
}
