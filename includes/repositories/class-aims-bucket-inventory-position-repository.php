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

	public function get_bucket_contents_summary( int $bucket_id ): array {
		$summary = array();

		foreach ( $this->get_for_bucket( $bucket_id ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = (int) ( $row['product_id'] ?? 0 );
			$vendor_id   = (int) ( $row['vendor_id'] ?? 0 );
			$key         = $vendor_id . ':' . $product_id;

			if ( ! isset( $summary[ $key ] ) ) {
				$summary[ $key ] = array(
					'bucket_id'               => (int) ( $row['bucket_id'] ?? $bucket_id ),
					'vendor_id'               => $vendor_id,
					'product_id'              => $product_id,
					'quantity'                => 0.0,
					'reserved_quantity'       => 0.0,
					'position_status'         => sanitize_key( (string) ( $row['position_status'] ?? 'active' ) ),
					'last_bucket_movement_id' => (int) ( $row['last_bucket_movement_id'] ?? 0 ),
					'last_counted_at'         => $this->normalize_datetime( $row['last_counted_at'] ?? null ),
				);
			}

			$summary[ $key ]['quantity'] += (float) ( $row['quantity'] ?? 0 );
			$summary[ $key ]['reserved_quantity'] += (float) ( $row['reserved_quantity'] ?? 0 );
			$summary[ $key ]['last_bucket_movement_id'] = max(
				(int) $summary[ $key ]['last_bucket_movement_id'],
				(int) ( $row['last_bucket_movement_id'] ?? 0 )
			);

			$row_counted_at = $this->normalize_datetime( $row['last_counted_at'] ?? null );
			if ( '' !== (string) $row_counted_at ) {
				$existing_counted_at = (string) ( $summary[ $key ]['last_counted_at'] ?? '' );
				if ( '' === $existing_counted_at || strcmp( $row_counted_at, $existing_counted_at ) > 0 ) {
					$summary[ $key ]['last_counted_at'] = $row_counted_at;
				}
			}

			if ( 'active' === sanitize_key( (string) ( $row['position_status'] ?? '' ) ) ) {
				$summary[ $key ]['position_status'] = 'active';
			}
		}

		return array_values( $summary );
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
