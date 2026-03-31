<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Access_Service {
	private $vendor_user_access;
	private $vendor_event_assignments;
	private $events;
	private $supervisor_hierarchy;
	private $responsibility_auth;

	public function __construct(
		AIMS_Vendor_User_Access_Repository $vendor_user_access = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Event_Repository $events = null,
		AIMS_Supervisor_User_Hierarchy_Repository $supervisor_hierarchy = null,
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null
	) {
		$this->vendor_user_access       = $vendor_user_access ?: new AIMS_Vendor_User_Access_Repository();
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->events                   = $events ?: new AIMS_Event_Repository();
		$this->supervisor_hierarchy     = $supervisor_hierarchy ?: ( class_exists( 'AIMS_Supervisor_User_Hierarchy_Repository' ) ? new AIMS_Supervisor_User_Hierarchy_Repository() : null );
		$this->responsibility_auth      = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function can_access_event_planning( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return true;
		}

		if ( ! is_object( $this->responsibility_auth ) ) {
			return false;
		}

		return $this->responsibility_auth->can_manage_event_planning( $user_id );
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

		$authorized_user_ids = $this->get_team_user_ids( $user_id );
		if ( empty( $authorized_user_ids ) ) {
			return array();
		}

		$vendor_ids = array();
		foreach ( $authorized_user_ids as $authorized_user_id ) {
			$vendor_ids = array_merge( $vendor_ids, $this->vendor_user_access->get_vendor_ids_for_user( (int) $authorized_user_id ) );
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $vendor_ids ) ) ) );
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

		return $this->responsibility_auth->get_authorized_event_ids( $user_id );
	}

	public function get_authorized_events( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || ! $this->can_access_event_planning( $user_id ) ) {
			return array();
		}

		if ( $this->can_view_all_events( $user_id ) ) {
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
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->user_has_role( $user_id, array( 'aims_supervisor_user' ) );
	}

	public function get_subordinate_user_ids( int $user_id = 0, int $max_depth = 5 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || ! is_object( $this->supervisor_hierarchy ) || ! method_exists( $this->supervisor_hierarchy, 'get_subordinates_for_supervisor' ) ) {
			return array();
		}

		$ids = $this->supervisor_hierarchy->get_subordinates_for_supervisor( $user_id, $max_depth );

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	}

	public function get_team_user_ids( int $user_id = 0, int $max_depth = 5 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( ! $this->is_hierarchy_scoped_user( $user_id ) ) {
			return array( $user_id );
		}

		if ( ! $this->user_has_hierarchy_mapping( $user_id ) ) {
			return array();
		}

		$team_user_ids = array( $user_id );
		$team_user_ids = array_merge( $team_user_ids, $this->get_subordinate_user_ids( $user_id, $max_depth ) );

		return array_values( array_unique( array_filter( array_map( 'intval', $team_user_ids ) ) ) );
	}

	public function is_subordinate_user( int $supervisor_user_id, int $candidate_user_id, int $max_depth = 5 ): bool {
		if ( $supervisor_user_id <= 0 || $candidate_user_id <= 0 ) {
			return false;
		}

		if ( $supervisor_user_id === $candidate_user_id ) {
			return true;
		}

		if ( ! is_object( $this->supervisor_hierarchy ) || ! method_exists( $this->supervisor_hierarchy, 'is_subordinate_of' ) ) {
			return false;
		}

		return (bool) $this->supervisor_hierarchy->is_subordinate_of( $candidate_user_id, $supervisor_user_id, $max_depth );
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

		$team_user_ids = $this->get_team_user_ids( $user_id, $max_depth );
		if ( empty( $team_user_ids ) ) {
			return array();
		}

		$contexts = array();
		foreach ( $team_user_ids as $team_user_id ) {
			$source     = $team_user_id === $user_id ? 'self' : 'team';
			$vendor_ids = $this->vendor_user_access->get_vendor_ids_for_user( (int) $team_user_id );

			if ( empty( $vendor_ids ) ) {
				continue;
			}

			foreach ( $this->get_event_ids_for_vendor_ids( $vendor_ids ) as $event_id ) {
				if ( ! isset( $contexts[ $event_id ] ) || 'self' === $source ) {
					$contexts[ $event_id ] = array(
						'event_id' => $event_id,
						'source'   => $source,
					);
				}
			}
		}

		return $contexts;
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

	private function get_event_ids_for_vendor_ids( array $vendor_ids ): array {
		global $wpdb;

		$vendor_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $vendor_id ): int {
							return (int) $vendor_id;
						},
						$vendor_ids
					)
				)
			)
		);

		if ( empty( $vendor_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $vendor_ids ), '%d' ) );
		$sql          = 'SELECT DISTINCT event_id FROM ' . $this->vendor_event_assignments->get_table_name() . ' WHERE vendor_id IN (' . $placeholders . ')';
		$rows         = $wpdb->get_results(
			$wpdb->prepare( $sql, $vendor_ids ),
			ARRAY_A
		);

		$event_ids = array();

		foreach ( (array) $rows as $row ) {
			$event_id = (int) ( $row['event_id'] ?? 0 );
			if ( $event_id > 0 ) {
				$event_ids[] = $event_id;
			}
		}

		return array_values( array_unique( $event_ids ) );
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

	private function user_has_role( int $user_id, array $roles ): bool {
		if ( ! function_exists( 'get_user_by' ) ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! is_object( $user ) ) {
			return false;
		}

		$user_roles = array_map( 'sanitize_key', (array) ( $user->roles ?? array() ) );

		foreach ( $roles as $role ) {
			if ( in_array( sanitize_key( $role ), $user_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_hierarchy_scoped_user( int $user_id ): bool {
		return $this->user_has_role(
			$user_id,
			array(
				'aims_manager_user',
				'aims_supervisor_user',
			)
		);
	}

	private function user_has_hierarchy_mapping( int $user_id ): bool {
		if ( ! is_object( $this->supervisor_hierarchy ) || ! method_exists( $this->supervisor_hierarchy, 'has_active_relationship_for_user' ) ) {
			return false;
		}

		return (bool) $this->supervisor_hierarchy->has_active_relationship_for_user( $user_id );
	}

	private function user_has_any_capability( int $user_id, array $caps ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'user_can' ) ) {
			foreach ( $caps as $cap ) {
				if ( user_can( $user_id, $cap ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function should_use_responsibility_model( int $user_id ): bool {
		if ( ! is_object( $this->responsibility_auth ) || ! method_exists( $this->responsibility_auth, 'has_any_assignments_for_user' ) ) {
			return false;
		}

		return (bool) $this->responsibility_auth->has_any_assignments_for_user( $user_id );
	}
}
