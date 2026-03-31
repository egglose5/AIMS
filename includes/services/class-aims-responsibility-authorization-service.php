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
	public const RESP_SQUARE_SYNC_MANAGEMENT   = 'square_sync_management';
	public const RESP_SQUARE_SYNC_REPLAY       = 'square_sync_replay';
	public const RESP_SQUARE_SYNC_UNDO         = 'square_sync_undo';
	public const RESP_REPORTS_VIEW             = 'reports_view';

	private $assignments;
	private $vendor_event_assignments;

	public function __construct(
		AIMS_Responsibility_Assignment_Repository $assignments = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null
	) {
		$this->assignments = $assignments ?: new AIMS_Responsibility_Assignment_Repository();
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
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
		if ( $user_id <= 0 || ! $this->is_enabled() ) {
			return false;
		}

		if ( $this->assignments->user_has_responsibility( $user_id, self::RESP_SYSTEM_ADMIN ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility( $user_id, $responsibility_key );
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
}