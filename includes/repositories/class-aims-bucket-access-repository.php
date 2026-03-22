<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Access_Repository {
	public const ROLE_VIEWER = 'viewer';
	public const ROLE_SUPERVISOR = 'supervisor';
	public const ROLE_MANAGER = 'manager';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_bucket_user_access';
	}

	public function save( array $data, int $access_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $access_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $access_id ),
				array( '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);

			return $access_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function grant_access( int $bucket_id, int $user_id, array $data = array() ): int {
		$existing = $this->find_by_bucket_and_user( $bucket_id, $user_id );

		return $this->save(
			array_merge(
				$data,
				array(
					'bucket_id'            => $bucket_id,
					'user_id'              => $user_id,
					'access_role'          => $data['access_role'] ?? self::ROLE_SUPERVISOR,
					'can_view'             => $data['can_view'] ?? 1,
					'can_adjust_inventory' => $data['can_adjust_inventory'] ?? 1,
					'can_transfer'         => $data['can_transfer'] ?? 0,
				)
			),
			(int) ( $existing['id'] ?? 0 )
		);
	}

	public function revoke_access( int $bucket_id, int $user_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->get_table_name(),
			array(
				'bucket_id' => $bucket_id,
				'user_id'   => $user_id,
			),
			array( '%d', '%d' )
		);

		return false !== $deleted;
	}

	public function find_by_bucket_and_user( int $bucket_id, int $user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_id = %d AND user_id = %d',
				$bucket_id,
				$user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_bucket( int $bucket_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_id = %d ORDER BY access_role ASC, id ASC',
				$bucket_id
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

	public function get_bucket_ids_for_user( int $user_id ): array {
		$rows = $this->get_for_user( $user_id );

		return array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return (int) ( $row['bucket_id'] ?? 0 );
					},
					$rows
				)
			)
		);
	}

	public function get_user_ids_for_bucket( int $bucket_id ): array {
		$rows = $this->get_for_bucket( $bucket_id );

		return array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return (int) ( $row['user_id'] ?? 0 );
					},
					$rows
				)
			)
		);
	}

	public function has_access( int $bucket_id, int $user_id ): bool {
		return ! empty( $this->find_by_bucket_and_user( $bucket_id, $user_id ) );
	}

	public function user_can_view_bucket( int $bucket_id, int $user_id ): bool {
		$access = $this->find_by_bucket_and_user( $bucket_id, $user_id );

		return ! empty( $access['can_view'] ) && (int) $access['can_view'] > 0;
	}

	public function user_can_adjust_bucket( int $bucket_id, int $user_id ): bool {
		$access = $this->find_by_bucket_and_user( $bucket_id, $user_id );

		return ! empty( $access['can_adjust_inventory'] ) && (int) $access['can_adjust_inventory'] > 0;
	}

	public function user_can_transfer_bucket( int $bucket_id, int $user_id ): bool {
		$access = $this->find_by_bucket_and_user( $bucket_id, $user_id );

		return ! empty( $access['can_transfer'] ) && (int) $access['can_transfer'] > 0;
	}

	public function get_access_model_for_bucket( int $bucket_id ): array {
		$rows = $this->get_for_bucket( $bucket_id );

		return array(
			'bucket_id'          => $bucket_id,
			'access_count'       => count( $rows ),
			'viewer_count'       => count(
				array_filter(
					$rows,
					static function ( $row ) {
						return self::ROLE_VIEWER === ( $row['access_role'] ?? '' );
					}
				)
			),
			'supervisor_count'   => count(
				array_filter(
					$rows,
					static function ( $row ) {
						return self::ROLE_SUPERVISOR === ( $row['access_role'] ?? '' );
					}
				)
			),
			'manager_count'      => count(
				array_filter(
					$rows,
					static function ( $row ) {
						return self::ROLE_MANAGER === ( $row['access_role'] ?? '' );
					}
				)
			),
			'has_adjust_access'  => ! empty(
				array_filter(
					$rows,
					static function ( $row ) {
						return ! empty( $row['can_adjust_inventory'] );
					}
				)
			),
			'has_transfer_access' => ! empty(
				array_filter(
					$rows,
					static function ( $row ) {
						return ! empty( $row['can_transfer'] );
					}
				)
			),
		);
	}

	private function build_record( array $data ): array {
		return array(
			'bucket_id'            => (int) ( $data['bucket_id'] ?? 0 ),
			'user_id'              => (int) ( $data['user_id'] ?? 0 ),
			'access_role'          => $this->normalize_access_role( (string) ( $data['access_role'] ?? self::ROLE_SUPERVISOR ) ),
			'can_view'             => $this->normalize_flag( $data['can_view'] ?? 1 ),
			'can_adjust_inventory' => $this->normalize_flag( $data['can_adjust_inventory'] ?? 1 ),
			'can_transfer'         => $this->normalize_flag( $data['can_transfer'] ?? 0 ),
			'notes'                => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'           => current_time( 'mysql' ),
		);
	}

	private function normalize_access_role( string $role ): string {
		$role = sanitize_key( $role );

		if ( in_array(
			$role,
			array(
				self::ROLE_VIEWER,
				self::ROLE_SUPERVISOR,
				self::ROLE_MANAGER,
			),
			true
		) ) {
			return $role;
		}

		return self::ROLE_SUPERVISOR;
	}

	private function normalize_flag( $value ): int {
		return ! empty( $value ) ? 1 : 0;
	}
}
