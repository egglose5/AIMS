<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Bucket_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_buckets';
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

		$existing = $this->find_bucket(
			(int) $data['vendor_id'],
			(int) $data['product_id'],
			(string) $data['bucket_code']
		);

		$record = array(
			'vendor_id'         => (int) $data['vendor_id'],
			'product_id'        => (int) $data['product_id'],
			'bucket_code'       => sanitize_text_field( $data['bucket_code'] ),
			'bucket_name'       => sanitize_text_field( $data['bucket_name'] ?? $data['bucket_code'] ),
			'quantity'          => number_format( (float) ( $data['quantity'] ?? 0 ), 4, '.', '' ),
			'reserved_quantity' => number_format( (float) ( $data['reserved_quantity'] ?? 0 ), 4, '.', '' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( ! empty( $existing['id'] ) ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => (int) $existing['id'] ),
				array( '%d', '%d', '%s', '%s', '%f', '%f', '%s' ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}
}

