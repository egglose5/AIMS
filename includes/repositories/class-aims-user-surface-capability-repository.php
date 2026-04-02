<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_User_Surface_Capability_Repository {
	public const MODE_ALLOW = 'allow';
	public const MODE_DENY  = 'deny';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_user_surface_capabilities';
	}

	public function get_active_rules_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE user_id = %d AND is_active = 1 ORDER BY id ASC',
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function save_rule( array $data ): int {
		global $wpdb;

		$now     = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$row     = $this->normalize_rule_row( $data, $now );
		$rule_id = $this->find_existing_rule_id(
			(int) $row['user_id'],
			(string) $row['capability_key'],
			(string) $row['surface'],
			(string) $row['scope_type'],
			(int) $row['scope_ref_id']
		);

		if ( $rule_id > 0 ) {
			$update = $row;
			unset( $update['created_at'] );
			$wpdb->update( $this->get_table_name(), $update, array( 'id' => $rule_id ) );

			return $rule_id;
		}

		$wpdb->insert( $this->get_table_name(), $row );

		return (int) $wpdb->insert_id;
	}

	public function delete_rule( int $rule_id ): bool {
		global $wpdb;

		if ( $rule_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$rule_id
			)
		);
	}

	private function find_existing_rule_id( int $user_id, string $capability_key, string $surface, string $scope_type, int $scope_ref_id ): int {
		global $wpdb;

		if ( $user_id <= 0 || '' === $capability_key || '' === $surface || '' === $scope_type ) {
			return 0;
		}

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $this->get_table_name() . ' WHERE user_id = %d AND capability_key = %s AND surface = %s AND scope_type = %s AND scope_ref_id = %d',
				$user_id,
				$capability_key,
				$surface,
				$scope_type,
				$scope_ref_id
			)
		);

		return (int) $existing_id;
	}

	private function normalize_rule_row( array $data, string $now ): array {
		$mode = sanitize_key( (string) ( $data['access_mode'] ?? self::MODE_DENY ) );
		if ( ! in_array( $mode, array( self::MODE_ALLOW, self::MODE_DENY ), true ) ) {
			$mode = self::MODE_DENY;
		}

		return array(
			'user_id'        => (int) ( $data['user_id'] ?? 0 ),
			'capability_key' => sanitize_key( (string) ( $data['capability_key'] ?? '' ) ),
			'surface'        => sanitize_key( (string) ( $data['surface'] ?? '' ) ),
			'scope_type'     => sanitize_key( (string) ( $data['scope_type'] ?? AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL ) ),
			'scope_ref_id'   => (int) ( $data['scope_ref_id'] ?? 0 ),
			'access_mode'    => $mode,
			'is_active'      => ! empty( $data['is_active'] ) ? 1 : 0,
			'note'           => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
			'created_at'     => (string) ( $data['created_at'] ?? $now ),
			'updated_at'     => $now,
		);
	}
}
