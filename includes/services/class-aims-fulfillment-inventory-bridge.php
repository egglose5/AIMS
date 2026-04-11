<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges a fulfillment allocation record to the bucket inventory position layer.
 *
 * When a sale is replayed and fulfillment allocations are created, the bridge
 * increments the matching bucket inventory position's reserved_quantity so that
 * operators can see how much stock is committed to pending orders.  The physical
 * quantity column is left untouched — it only decreases when a real movement event
 * (vendor_event_checkin / event_return) is applied.
 */
class AIMS_Fulfillment_Inventory_Bridge {

	private $positions;

	public function __construct(
		AIMS_Bucket_Inventory_Position_Repository $positions = null
	) {
		$this->positions = $positions ?: new AIMS_Bucket_Inventory_Position_Repository();
	}

	/**
	 * Locates the active bucket inventory position for the vendor + product in
	 * $data and increments its reserved_quantity by the allocation quantity.
	 *
	 * @param int   $allocation_id  The freshly-created allocation ID.
	 * @param array $data           Must contain: vendor_id, product_id, quantity.
	 *                              May optionally contain source_bucket_id.
	 *
	 * @return array {
	 *   position_id: int — the position that was updated (0 = not found / skipped)
	 *   delta:       float — the quantity that was reserved
	 *   applied:     bool — whether the increment was written
	 * }
	 */
	public function reserve_for_allocation( int $allocation_id, array $data = array() ): array {
		$vendor_id  = (int) ( $data['vendor_id'] ?? 0 );
		$product_id = (int) ( $data['product_id'] ?? 0 );
		$quantity   = (float) ( $data['quantity'] ?? 0 );

		$noop = array(
			'allocation_id' => $allocation_id,
			'position_id'   => 0,
			'delta'         => 0.0,
			'applied'       => false,
		);

		if ( $allocation_id <= 0 || $vendor_id <= 0 || $product_id <= 0 || $quantity <= 0 ) {
			return $noop;
		}

		$position = null;

		// Prefer a position that matches source_bucket_id when available.
		$source_bucket_id = (int) ( $data['source_bucket_id'] ?? 0 );
		if ( $source_bucket_id > 0 && method_exists( $this->positions, 'find_by_bucket_vendor_product' ) ) {
			$position = $this->positions->find_by_bucket_vendor_product( $source_bucket_id, $vendor_id, $product_id );
		}

		// Fall back to a vendor + product search across all buckets.
		if ( empty( $position ) && method_exists( $this->positions, 'find_by_vendor_and_product' ) ) {
			$candidates = $this->positions->find_by_vendor_and_product( $vendor_id, $product_id );
			if ( ! empty( $candidates ) ) {
				$position = reset( $candidates );
			}
		}

		if ( empty( $position['id'] ) ) {
			return $noop;
		}

		$position_id = (int) $position['id'];

		if ( ! method_exists( $this->positions, 'increment_reserved_quantity' ) ) {
			return $noop;
		}

		$applied = $this->positions->increment_reserved_quantity( $position_id, $quantity );

		return array(
			'allocation_id' => $allocation_id,
			'position_id'   => $position_id,
			'delta'         => $quantity,
			'applied'       => $applied,
		);
	}
}
