<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Sale_Fulfillment_Allocation_Repository {
	public const STATUS_ALLOCATED = 'allocated';
	public const STATUS_PENDING = 'pending';
	public const STATUS_PICKED = 'picked';
	public const STATUS_SHIPPED = 'shipped';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_BACKORDERED = 'backordered';

	public const ALLOCATION_EVENT_STOCK = 'event_stock';
	public const ALLOCATION_WAREHOUSE_BACKORDER = 'warehouse_backorder';
	public const ALLOCATION_WAREHOUSE_PICK = 'warehouse_pick';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_sale_fulfillment_allocations';
	}

	public function allowed_statuses(): array {
		return array(
			self::STATUS_ALLOCATED,
			self::STATUS_PENDING,
			self::STATUS_PICKED,
			self::STATUS_SHIPPED,
			self::STATUS_CANCELLED,
			self::STATUS_BACKORDERED,
		);
	}

	public function allowed_allocation_types(): array {
		return array(
			self::ALLOCATION_EVENT_STOCK,
			self::ALLOCATION_WAREHOUSE_BACKORDER,
			self::ALLOCATION_WAREHOUSE_PICK,
		);
	}

	public function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, $this->allowed_statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	public function normalize_allocation_type( string $allocation_type ): string {
		$allocation_type = sanitize_key( $allocation_type );

		return in_array( $allocation_type, $this->allowed_allocation_types(), true ) ? $allocation_type : self::ALLOCATION_EVENT_STOCK;
	}

	public function save( array $data, int $allocation_id = 0 ): int {
		global $wpdb;

		$record = array(
			'square_sale_id'     => (int) ( $data['square_sale_id'] ?? 0 ),
			'square_order_id'    => sanitize_text_field( $data['square_order_id'] ?? '' ),
			'product_id'         => (int) ( $data['product_id'] ?? 0 ),
			'vendor_id'          => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'           => (int) ( $data['event_id'] ?? 0 ),
			'source_bucket_id'   => ! empty( $data['source_bucket_id'] ) ? (int) $data['source_bucket_id'] : null,
			'source_bucket_code' => sanitize_text_field( $data['source_bucket_code'] ?? '' ),
			'allocation_type'    => $this->normalize_allocation_type( (string) ( $data['allocation_type'] ?? self::ALLOCATION_EVENT_STOCK ) ),
			'allocation_status'  => $this->normalize_status( (string) ( $data['allocation_status'] ?? self::STATUS_ALLOCATED ) ),
			'quantity'           => number_format( (float) ( $data['quantity'] ?? 0 ), 4, '.', '' ),
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $allocation_id <= 0 ) {
			$existing = null;

			if ( ! empty( $record['square_sale_id'] ) ) {
				$existing = $this->find_by_square_sale_id( (int) $record['square_sale_id'] );
			}

			if ( empty( $existing ) ) {
				$existing = $this->find_existing_allocation( $record );
			}

			if ( ! empty( $existing['id'] ) ) {
				$allocation_id = (int) $existing['id'];
			}
		}

		if ( $allocation_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $allocation_id ),
				array( '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s' ),
				array( '%d' )
			);

			return $allocation_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates only the allocation_status column for the given allocation_id.
	 * Returns false when the status value is not in the allowed list or on DB error.
	 */
	public function update_status( int $allocation_id, string $to_status ): bool {
		global $wpdb;

		$to_status = $this->normalize_status( $to_status );
		if ( $allocation_id <= 0 || '' === $to_status ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'allocation_status' => $to_status,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $allocation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function find_by_square_sale_id( int $square_sale_id ): ?array {
		global $wpdb;

		if ( $square_sale_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_sale_id = %d ORDER BY id DESC LIMIT 1',
				$square_sale_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function find_existing_allocation( array $record ): ?array {
		global $wpdb;

		if ( '' === $record['square_order_id'] || (int) $record['product_id'] <= 0 ) {
			return null;
		}

		$query = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_order_id = %s AND product_id = %d AND vendor_id = %d AND event_id = %d AND allocation_type = %s';
		$args  = array(
			$record['square_order_id'],
			(int) $record['product_id'],
			(int) $record['vendor_id'],
			(int) $record['event_id'],
			(string) $record['allocation_type'],
		);

		$source_bucket_id = isset( $record['source_bucket_id'] ) ? (int) $record['source_bucket_id'] : 0;
		if ( $source_bucket_id > 0 ) {
			$query .= ' AND source_bucket_id = %d';
			$args[] = $source_bucket_id;
		} elseif ( '' !== (string) ( $record['source_bucket_code'] ?? '' ) ) {
			$query .= ' AND source_bucket_code = %s';
			$args[] = (string) $record['source_bucket_code'];
		}

		$query .= ' ORDER BY id DESC LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $query, $args ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
