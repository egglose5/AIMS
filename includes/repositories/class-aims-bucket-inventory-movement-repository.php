<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Inventory_Movement_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_bucket_inventory_movements';
	}

	public function resolve_bucket_identity( int $bucket_id = 0, string $bucket_code = '' ): array {
		$bucket_id   = (int) $bucket_id;
		$bucket_code = sanitize_text_field( $bucket_code );

		if ( $bucket_id <= 0 && '' === $bucket_code ) {
			return array(
				'bucket_id'   => 0,
				'bucket_code' => '',
			);
		}

		if ( class_exists( 'AIMS_Physical_Bucket_Repository' ) ) {
			$physical_buckets = new AIMS_Physical_Bucket_Repository();

			if ( $bucket_id > 0 ) {
				$bucket = $physical_buckets->find( $bucket_id );
				if ( ! empty( $bucket ) ) {
					return array(
						'bucket_id'   => (int) ( $bucket['id'] ?? 0 ),
						'bucket_code' => sanitize_text_field( (string) ( $bucket['bucket_code'] ?? $bucket_code ) ),
					);
				}
			}

			if ( '' !== $bucket_code ) {
				$bucket = $physical_buckets->find_by_code( $bucket_code );
				if ( ! empty( $bucket ) ) {
					return array(
						'bucket_id'   => (int) ( $bucket['id'] ?? $bucket_id ),
						'bucket_code' => sanitize_text_field( (string) ( $bucket['bucket_code'] ?? $bucket_code ) ),
					);
				}
			}
		}

		return array(
			'bucket_id'   => $bucket_id,
			'bucket_code' => $bucket_code,
		);
	}

	public function create( array $data ): int {
		global $wpdb;

		$identity = $this->resolve_bucket_identity(
			(int) ( $data['bucket_id'] ?? 0 ),
			(string) ( $data['bucket_code'] ?? '' )
		);

		$record = array(
			'movement_uuid'              => sanitize_text_field( $data['movement_uuid'] ?? wp_generate_uuid4() ),
			'movement_batch_id'          => (int) ( $data['movement_batch_id'] ?? 0 ),
			'reference_type'             => sanitize_key( $data['reference_type'] ?? '' ),
			'reference_id'               => sanitize_text_field( $data['reference_id'] ?? '' ),
			'bucket_id'                  => (int) ( $identity['bucket_id'] ?? 0 ),
			'vendor_id'                  => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'                   => (int) ( $data['event_id'] ?? 0 ),
			'product_id'                 => (int) ( $data['product_id'] ?? 0 ),
			'fulfillment_allocation_id'  => (int) ( $data['fulfillment_allocation_id'] ?? 0 ),
			'source_bucket_id'           => (int) ( $data['source_bucket_id'] ?? 0 ),
			'target_bucket_id'           => (int) ( $data['target_bucket_id'] ?? 0 ),
			'source_storage_location_id' => (int) ( $data['source_storage_location_id'] ?? 0 ),
			'target_storage_location_id' => (int) ( $data['target_storage_location_id'] ?? 0 ),
			'square_location_id'         => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'movement_type'              => sanitize_key( $data['movement_type'] ?? '' ),
			'quantity_delta'             => number_format( (float) ( $data['quantity_delta'] ?? 0 ), 4, '.', '' ),
			'sealed_state'               => ! empty( $data['sealed_state'] ) ? 1 : 0,
			'movement_lifecycle'         => sanitize_key( (string) ( $data['movement_lifecycle'] ?? 'hot' ) ),
			'archive_manifest_id'        => (int) ( $data['archive_manifest_id'] ?? 0 ),
			'applied_by'                 => (int) ( $data['applied_by'] ?? get_current_user_id() ),
			'note'                       => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'metadata_json'              => isset( $data['metadata_json'] ) ? wp_json_encode( $data['metadata_json'] ) : null,
			'line_meta_json'             => isset( $data['line_meta_json'] ) ? wp_json_encode( $data['line_meta_json'] ) : null,
			'created_at'                 => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $movement_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $movement_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_uuid( string $movement_uuid ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE movement_uuid = %s', sanitize_text_field( $movement_uuid ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function has_reference_application( string $reference_type, string $reference_id, int $product_id, int $bucket_id, string $movement_type ): bool {
		global $wpdb;

		if ( $bucket_id <= 0 ) {
			return false;
		}

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->get_table_name() . ' WHERE reference_type = %s AND reference_id = %s AND product_id = %d AND bucket_id = %d AND movement_type = %s',
				$reference_type,
				$reference_id,
				$product_id,
				$bucket_id,
				$movement_type
			)
		);

		return ( (int) $count ) > 0;
	}

	public function get_for_bucket( int $bucket_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_id = %d ORDER BY created_at ASC, id ASC',
				$bucket_id
			),
			ARRAY_A
		);
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY created_at ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_balance_for_bucket_product( int $bucket_id, int $vendor_id, int $product_id ): float {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(quantity_delta), 0) FROM ' . $this->get_table_name() . ' WHERE bucket_id = %d AND vendor_id = %d AND product_id = %d',
				$bucket_id,
				$vendor_id,
				$product_id
			)
		);

		return (float) $total;
	}
}
