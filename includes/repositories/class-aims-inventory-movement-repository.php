<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Movement_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_movements';
	}

	public function has_reference_application( string $reference_type, string $reference_id, int $product_id, string $bucket_code, string $movement_type, int $bucket_id = 0, int $vendor_id = 0 ): bool {
		global $wpdb;

		$bucket_code = $this->resolve_bucket_code( $bucket_id, $bucket_code, $vendor_id, $product_id );

		if ( $bucket_id > 0 ) {
			if ( '' !== $bucket_code ) {
				$count = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . $this->get_table_name() . ' WHERE reference_type = %s AND reference_id = %s AND product_id = %d AND movement_type = %s AND ( bucket_id = %d OR (( bucket_id IS NULL OR bucket_id = 0 ) AND bucket_code = %s) )',
						$reference_type,
						$reference_id,
						$product_id,
						$movement_type,
						$bucket_id,
						$bucket_code
					)
				);
			} else {
				$count = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . $this->get_table_name() . ' WHERE reference_type = %s AND reference_id = %s AND product_id = %d AND movement_type = %s AND bucket_id = %d',
						$reference_type,
						$reference_id,
						$product_id,
						$movement_type,
						$bucket_id
					)
				);
			}
		} else {
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
		}

		return ( (int) $count ) > 0;
	}

	public function create( array $data ): int {
		global $wpdb;

		$bucket_id   = ! empty( $data['bucket_id'] ) ? (int) $data['bucket_id'] : 0;
		$product_id  = (int) ( $data['product_id'] ?? 0 );
		$vendor_id   = (int) ( $data['vendor_id'] ?? 0 );
		$bucket_code = $this->resolve_bucket_code(
			$bucket_id,
			(string) ( $data['bucket_code'] ?? '' ),
			$vendor_id,
			$product_id
		);

		$record = array(
			'movement_uuid'  => sanitize_text_field( $data['movement_uuid'] ?? wp_generate_uuid4() ),
			'reference_type' => sanitize_key( $data['reference_type'] ?? '' ),
			'reference_id'   => sanitize_text_field( $data['reference_id'] ?? '' ),
			'vendor_id'      => $vendor_id,
			'event_id'       => (int) ( $data['event_id'] ?? 0 ),
			'stitch_job_id'  => (int) ( $data['stitch_job_id'] ?? 0 ),
			'product_id'     => $product_id,
			'bucket_id'      => $bucket_id > 0 ? $bucket_id : null,
			'bucket_code'    => $bucket_code,
			'movement_type'  => sanitize_key( $data['movement_type'] ?? '' ),
			'quantity_delta' => number_format( (float) ( $data['quantity_delta'] ?? 0 ), 4, '.', '' ),
			'applied_by'     => (int) ( $data['applied_by'] ?? get_current_user_id() ),
			'note'           => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'created_at'     => current_time( 'mysql' ),
		);

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function get_total_quantity_for_bucket_id( int $vendor_id, int $product_id, int $bucket_id ): float {
		global $wpdb;

		if ( $bucket_id <= 0 ) {
			return 0.0;
		}

		$bucket_code = $this->resolve_bucket_code( $bucket_id, '', $vendor_id, $product_id );

		if ( '' !== $bucket_code ) {
			$total = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(quantity_delta), 0) FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d AND product_id = %d AND ( bucket_id = %d OR (( bucket_id IS NULL OR bucket_id = 0 ) AND bucket_code = %s) )',
					$vendor_id,
					$product_id,
					$bucket_id,
					$bucket_code
				)
			);
		} else {
			$total = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(quantity_delta), 0) FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d AND product_id = %d AND bucket_id = %d',
					$vendor_id,
					$product_id,
					$bucket_id
				)
			);
		}

		return (float) $total;
	}

	public function get_total_quantity_for_bucket( int $vendor_id, int $product_id, string $bucket_code, int $bucket_id = 0 ): float {
		global $wpdb;

		if ( $bucket_id > 0 ) {
			return $this->get_total_quantity_for_bucket_id( $vendor_id, $product_id, $bucket_id );
		}

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

	private function resolve_bucket_code( int $bucket_id = 0, string $bucket_code = '', int $vendor_id = 0, int $product_id = 0 ): string {
		$bucket_code = sanitize_text_field( $bucket_code );
		if ( '' !== $bucket_code ) {
			return $bucket_code;
		}

		if ( ! class_exists( 'AIMS_Inventory_Bucket_Repository' ) ) {
			return '';
		}

		$buckets = new AIMS_Inventory_Bucket_Repository();

		return $buckets->resolve_bucket_code( $bucket_id, $bucket_code, $vendor_id, $product_id );
	}
}

