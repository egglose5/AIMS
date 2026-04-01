<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Responsibility_Authorization_Service {
	public const OPTION_ENABLE = 'aims_responsibility_rbac_enabled';

	public const RESP_SYSTEM_ADMIN            = 'system_admin';
	public const RESP_EVENT_PLANNING_ACCESS   = 'event_planning_access';
	public const RESP_EVENT_PLANNING_MUTATE   = 'event_planning_mutate';
	public const RESP_EVENT_PLANNING_ALL      = 'event_planning_all_events';
	public const RESP_VENDOR_MANAGEMENT        = 'vendor_management';
	public const RESP_VENDOR_SUBMIT_CHECKIN    = 'vendor_submit_checkin';
	public const RESP_VENDOR_VIEW_COMMISSION   = 'vendor_view_commission';
	public const RESP_VENDOR_MANAGE_INVENTORY  = 'vendor_manage_inventory';
	public const RESP_SQUARE_SYNC_MANAGEMENT   = 'square_sync_management';
	public const RESP_SQUARE_SYNC_REPLAY       = 'square_sync_replay';
	public const RESP_SQUARE_SYNC_UNDO         = 'square_sync_undo';
	public const RESP_REPORTS_VIEW             = 'reports_view';

	private $assignments;
	private $vendor_event_assignments;
	private $person_identity;

	public function __construct(
		AIMS_Responsibility_Assignment_Repository $assignments = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Person_Identity_Service $person_identity = null
	) {
		$this->assignments = $assignments ?: new AIMS_Responsibility_Assignment_Repository();
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->person_identity = $person_identity ?: new AIMS_Person_Identity_Service();
	}

	public function has_any_assignments_for_user( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || ! $this->is_enabled() ) {
			return false;
		}

		return $this->assignments->has_active_assignments_for_user( $user_id );
	}

	public function can_manage_event_planning( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return true;
		}

		if ( $this->user_has_responsibility_cap( $user_id, self::RESP_EVENT_PLANNING_ACCESS ) ) {
			return true;
		}

		if ( $this->user_has_responsibility_cap( $user_id, self::RESP_EVENT_PLANNING_MUTATE ) ) {
			return true;
		}

		if ( $this->assignments->user_has_responsibility( $user_id, self::RESP_EVENT_PLANNING_ACCESS ) ) {
			return true;
		}

		if ( $this->assignments->user_has_responsibility( $user_id, self::RESP_EVENT_PLANNING_MUTATE ) ) {
			return true;
		}

		return ! empty( $this->get_authorized_event_ids( $user_id ) );
	}

	public function can_view_all_events( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->user_has_responsibility_cap( $user_id, self::RESP_SYSTEM_ADMIN ) ) {
			return true;
		}

		if ( $this->user_has_responsibility_cap( $user_id, self::RESP_EVENT_PLANNING_ALL ) ) {
			return true;
		}

		if ( $this->assignments->user_has_responsibility( $user_id, self::RESP_SYSTEM_ADMIN ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility( $user_id, self::RESP_EVENT_PLANNING_ALL );
	}

	public function can_mutate_event( int $user_id, int $event_id ): bool {
		$user_id = $this->resolve_user_id( $user_id );
		$event_id = (int) $event_id;

		if ( $user_id <= 0 || $event_id <= 0 ) {
			return false;
		}

		if ( $this->can_view_all_events( $user_id ) ) {
			return true;
		}

		if ( $this->user_has_responsibility_cap( $user_id, self::RESP_EVENT_PLANNING_MUTATE ) ) {
			return true;
		}

		if ( $this->assignments->user_has_responsibility( $user_id, self::RESP_EVENT_PLANNING_MUTATE ) ) {
			return true;
		}

		if ( $this->event_matches_authorized_vendor_scope( $user_id, $event_id, self::RESP_EVENT_PLANNING_MUTATE ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility(
			$user_id,
			self::RESP_EVENT_PLANNING_MUTATE,
			AIMS_Responsibility_Assignment_Repository::SCOPE_EVENT,
			$event_id
		);
	}

	public function can_manage_vendors( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		return $this->has_global_responsibility( $user_id, self::RESP_VENDOR_MANAGEMENT );
	}

	public function can_submit_vendor_checkin( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $this->has_global_responsibility( $user_id, self::RESP_VENDOR_MANAGEMENT ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility( $user_id, self::RESP_VENDOR_SUBMIT_CHECKIN );
	}

	public function can_view_vendor_commission( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $this->has_global_responsibility( $user_id, self::RESP_VENDOR_MANAGEMENT ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility( $user_id, self::RESP_VENDOR_VIEW_COMMISSION );
	}

	public function can_manage_vendor_inventory( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $this->has_global_responsibility( $user_id, self::RESP_VENDOR_MANAGEMENT ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility( $user_id, self::RESP_VENDOR_MANAGE_INVENTORY );
	}

	public function can_manage_vendor_inventory_for_vendor( int $user_id, int $vendor_id ): bool {
		$user_id = $this->resolve_user_id( $user_id );
		$vendor_id = (int) $vendor_id;

		if ( $user_id <= 0 || $vendor_id <= 0 ) {
			return false;
		}

		if ( ! $this->is_enabled() ) {
			return true;
		}

		if ( $this->should_enforce_person_boundary( $user_id ) && ! $this->is_aims_person( $user_id ) ) {
			return false;
		}

		if ( $this->has_global_responsibility( $user_id, self::RESP_VENDOR_MANAGEMENT ) ) {
			return true;
		}

		if ( $this->assignments->user_has_responsibility( $user_id, self::RESP_VENDOR_MANAGE_INVENTORY ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility(
			$user_id,
			self::RESP_VENDOR_MANAGE_INVENTORY,
			AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR,
			$vendor_id
		);
	}

	public function can_manage_square_sync( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		return $this->has_global_responsibility( $user_id, self::RESP_SQUARE_SYNC_MANAGEMENT );
	}

	public function can_run_square_sync_replay( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $this->has_global_responsibility( $user_id, self::RESP_SQUARE_SYNC_REPLAY ) ) {
			return true;
		}

		return $this->can_manage_square_sync( $user_id );
	}

	public function can_run_square_sync_undo( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $this->has_global_responsibility( $user_id, self::RESP_SQUARE_SYNC_UNDO ) ) {
			return true;
		}

		return $this->can_manage_square_sync( $user_id );
	}

	public function can_view_reports( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		return $this->has_global_responsibility( $user_id, self::RESP_REPORTS_VIEW );
	}

	public function get_authorized_event_ids( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 || $this->can_view_all_events( $user_id ) ) {
			return array();
		}

		$event_ids = array_merge(
			$this->assignments->get_scope_ref_ids_for_user(
				$user_id,
				self::RESP_EVENT_PLANNING_ACCESS,
				AIMS_Responsibility_Assignment_Repository::SCOPE_EVENT
			),
			$this->assignments->get_scope_ref_ids_for_user(
				$user_id,
				self::RESP_EVENT_PLANNING_MUTATE,
				AIMS_Responsibility_Assignment_Repository::SCOPE_EVENT
			),
			$this->get_event_ids_for_vendor_scope(
				$user_id,
				self::RESP_EVENT_PLANNING_ACCESS
			),
			$this->get_event_ids_for_vendor_scope(
				$user_id,
				self::RESP_EVENT_PLANNING_MUTATE
			)
		);

		return array_values( array_unique( array_filter( array_map( 'intval', $event_ids ) ) ) );
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

	private function is_enabled(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$value = get_option( self::OPTION_ENABLE, '0' );

		return in_array( $value, array( '1', 1, true, 'yes', 'on' ), true );
	}

	private function has_global_responsibility( int $user_id, string $responsibility_key ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->should_enforce_person_boundary( $user_id ) && ! $this->is_aims_person( $user_id ) ) {
			return false;
		}

		if ( $this->user_has_responsibility_cap( $user_id, self::RESP_SYSTEM_ADMIN ) ) {
			return true;
		}

		if ( $this->user_has_responsibility_cap( $user_id, $responsibility_key ) ) {
			return true;
		}

		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( $this->assignments->user_has_responsibility( $user_id, self::RESP_SYSTEM_ADMIN ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility( $user_id, $responsibility_key );
	}

	private function is_aims_person( int $user_id ): bool {
		return is_object( $this->person_identity ) && $this->person_identity->is_aims_person( $user_id );
	}

	private function should_enforce_person_boundary( int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'get_user_by' ) ) {
			return false;
		}

		return is_object( get_user_by( 'id', $user_id ) );
	}

	private function get_event_ids_for_vendor_scope( int $user_id, string $responsibility_key ): array {
		$vendor_ids = $this->assignments->get_scope_ref_ids_for_user(
			$user_id,
			$responsibility_key,
			AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR
		);

		if ( empty( $vendor_ids ) ) {
			return array();
		}

		$event_ids = array();

		foreach ( $vendor_ids as $vendor_id ) {
			foreach ( (array) $this->vendor_event_assignments->get_for_vendor( (int) $vendor_id ) as $assignment ) {
				$event_id = (int) ( is_array( $assignment ) ? ( $assignment['event_id'] ?? 0 ) : 0 );
				if ( $event_id > 0 ) {
					$event_ids[] = $event_id;
				}
			}
		}

		return array_values( array_unique( $event_ids ) );
	}

	private function event_matches_authorized_vendor_scope( int $user_id, int $event_id, string $responsibility_key ): bool {
		$vendor_ids = $this->assignments->get_scope_ref_ids_for_user(
			$user_id,
			$responsibility_key,
			AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR
		);

		if ( empty( $vendor_ids ) ) {
			return false;
		}

		foreach ( (array) $this->vendor_event_assignments->get_for_event( $event_id ) as $assignment ) {
			$assignment_vendor_id = (int) ( is_array( $assignment ) ? ( $assignment['vendor_id'] ?? 0 ) : 0 );
			if ( $assignment_vendor_id > 0 && in_array( $assignment_vendor_id, $vendor_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function user_has_responsibility_cap( int $user_id, string $responsibility_key ): bool {
		$cap = AIMS_Capabilities::get_responsibility_cap_map()[ $responsibility_key ] ?? '';
		if ( $user_id <= 0 || '' === $cap ) {
			return false;
		}

		return function_exists( 'user_can' ) && user_can( $user_id, $cap );
	}
}
