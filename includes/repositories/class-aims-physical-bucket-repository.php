<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Physical_Bucket_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_physical_buckets';
	}

	public function save( array $data, int $bucket_id = 0 ): int {
		global $wpdb;

		$record = array(
			'bucket_code'                => sanitize_text_field( $data['bucket_code'] ?? '' ),
			'bucket_label'               => sanitize_text_field( $data['bucket_label'] ?? '' ),
			'bucket_type'                => sanitize_key( $data['bucket_type'] ?? 'standard' ),
			'status'                     => sanitize_key( $data['status'] ?? 'available' ),
			'current_storage_location_id' => (int) ( $data['current_storage_location_id'] ?? 0 ),
			'home_storage_location_id'   => (int) ( $data['home_storage_location_id'] ?? 0 ),
			'vendor_id'                  => (int) ( $data['vendor_id'] ?? 0 ),
			'barcode_value'              => sanitize_text_field( $data['barcode_value'] ?? '' ),
			'tare_weight'                => number_format( (float) ( $data['tare_weight'] ?? 0 ), 4, '.', '' ),
			'notes'                      => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'                 => current_time( 'mysql' ),
		);

		if ( $bucket_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $bucket_id ) );
			return $bucket_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $bucket_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $bucket_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_code( string $bucket_code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_code = %s', sanitize_text_field( $bucket_code ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_barcode( string $barcode ): ?array {
		global $wpdb;

		if ( '' === trim( $barcode ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE barcode_value = %s', sanitize_text_field( $barcode ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_location( int $location_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE current_storage_location_id = %d ORDER BY bucket_label ASC, bucket_code ASC, id ASC',
				$location_id
			),
			ARRAY_A
		);
	}

	public function get_for_vendor( int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d ORDER BY bucket_label ASC, bucket_code ASC, id ASC',
				$vendor_id
			),
			ARRAY_A
		);
	}

	public function update_current_location( int $bucket_id, int $location_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'current_storage_location_id' => $location_id,
				'updated_at'                  => current_time( 'mysql' ),
			),
			array( 'id' => $bucket_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function update_status( int $bucket_id, string $status ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $bucket_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
