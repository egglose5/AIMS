<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Responsibility_Assignment_Repository {
	public const SCOPE_GLOBAL           = 'global';
	public const SCOPE_EVENT            = 'event';
	public const SCOPE_VENDOR           = 'vendor';
	public const SCOPE_CUSTODY          = 'custody';
	public const SCOPE_SUBORDINATE_TREE = 'subordinate_tree';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_user_responsibilities';
	}

	public function get_active_assignments_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE user_id = %d AND is_active = 1 AND revoked_at IS NULL ORDER BY id ASC',
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$assignments = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$responsibility_key = sanitize_key( (string) ( $row['responsibility_key'] ?? '' ) );
			if ( '' === $responsibility_key ) {
				continue;
			}

			$assignments[] = $row;
		}

		return $assignments;
	}

	public function has_active_assignments_for_user( int $user_id ): bool {
		return ! empty( $this->get_active_assignments_for_user( $user_id ) );
	}

	public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
		$responsibility_key = sanitize_key( $responsibility_key );
		$scope_type         = sanitize_key( $scope_type );

		if ( '' === $responsibility_key || $user_id <= 0 ) {
			return false;
		}

		foreach ( $this->get_active_assignments_for_user( $user_id ) as $assignment ) {
			$assigned_key      = sanitize_key( (string) ( $assignment['responsibility_key'] ?? '' ) );
			$assigned_scope    = sanitize_key( (string) ( $assignment['scope_type'] ?? self::SCOPE_GLOBAL ) );
			$assigned_scope_id = (int) ( $assignment['scope_ref_id'] ?? 0 );

			if ( $assigned_key !== $responsibility_key ) {
				continue;
			}

			if ( self::SCOPE_GLOBAL === $assigned_scope ) {
				return true;
			}

			if ( $assigned_scope !== $scope_type ) {
				continue;
			}

			if ( $assigned_scope_id <= 0 || $scope_ref_id <= 0 ) {
				return true;
			}

			if ( $assigned_scope_id === $scope_ref_id ) {
				return true;
			}
		}

		return false;
	}

	public function get_scope_ref_ids_for_user( int $user_id, string $responsibility_key, string $scope_type ): array {
		$responsibility_key = sanitize_key( $responsibility_key );
		$scope_type         = sanitize_key( $scope_type );

		if ( '' === $responsibility_key || '' === $scope_type || $user_id <= 0 ) {
			return array();
		}

		$scope_ref_ids = array();

		foreach ( $this->get_active_assignments_for_user( $user_id ) as $assignment ) {
			$assigned_key   = sanitize_key( (string) ( $assignment['responsibility_key'] ?? '' ) );
			$assigned_scope = sanitize_key( (string) ( $assignment['scope_type'] ?? self::SCOPE_GLOBAL ) );

			if ( $assigned_key !== $responsibility_key || $assigned_scope !== $scope_type ) {
				continue;
			}

			$scope_ref_id = (int) ( $assignment['scope_ref_id'] ?? 0 );
			if ( $scope_ref_id > 0 ) {
				$scope_ref_ids[] = $scope_ref_id;
			}
		}

		return array_values( array_unique( $scope_ref_ids ) );
	}

	/**
	 * Returns true if the user has any active assignment with the given scope_type
	 * and scope_ref_id, regardless of responsibility_key.
	 */
	public function has_scope_for_user( int $user_id, string $scope_type, int $scope_ref_id ): bool {
		$scope_type   = sanitize_key( $scope_type );
		$scope_ref_id = (int) $scope_ref_id;

		if ( $user_id <= 0 || '' === $scope_type || $scope_ref_id <= 0 ) {
			return false;
		}

		foreach ( $this->get_active_assignments_for_user( $user_id ) as $assignment ) {
			$assigned_scope = sanitize_key( (string) ( $assignment['scope_type'] ?? '' ) );
			$assigned_ref   = (int) ( $assignment['scope_ref_id'] ?? 0 );

			if ( $assigned_scope === $scope_type && $assigned_ref === $scope_ref_id ) {
				return true;
			}
		}

		return false;
	}

	public function get_user_ids_for_responsibility( string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): array {
		global $wpdb;

		$responsibility_key = sanitize_key( $responsibility_key );
		$scope_type         = sanitize_key( $scope_type );

		if ( '' === $responsibility_key ) {
			return array();
		}

		if ( $scope_ref_id > 0 ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT user_id FROM ' . $this->get_table_name() . ' WHERE responsibility_key = %s AND scope_type = %s AND scope_ref_id = %d AND is_active = 1 AND revoked_at IS NULL',
					$responsibility_key,
					$scope_type,
					$scope_ref_id
				)
			);
		} elseif ( self::SCOPE_GLOBAL !== $scope_type ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT user_id FROM ' . $this->get_table_name() . ' WHERE responsibility_key = %s AND scope_type = %s AND is_active = 1 AND revoked_at IS NULL',
					$responsibility_key,
					$scope_type
				)
			);
		} else {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT user_id FROM ' . $this->get_table_name() . ' WHERE responsibility_key = %s AND is_active = 1 AND revoked_at IS NULL',
					$responsibility_key
				)
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map( 'intval', $rows ) );
	}
}