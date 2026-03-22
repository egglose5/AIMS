<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Service {
	private $buckets;
	private $movements;

	public function __construct(
		AIMS_Inventory_Bucket_Repository $buckets,
		AIMS_Inventory_Movement_Repository $movements
	) {
		$this->buckets   = $buckets;
		$this->movements = $movements;
	}

	public function apply_movement( array $data ) {
		$reference_type = sanitize_key( $data['reference_type'] ?? '' );
		$reference_id   = sanitize_text_field( $data['reference_id'] ?? '' );
		$product_id     = (int) ( $data['product_id'] ?? 0 );
		$vendor_id      = (int) ( $data['vendor_id'] ?? 0 );
		$bucket_code    = sanitize_text_field( $data['bucket_code'] ?? '' );
		$movement_type  = sanitize_key( $data['movement_type'] ?? '' );
		$quantity_delta = (float) ( $data['quantity_delta'] ?? 0 );

		if ( '' === $reference_type || '' === $reference_id || $product_id <= 0 || $vendor_id <= 0 || '' === $bucket_code || '' === $movement_type || 0.0 === $quantity_delta ) {
			return new WP_Error( 'aims_invalid_inventory_movement', 'Inventory movement is missing required fields.' );
		}

		if ( $this->movements->has_reference_application( $reference_type, $reference_id, $product_id, $bucket_code, $movement_type ) ) {
			return new WP_Error( 'aims_duplicate_inventory_movement', 'This inventory movement has already been applied.' );
		}

		$existing_bucket = $this->buckets->find_bucket( $vendor_id, $product_id, $bucket_code );
		if ( empty( $existing_bucket ) ) {
			$this->buckets->upsert_bucket(
				array(
					'vendor_id'         => $vendor_id,
					'product_id'        => $product_id,
					'bucket_code'       => $bucket_code,
					'bucket_name'       => $data['bucket_name'] ?? $bucket_code,
					'quantity'          => 0,
					'reserved_quantity' => 0,
				)
			);
		}

		$movement_id = $this->movements->create( $data );
		$current_qty = $this->movements->get_total_quantity_for_bucket( $vendor_id, $product_id, $bucket_code );

		$this->buckets->upsert_bucket(
			array(
				'vendor_id'         => $vendor_id,
				'product_id'        => $product_id,
				'bucket_code'       => $bucket_code,
				'bucket_name'       => $data['bucket_name'] ?? $bucket_code,
				'quantity'          => $current_qty,
				'reserved_quantity' => ! empty( $existing_bucket['reserved_quantity'] ) ? (float) $existing_bucket['reserved_quantity'] : 0,
			)
		);

		return array(
			'movement_id'      => $movement_id,
			'current_quantity' => $current_qty,
		);
	}
}
