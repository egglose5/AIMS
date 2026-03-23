<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Bucket_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_buckets';
	}

	public function find_any_by_bucket_id( int $bucket_id ): ?array {
		global $wpdb;

		if ( $bucket_id <= 0 ) {
			return null;
		}

		$bucket = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_id = %d ORDER BY id ASC LIMIT 1',
				$bucket_id
			),
			ARRAY_A
		);

		return is_array( $bucket ) ? $bucket : null;
	}

	public function find_bucket_by_bucket_id( int $vendor_id, int $product_id, int $bucket_id ): ?array {
		global $wpdb;

		if ( $bucket_id <= 0 ) {
			return null;
		}

		$bucket = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d AND product_id = %d AND bucket_id = %d',
				$vendor_id,
				$product_id,
				$bucket_id
			),
			ARRAY_A
		);

		return is_array( $bucket ) ? $bucket : null;
	}

	public function find_bucket( int $vendor_id, int $product_id, string $bucket_code ): ?array {
		global $wpdb;

		$bucket = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d AND product_id = %d AND bucket_code = %s',
				$vendor_id,
				$product_id,
				$bucket_code
			),
			ARRAY_A
		);

		return is_array( $bucket ) ? $bucket : null;
	}

	public function upsert_bucket( array $data ): int {
		global $wpdb;

		$vendor_id   = (int) ( $data['vendor_id'] ?? 0 );
		$product_id  = (int) ( $data['product_id'] ?? 0 );
		$bucket_id   = (int) ( $data['bucket_id'] ?? 0 );
		$bucket_code = $this->resolve_bucket_code(
			$bucket_id,
			(string) ( $data['bucket_code'] ?? '' ),
			$vendor_id,
			$product_id
		);

		$existing = null;
		if ( $bucket_id > 0 ) {
			$existing = $this->find_bucket_by_bucket_id( $vendor_id, $product_id, $bucket_id );
		}

		if ( empty( $existing ) ) {
			$existing = $this->find_bucket( $vendor_id, $product_id, $bucket_code );
		}

		$record = array(
			'vendor_id'         => $vendor_id,
			'product_id'        => $product_id,
			'bucket_id'         => $bucket_id > 0 ? $bucket_id : null,
			'bucket_code'       => sanitize_text_field( $bucket_code ),
			'bucket_name'       => sanitize_text_field( $data['bucket_name'] ?? $bucket_code ),
			'quantity'          => number_format( (float) ( $data['quantity'] ?? 0 ), 4, '.', '' ),
			'reserved_quantity' => number_format( (float) ( $data['reserved_quantity'] ?? 0 ), 4, '.', '' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( ! empty( $existing['id'] ) ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => (int) $existing['id'] ),
				array( '%d', '%d', '%d', '%s', '%s', '%f', '%f', '%s' ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function resolve_bucket_code( int $bucket_id = 0, string $bucket_code = '', int $vendor_id = 0, int $product_id = 0 ): string {
		$bucket_code = sanitize_text_field( $bucket_code );
		if ( '' !== $bucket_code ) {
			return $bucket_code;
		}

		if ( $bucket_id <= 0 ) {
			return '';
		}

		if ( $vendor_id > 0 && $product_id > 0 ) {
			$existing = $this->find_bucket_by_bucket_id( $vendor_id, $product_id, $bucket_id );
			if ( ! empty( $existing['bucket_code'] ) ) {
				return sanitize_text_field( (string) $existing['bucket_code'] );
			}
		}

		$existing = $this->find_any_by_bucket_id( $bucket_id );
		if ( ! empty( $existing['bucket_code'] ) ) {
			return sanitize_text_field( (string) $existing['bucket_code'] );
		}

		if ( class_exists( 'AIMS_Physical_Bucket_Repository' ) ) {
			$physical_buckets = new AIMS_Physical_Bucket_Repository();
			$physical_bucket  = $physical_buckets->find( $bucket_id );

			if ( ! empty( $physical_bucket['bucket_code'] ) ) {
				return sanitize_text_field( (string) $physical_bucket['bucket_code'] );
			}
		}

		return '';
	}
}

