<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Movement_Events {
	// ====== ORIGIN INBOUND FLOWS ======
	// Inventory received from origin/supplier into warehouse.
	public const ORIGIN_INBOUND = 'origin_inbound';

	// ====== WAREHOUSE INTERNAL FLOWS ======
	// Physical transfer between buckets/locations within warehouse.
	public const WAREHOUSE_TRANSFER = 'warehouse_transfer';

	// ====== WAREHOUSE OUTBOUND ALLOCATIONS (to destinations) ======
	// Inventory allocated from warehouse stock to event-prepack destination.
	public const ALLOCATE_TO_EVENT_PREPACK = 'allocate_to_event_prepack';
	// Inventory allocated from warehouse stock to Woo fulfillment destination.
	public const ALLOCATE_TO_WOO_FULFILLMENT = 'allocate_to_woo_fulfillment';
	// Inventory allocated from warehouse stock to stitcher workflow.
	public const ALLOCATE_TO_STITCHER = 'allocate_to_stitcher';
	// Inventory consumed at show/event point-of-sale.
	public const SHOW_CONSUMPTION = 'show_consumption';

	// ====== RETURN FLOWS (destinations back to warehouse) ======
	// Inventory returned from event back to warehouse stock.
	public const RETURN_FROM_EVENT = 'return_from_event';
	// Inventory returned from stitcher back to warehouse stock.
	public const RETURN_FROM_STITCHER = 'return_from_stitcher';

	// ====== MANUAL ADJUSTMENTS ======
	// Manual audited adjustment (damage, count correction, reconciliation, etc).
	public const ADJUSTMENT = 'adjustment';

	public static function allowed(): array {
		return array(
			self::ORIGIN_INBOUND,
			self::WAREHOUSE_TRANSFER,
			self::ALLOCATE_TO_EVENT_PREPACK,
			self::ALLOCATE_TO_WOO_FULFILLMENT,
			self::ALLOCATE_TO_STITCHER,
			self::SHOW_CONSUMPTION,
			self::RETURN_FROM_EVENT,
			self::RETURN_FROM_STITCHER,
			self::ADJUSTMENT,
		);
	}

	public static function is_allowed( string $movement_type ): bool {
		return in_array( sanitize_key( $movement_type ), self::allowed(), true );
	}

	public static function allowed_references_for_movement( string $movement_type ): array {
		$movement_type = sanitize_key( $movement_type );
		$matrix        = self::reference_matrix();

		return isset( $matrix[ $movement_type ] ) ? $matrix[ $movement_type ] : array();
	}

	public static function is_allowed_reference_for_movement( string $movement_type, string $reference_type ): bool {
		$movement_type  = sanitize_key( $movement_type );
		$reference_type = sanitize_key( $reference_type );
		$allowed_refs   = self::allowed_references_for_movement( $movement_type );

		return in_array( $reference_type, $allowed_refs, true );
	}

	private static function reference_matrix(): array {
		return array(
			self::ORIGIN_INBOUND            => array( 'inbound_receipt', 'purchase_order', 'supplier_delivery' ),
			self::WAREHOUSE_TRANSFER        => array( 'bucket_transfer', 'location_transfer' ),
			self::ALLOCATE_TO_EVENT_PREPACK => array( 'event_prepack_pickup', 'vendor_event_checkin' ),
			self::ALLOCATE_TO_WOO_FULFILLMENT => array( 'woo_order_fulfillment', 'fulfillment_pickup_ticket' ),
			self::ALLOCATE_TO_STITCHER      => array( 'stitch_job_handoff' ),
			self::SHOW_CONSUMPTION          => array( 'square_sale_line', 'square_order', 'pos_transaction' ),
			self::RETURN_FROM_EVENT         => array( 'event_return', 'vendor_event_return' ),
			self::RETURN_FROM_STITCHER      => array( 'stitch_job_return', 'stitch_completion' ),
			self::ADJUSTMENT                => array( 'manual_adjustment', 'physical_count', 'reconciliation', 'damage_writeoff' ),
		);
	}
}

