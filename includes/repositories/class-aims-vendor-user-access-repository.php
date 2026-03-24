<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_User_Access_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendor_user_access';
	}

	public function all(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT * FROM ' . $this->get_table_name() . ' ORDER BY vendor_id ASC, user_id ASC, id ASC',
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function find( int $access_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d LIMIT 1',
				$access_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE user_id = %d ORDER BY vendor_id ASC, id ASC',
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function get_vendor_ids_for_user( int $user_id ): array {
		$vendor_ids = array();

		foreach ( $this->get_for_user( $user_id ) as $row ) {
			$vendor_id = (int) ( $row['vendor_id'] ?? 0 );
			if ( $vendor_id > 0 ) {
				$vendor_ids[] = $vendor_id;
			}
		}

		return array_values( array_unique( $vendor_ids ) );
	}

	public function get_user_ids_for_vendor( int $vendor_id ): array {
		global $wpdb;

		if ( $vendor_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d ORDER BY user_id ASC',
				$vendor_id
			),
			ARRAY_A
		);

		$user_ids = array();

		foreach ( (array) $rows as $row ) {
			$user_id = (int) ( $row['user_id'] ?? 0 );
			if ( $user_id > 0 ) {
				$user_ids[] = $user_id;
			}
		}

		return array_values( array_unique( $user_ids ) );
	}

	public function save( array $data, int $access_id = 0 ): int {
		global $wpdb;

		$record = array(
			'vendor_id'   => (int) ( $data['vendor_id'] ?? 0 ),
			'user_id'     => (int) ( $data['user_id'] ?? 0 ),
			'access_role' => sanitize_key( $data['access_role'] ?? 'viewer' ),
			'updated_at'  => current_time( 'mysql' ),
		);

		if ( $access_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $access_id ),
				array( '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);

			return $access_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}
}
