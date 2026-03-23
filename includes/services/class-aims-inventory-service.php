<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Service {
	private $buckets;
	private $movements;
	private $bucket_identity;
	private $bucket_movement_service;
	private $bucket_position_service;

	public function __construct(
		AIMS_Inventory_Bucket_Repository $buckets,
		AIMS_Inventory_Movement_Repository $movements,
		AIMS_Bucket_Identity_Service $bucket_identity = null,
		AIMS_Bucket_Movement_Service $bucket_movement_service = null,
		AIMS_Bucket_Position_Service $bucket_position_service = null
	) {
		$this->buckets                 = $buckets;
		$this->movements               = $movements;
		$this->bucket_identity         = $bucket_identity;
		$this->bucket_movement_service = $bucket_movement_service;
		$this->bucket_position_service = $bucket_position_service;
	}

	public function apply_movement( array $data ) {
		$reference_type = sanitize_key( $data['reference_type'] ?? '' );
		$reference_id   = sanitize_text_field( $data['reference_id'] ?? '' );
		$product_id     = (int) ( $data['product_id'] ?? 0 );
		$vendor_id      = (int) ( $data['vendor_id'] ?? 0 );
		$movement_type  = sanitize_key( $data['movement_type'] ?? '' );
		$quantity_delta = (float) ( $data['quantity_delta'] ?? 0 );
		$bucket_ref     = $this->normalize_bucket_reference( $data );
		$bucket_id      = (int) $bucket_ref['bucket_id'];
		$bucket_code    = (string) $bucket_ref['bucket_code'];

		if ( '' === $reference_type || '' === $reference_id || $product_id <= 0 || $vendor_id <= 0 || ( $bucket_id <= 0 && '' === $bucket_code ) || '' === $movement_type || 0.0 === $quantity_delta ) {
			return new WP_Error( 'aims_invalid_inventory_movement', 'Inventory movement is missing required fields.' );
		}

		if ( $this->movements->has_reference_application( $reference_type, $reference_id, $product_id, $bucket_code, $movement_type, $bucket_id, $vendor_id ) ) {
			return new WP_Error( 'aims_duplicate_inventory_movement', 'This inventory movement has already been applied.' );
		}

		$movement_payload = array_merge(
			$data,
			array(
				'bucket_id'   => $bucket_id,
				'bucket_code' => $bucket_code,
			)
		);

		$existing_bucket = array();
		if ( '' !== $bucket_code ) {
			$existing_bucket = $this->buckets->find_bucket( $vendor_id, $product_id, $bucket_code ) ?: array();
		}

		if ( '' !== $bucket_code && empty( $existing_bucket ) ) {
			$this->buckets->upsert_bucket(
				array(
					'vendor_id'         => $vendor_id,
					'product_id'        => $product_id,
					'bucket_id'         => $bucket_id,
					'bucket_code'       => $bucket_code,
					'bucket_name'       => $data['bucket_name'] ?? $bucket_code,
					'quantity'          => 0,
					'reserved_quantity' => 0,
				)
			);
		}

		$movement_result = is_object( $this->bucket_movement_service )
			? $this->bucket_movement_service->record_movement( $movement_payload )
			: null;

		if ( is_wp_error( $movement_result ) ) {
			return $movement_result;
		}

		$movement_id = is_array( $movement_result ) && isset( $movement_result['movement_id'] )
			? (int) $movement_result['movement_id']
			: (int) $this->movements->create( $movement_payload );

		$current_qty = $this->resolve_current_quantity(
			$movement_result,
			$vendor_id,
			$product_id,
			$bucket_id,
			$bucket_code
		);

		if ( '' !== $bucket_code ) {
			$this->buckets->upsert_bucket(
				array(
					'vendor_id'         => $vendor_id,
					'product_id'        => $product_id,
					'bucket_id'         => $bucket_id,
					'bucket_code'       => $bucket_code,
					'bucket_name'       => $data['bucket_name'] ?? $bucket_code,
					'quantity'          => $current_qty,
					'reserved_quantity' => ! empty( $existing_bucket['reserved_quantity'] ) ? (float) $existing_bucket['reserved_quantity'] : 0,
				)
			);
		}

		if ( is_object( $this->bucket_position_service ) && $bucket_id > 0 ) {
			$this->bucket_position_service->recalculate_position(
				array(
					'bucket_id'               => $bucket_id,
					'vendor_id'               => $vendor_id,
					'product_id'              => $product_id,
					'quantity'                => $current_qty,
					'reserved_quantity'       => ! empty( $existing_bucket['reserved_quantity'] ) ? (float) $existing_bucket['reserved_quantity'] : 0,
					'last_bucket_movement_id' => $movement_id,
				)
			);
		}

		return array(
			'bucket_id'        => $bucket_id,
			'bucket_code'      => $bucket_code,
			'movement_id'      => $movement_id,
			'current_quantity' => $current_qty,
		);
	}

	private function normalize_bucket_reference( array $data ): array {
		$bucket_ref = array(
			'bucket_id'   => ! empty( $data['bucket_id'] ) ? (int) $data['bucket_id'] : 0,
			'bucket_code' => sanitize_text_field( $data['bucket_code'] ?? '' ),
		);

		if ( is_object( $this->bucket_identity ) ) {
			$bucket_ref = $this->bucket_identity->normalize_bucket_reference( $bucket_ref );
		}

		return $bucket_ref;
	}

	private function resolve_current_quantity( $movement_result, int $vendor_id, int $product_id, int $bucket_id, string $bucket_code ): float {
		if ( is_array( $movement_result ) && isset( $movement_result['current_quantity'] ) ) {
			return (float) $movement_result['current_quantity'];
		}

		if ( method_exists( $this->movements, 'get_total_quantity_for_bucket' ) ) {
			return (float) $this->movements->get_total_quantity_for_bucket( $vendor_id, $product_id, $bucket_code, $bucket_id );
		}

		if ( '' !== $bucket_code ) {
			return (float) $this->movements->get_total_quantity_for_bucket( $vendor_id, $product_id, $bucket_code, 0 );
		}

		return 0.0;
	}
}
