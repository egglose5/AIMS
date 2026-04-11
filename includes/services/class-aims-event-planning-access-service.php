<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Access_Service {
	private $vendor_event_assignments;
	private $events;
	private $responsibility_auth;

	public function __construct(
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Event_Repository $events = null,
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null
	) {
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->events                   = $events ?: new AIMS_Event_Repository();
		$this->responsibility_auth      = $responsibility_auth ?: new AIMS_Responsibility_Authorization_Service();
	}

	public function can_access_event_planning( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return true;
		}

		return is_object( $this->responsibility_auth ) && $this->responsibility_auth->can_manage_event_planning( $user_id );
	}

	public function can_view_all_events( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || ! is_object( $this->responsibility_auth ) ) {
			return false;
		}

		return $this->responsibility_auth->can_view_all_events( $user_id );
	}

	public function get_authorized_vendor_ids( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || $this->can_view_all_events( $user_id ) ) {
			return array();
		}

		$event_ids = $this->get_authorized_event_ids_including_subordinates( $user_id );

		return $this->get_vendor_ids_for_event_ids( $event_ids );
	}

	public function get_authorized_event_ids( int $user_id = 0 ): array {
		return $this->get_authorized_event_ids_including_subordinates( $user_id );
	}

	public function get_authorized_event_ids_including_subordinates( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return $this->get_all_event_ids();
		}

		if ( ! is_object( $this->responsibility_auth ) ) {
			return array();
		}

		$event_ids = $this->responsibility_auth->get_authorized_event_ids( $user_id );
		if ( ! empty( $event_ids ) ) {
			return $event_ids;
		}

		$has_scope_restrictions = method_exists( $this->responsibility_auth, 'has_event_scope_restrictions' )
			? (bool) $this->responsibility_auth->has_event_scope_restrictions( $user_id )
			: false;

		if ( ! $has_scope_restrictions && $this->responsibility_auth->can_manage_event_planning( $user_id ) ) {
			return $this->get_all_event_ids();
		}

		return array();
	}

	public function get_authorized_events( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || ! $this->can_access_event_planning( $user_id ) ) {
			return array();
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return $this->events->all();
		}

		if ( ! $this->has_event_scope_restrictions( $user_id ) ) {
			return $this->events->all();
		}

		$event_ids = $this->get_authorized_event_ids_including_subordinates( $user_id );
		if ( empty( $event_ids ) ) {
			return array();
		}

		return $this->load_events_by_ids( $event_ids );
	}

	public function get_current_user_authorized_events(): array {
		return $this->get_authorized_events( 0 );
	}

	public function is_supervisor( int $user_id = 0 ): bool {
		return false;
	}

	public function get_subordinate_user_ids( int $user_id = 0, int $max_depth = 5 ): array {
		return array();
	}

	public function get_team_user_ids( int $user_id = 0, int $max_depth = 5 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( $this->can_access_event_planning( $user_id ) ) {
			return array( $user_id );
		}

		return array();
	}

	public function is_subordinate_user( int $supervisor_user_id, int $candidate_user_id, int $max_depth = 5 ): bool {
		if ( $supervisor_user_id <= 0 || $candidate_user_id <= 0 ) {
			return false;
		}

		if ( $supervisor_user_id === $candidate_user_id ) {
			return true;
		}

		return false;
	}

	public function get_authorized_event_contexts( int $user_id = 0, int $max_depth = 5 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			$contexts = array();
			foreach ( $this->get_all_event_ids() as $event_id ) {
				$contexts[ $event_id ] = array(
					'event_id' => $event_id,
					'source'   => 'all',
				);
			}

			return $contexts;
		}

		$event_ids = $this->get_authorized_event_ids_including_subordinates( $user_id );
		if ( empty( $event_ids ) ) {
			return array();
		}

		$contexts = array();
		foreach ( $event_ids as $event_id ) {
			$contexts[ $event_id ] = array(
				'event_id' => (int) $event_id,
				'source'   => 'self',
			);
		}

		return $contexts;
	}

	private function has_event_scope_restrictions( int $user_id ): bool {
		if ( $user_id <= 0 || ! is_object( $this->responsibility_auth ) ) {
			return false;
		}

		return method_exists( $this->responsibility_auth, 'has_event_scope_restrictions' )
			? (bool) $this->responsibility_auth->has_event_scope_restrictions( $user_id )
			: false;
	}

	private function get_all_event_ids(): array {
		$event_ids = array();

		foreach ( (array) $this->events->all() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_id = (int) ( $event['id'] ?? 0 );
			if ( $event_id > 0 ) {
				$event_ids[] = $event_id;
			}
		}

		return array_values( array_unique( $event_ids ) );
	}

	private function get_vendor_ids_for_event_ids( array $event_ids ): array {
		$event_ids = array_values( array_filter( array_unique( array_map( 'intval', $event_ids ) ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$vendor_ids = array();

		foreach ( $event_ids as $event_id ) {
			foreach ( (array) $this->vendor_event_assignments->get_for_event( (int) $event_id ) as $assignment ) {
				if ( ! is_array( $assignment ) ) {
					continue;
				}

				$vendor_id = (int) ( $assignment['vendor_id'] ?? 0 );
				if ( $vendor_id > 0 ) {
					$vendor_ids[] = $vendor_id;
				}
			}
		}

		return array_values( array_unique( $vendor_ids ) );
	}

	private function load_events_by_ids( array $event_ids ): array {
		global $wpdb;

		$event_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $event_id ): int {
							return (int) $event_id;
						},
						$event_ids
					)
				)
			)
		);

		if ( empty( $event_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
		$sql          = 'SELECT * FROM ' . $this->events->get_table_name() . ' WHERE id IN (' . $placeholders . ') ORDER BY start_date DESC, id DESC';
		$rows         = $wpdb->get_results(
			$wpdb->prepare( $sql, $event_ids ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	private function resolve_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			return (int) get_current_user_id();
		}

		return 0;
	}

}
