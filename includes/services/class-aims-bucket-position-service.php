<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Position_Service {
	private $positions;
	private $movements;

	public function __construct( $positions, $movements = null ) {
		$this->positions  = $positions;
		$this->movements = $movements;
	}

	public function get_position( int $bucket_id, int $vendor_id, int $product_id ): ?array {
		if ( $bucket_id <= 0 || ! method_exists( $this->positions, 'find_by_bucket_vendor_product' ) ) {
			return null;
		}

		$position = $this->positions->find_by_bucket_vendor_product( $bucket_id, $vendor_id, $product_id );

		return is_array( $position ) ? $position : null;
	}

	public function get_bucket_contents( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! method_exists( $this->positions, 'get_for_bucket' ) ) {
			return array();
		}

		return (array) $this->positions->get_for_bucket( $bucket_id );
	}

	public function recalculate_position( array $data ): int {
		if ( ! method_exists( $this->positions, 'upsert_position' ) ) {
			return 0;
		}

		$record = array(
			'bucket_id'               => (int) ( $data['bucket_id'] ?? 0 ),
			'vendor_id'               => (int) ( $data['vendor_id'] ?? 0 ),
			'product_id'              => (int) ( $data['product_id'] ?? 0 ),
			'quantity'                => (float) ( $data['quantity'] ?? 0 ),
			'reserved_quantity'       => (float) ( $data['reserved_quantity'] ?? 0 ),
			'position_status'         => sanitize_key( $data['position_status'] ?? 'active' ),
			'last_bucket_movement_id' => (int) ( $data['last_bucket_movement_id'] ?? 0 ),
			'last_counted_at'         => $data['last_counted_at'] ?? null,
		);

		return (int) $this->positions->upsert_position( $record );
	}

	public function reserve_quantity( int $bucket_id, int $vendor_id, int $product_id, float $quantity ): bool {
		$position = $this->get_position( $bucket_id, $vendor_id, $product_id );
		if ( empty( $position['id'] ) || ! method_exists( $this->positions, 'adjust_reserved_quantity' ) ) {
			return false;
		}

		$next_reserved = (float) ( $position['reserved_quantity'] ?? 0 ) + $quantity;

		return (bool) $this->positions->adjust_reserved_quantity( (int) $position['id'], $next_reserved );
	}

	public function release_reservation( int $bucket_id, int $vendor_id, int $product_id, float $quantity ): bool {
		return $this->reserve_quantity( $bucket_id, $vendor_id, $product_id, 0 - $quantity );
	}
}
