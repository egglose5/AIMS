<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Storage_Location_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_storage_locations';
	}

	public function save( array $data, int $location_id = 0 ): int {
		global $wpdb;

		$record = array(
			'location_code'     => sanitize_key( $data['location_code'] ?? '' ),
			'location_name'     => sanitize_text_field( $data['location_name'] ?? '' ),
			'location_type'     => sanitize_key( $data['location_type'] ?? 'bin' ),
			'parent_location_id' => (int) ( $data['parent_location_id'] ?? 0 ),
			'sort_order'        => (int) ( $data['sort_order'] ?? 0 ),
			'is_pickable'       => ! empty( $data['is_pickable'] ) ? 1 : 0,
			'is_staging'        => ! empty( $data['is_staging'] ) ? 1 : 0,
			'status'            => sanitize_key( $data['status'] ?? 'active' ),
			'barcode_value'     => sanitize_text_field( $data['barcode_value'] ?? '' ),
			'notes'             => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( $location_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $location_id ) );
			return $location_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $location_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $location_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_code( string $location_code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE location_code = %s', sanitize_key( $location_code ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_children( int $parent_location_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE parent_location_id = %d ORDER BY sort_order ASC, location_name ASC, id ASC',
				$parent_location_id
			),
			ARRAY_A
		);
	}

	public function get_active_by_type( string $location_type ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE location_type = %s AND status = %s ORDER BY location_name ASC, id ASC',
				sanitize_key( $location_type ),
				'active'
			),
			ARRAY_A
		);
	}
}
