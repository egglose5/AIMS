<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Movement_Events {
	// Inbound inventory to a bucket.
	public const STOCK_IN = 'stock_in';
	// Outbound inventory from a bucket.
	public const STOCK_OUT = 'stock_out';
	// Physical transfer between buckets/locations.
	public const TRANSFER = 'transfer';
	// Inventory moved from storage to event floor.
	public const EVENT_LOAD_OUT = 'event_load_out';
	// Inventory physically returned from event.
	public const EVENT_RETURN = 'event_return';
	// Inventory handed to stitch workflow.
	public const STITCHER_HANDOFF = 'stitcher_handoff';
	// Inventory returned from stitch workflow.
	public const STITCHER_RETURN = 'stitcher_return';
	// Inventory consumed by a Square sale.
	public const SQUARE_SALE = 'square_sale';
	// Inventory consumed by WooCommerce fulfillment.
	public const WOOCOMMERCE_FULFILLMENT = 'woocommerce_fulfillment';
	// Inventory physically picked for a fulfillment run.
	public const WAREHOUSE_PICK = 'warehouse_pick';
	// Manual audited adjustment (damage, count correction, etc).
	public const ADJUSTMENT = 'adjustment';

	public static function allowed(): array {
		return array(
			self::STOCK_IN,
			self::STOCK_OUT,
			self::TRANSFER,
			self::EVENT_LOAD_OUT,
			self::EVENT_RETURN,
			self::STITCHER_HANDOFF,
			self::STITCHER_RETURN,
			self::SQUARE_SALE,
			self::WOOCOMMERCE_FULFILLMENT,
			self::WAREHOUSE_PICK,
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
			self::STOCK_IN               => array( 'inbound_receipt', 'manual_adjustment', 'physical_count' ),
			self::STOCK_OUT              => array( 'manual_adjustment', 'physical_count', 'shrinkage' ),
			self::TRANSFER               => array( 'bucket_transfer', 'location_transfer' ),
			self::EVENT_LOAD_OUT         => array( 'vendor_event_checkin', 'event_execution' ),
			self::EVENT_RETURN           => array( 'vendor_event_return', 'event_execution' ),
			self::STITCHER_HANDOFF       => array( 'stitch_job_handoff' ),
			self::STITCHER_RETURN        => array( 'stitch_job_return' ),
			self::SQUARE_SALE            => array( 'square_sale_line', 'square_order' ),
			self::WOOCOMMERCE_FULFILLMENT => array( 'woo_fulfillment_line', 'woo_order_fulfillment' ),
			self::WAREHOUSE_PICK         => array( 'woo_order_fulfillment', 'warehouse_pick_ticket' ),
			self::ADJUSTMENT             => array( 'manual_adjustment', 'physical_count', 'reconciliation' ),
		);
	}
}

