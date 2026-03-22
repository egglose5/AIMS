<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_User_Access_Repository {
	public const ROLE_VIEWER = 'viewer';
	public const ROLE_MANAGER = 'manager';
	public const ROLE_OWNER   = 'owner';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendor_user_access';
	}

	public function save( array $data, int $access_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $access_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $access_id ),
				array( '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $access_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function grant_access( int $vendor_id, int $user_id, array $data = array() ): int {
		$existing = $this->find_by_vendor_and_user( $vendor_id, $user_id );

		return $this->save(
			array_merge(
				$data,
				array(
					'vendor_id'   => $vendor_id,
					'user_id'     => $user_id,
					'access_role' => $data['access_role'] ?? self::ROLE_MANAGER,
				)
			),
			(int) ( $existing['id'] ?? 0 )
		);
	}

	public function revoke_access( int $vendor_id, int $user_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->get_table_name(),
			array(
				'vendor_id' => $vendor_id,
				'user_id'   => $user_id,
			),
			array( '%d', '%d' )
		);

		return false !== $deleted;
	}

	public function find_by_vendor_and_user( int $vendor_id, int $user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d AND user_id = %d',
				$vendor_id,
				$user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_vendor( int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d ORDER BY access_role ASC, id ASC',
				$vendor_id
			),
			ARRAY_A
		);
	}

	public function get_for_user( int $user_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE user_id = %d ORDER BY access_role ASC, id ASC',
				$user_id
			),
			ARRAY_A
		);
	}

	public function get_vendor_ids_for_user( int $user_id ): array {
		$rows = $this->get_for_user( $user_id );

		return array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return (int) ( $row['vendor_id'] ?? 0 );
					},
					$rows
				)
			)
		);
	}

	public function user_can_view_vendor( int $vendor_id, int $user_id ): bool {
		$access = $this->find_by_vendor_and_user( $vendor_id, $user_id );

		return ! empty( $access );
	}

	public function user_can_manage_vendor( int $vendor_id, int $user_id ): bool {
		$access = $this->find_by_vendor_and_user( $vendor_id, $user_id );

		return ! empty( $access['access_role'] ) && in_array(
			(string) $access['access_role'],
			array( self::ROLE_MANAGER, self::ROLE_OWNER ),
			true
		);
	}

	private function build_record( array $data ): array {
		return array(
			'vendor_id'   => (int) ( $data['vendor_id'] ?? 0 ),
			'user_id'     => (int) ( $data['user_id'] ?? 0 ),
			'access_role' => $this->normalize_access_role( (string) ( $data['access_role'] ?? self::ROLE_MANAGER ) ),
			'updated_at'  => current_time( 'mysql' ),
		);
	}

	private function normalize_access_role( string $role ): string {
		$role = sanitize_key( $role );

		if ( in_array(
			$role,
			array(
				self::ROLE_VIEWER,
				self::ROLE_MANAGER,
				self::ROLE_OWNER,
			),
			true
		) ) {
			return $role;
		}

		return self::ROLE_MANAGER;
	}
}
