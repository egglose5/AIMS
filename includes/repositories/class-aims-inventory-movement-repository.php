<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Movement_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_movements';
	}

	public function has_reference_application( string $reference_type, string $reference_id, int $product_id, string $bucket_code, string $movement_type ): bool {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->get_table_name() . ' WHERE reference_type = %s AND reference_id = %s AND product_id = %d AND bucket_code = %s AND movement_type = %s',
				$reference_type,
				$reference_id,
				$product_id,
				$bucket_code,
				$movement_type
			)
		);

		return ( (int) $count ) > 0;
	}

	public function create( array $data ): int {
		global $wpdb;

		$record = array(
			'movement_uuid'  => sanitize_text_field( $data['movement_uuid'] ?? wp_generate_uuid4() ),
			'reference_type' => sanitize_key( $data['reference_type'] ?? '' ),
			'reference_id'   => sanitize_text_field( $data['reference_id'] ?? '' ),
			'vendor_id'      => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'       => (int) ( $data['event_id'] ?? 0 ),
			'stitch_job_id'  => (int) ( $data['stitch_job_id'] ?? 0 ),
			'product_id'     => (int) ( $data['product_id'] ?? 0 ),
			'bucket_code'    => sanitize_text_field( $data['bucket_code'] ?? '' ),
			'movement_type'  => sanitize_key( $data['movement_type'] ?? '' ),
			'quantity_delta' => number_format( (float) ( $data['quantity_delta'] ?? 0 ), 4, '.', '' ),
			'applied_by'     => (int) ( $data['applied_by'] ?? get_current_user_id() ),
			'note'           => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'created_at'     => current_time( 'mysql' ),
		);

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function get_total_quantity_for_bucket( int $vendor_id, int $product_id, string $bucket_code ): float {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(quantity_delta), 0) FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d AND product_id = %d AND bucket_code = %s',
				$vendor_id,
				$product_id,
				$bucket_code
			)
		);

		return (float) $total;
	}
}

