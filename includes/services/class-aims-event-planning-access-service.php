<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Access_Service {
	private $vendor_user_access;
	private $vendor_event_assignments;
	private $events;

	public function __construct(
		AIMS_Vendor_User_Access_Repository $vendor_user_access = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Event_Repository $events = null
	) {
		$this->vendor_user_access       = $vendor_user_access ?: new AIMS_Vendor_User_Access_Repository();
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->events                   = $events ?: new AIMS_Event_Repository();
	}

	public function can_access_event_planning( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return true;
		}

		return $this->user_has_role(
			$user_id,
			array(
				'aims_manager_user',
				'aims_supervisor_user',
			)
		) || $this->user_has_any_capability(
			$user_id,
			array(
				AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING,
				AIMS_Capabilities::CAP_MANAGE_EVENT_BUCKETS,
			)
		);
	}

	public function can_view_all_events( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->user_has_role( $user_id, array( 'administrator', 'shop_manager' ) ) ) {
			return true;
		}

		return $this->user_has_any_capability(
			$user_id,
			array(
				AIMS_Capabilities::CAP_MANAGE,
				AIMS_Capabilities::CAP_MANAGE_EVENTS,
			)
		);
	}

	public function get_authorized_vendor_ids( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || $this->can_view_all_events( $user_id ) ) {
			return array();
		}

		return $this->vendor_user_access->get_vendor_ids_for_user( $user_id );
	}

	public function get_authorized_event_ids( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return $this->get_all_event_ids();
		}

		$vendor_ids = $this->vendor_user_access->get_vendor_ids_for_user( $user_id );
		if ( empty( $vendor_ids ) ) {
			return array();
		}

		return $this->get_event_ids_for_vendor_ids( $vendor_ids );
	}

	public function get_authorized_events( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || ! $this->can_access_event_planning( $user_id ) ) {
			return array();
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return $this->events->all();
		}

		$event_ids = $this->get_authorized_event_ids( $user_id );
		if ( empty( $event_ids ) ) {
			return array();
		}

		return $this->load_events_by_ids( $event_ids );
	}

	public function get_current_user_authorized_events(): array {
		return $this->get_authorized_events( 0 );
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
}
