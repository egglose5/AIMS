<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Inventory_Position_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_bucket_inventory_positions';
	}

	public function save( array $data, int $position_id = 0 ): int {
		global $wpdb;

		$record = array(
			'bucket_id'               => (int) ( $data['bucket_id'] ?? 0 ),
			'vendor_id'               => (int) ( $data['vendor_id'] ?? 0 ),
			'product_id'              => (int) ( $data['product_id'] ?? 0 ),
			'quantity'                => number_format( (float) ( $data['quantity'] ?? 0 ), 4, '.', '' ),
			'reserved_quantity'       => number_format( (float) ( $data['reserved_quantity'] ?? 0 ), 4, '.', '' ),
			'position_status'         => sanitize_key( $data['position_status'] ?? 'active' ),
			'last_bucket_movement_id' => (int) ( $data['last_bucket_movement_id'] ?? 0 ),
			'last_counted_at'         => $this->normalize_datetime( $data['last_counted_at'] ?? null ),
			'updated_at'              => current_time( 'mysql' ),
		);

		if ( $position_id <= 0 ) {
			$existing = $this->find_by_bucket_vendor_product( $record['bucket_id'], $record['vendor_id'], $record['product_id'] );
			if ( ! empty( $existing['id'] ) ) {
				$position_id = (int) $existing['id'];
			}
		}

		if ( $position_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $position_id ) );
			return $position_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $position_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $position_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_bucket_vendor_product( int $bucket_id, int $vendor_id, int $product_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_id = %d AND vendor_id = %d AND product_id = %d LIMIT 1',
				$bucket_id,
				$vendor_id,
				$product_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_bucket( int $bucket_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_id = %d ORDER BY vendor_id ASC, product_id ASC, id ASC',
				$bucket_id
			),
			ARRAY_A
		);
	}

	public function upsert_position( array $data ): int {
		return $this->save( $data );
	}

	public function adjust_reserved_quantity( int $position_id, float $reserved_quantity ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'reserved_quantity' => number_format( $reserved_quantity, 4, '.', '' ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $position_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
