<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AIMS Physical Bucket Types
 *
 * Defines the canonical bucket types representing different inventory states and purposes.
 * Each bucket type represents a distinct operational domain with specific constraints and workflows.
 *
 * Bucket types form the foundation of inventory partitioning: inventory never changes type
 * through direct updates (which are forbidden per movement-based authority). Instead, movements
 * between locations implicitly change bucket_type when the destination location maps to a different type.
 *
 * @package AIMS
 */
class AIMS_Physical_Bucket_Types {

	// ===== WAREHOUSE STAGING & TRIAGE BUCKETS =====
	/**
	 * Normal sellable warehouse inventory - the default, primary warehouse stock location.
	 * This is the source for all outbound allocations (event-prepack, Woo fulfillment, stitcher, shows).
	 */
	public const WAREHOUSE_STOCK = 'warehouse_stock';

	/**
	 * Event-specific separated inventory pulled from warehouse_stock in advance of an event.
	 * Physically isolated to avoid cross-contamination of backstock with event stock.
	 */
	public const WAREHOUSE_EVENT_PREPACK = 'warehouse_event_prepack';

	// ===== CUSTODY & DESTINATION BUCKETS =====
	/**
	 * Items pulled and staged for Woo order fulfillment.
	 * Intermediate holding state between warehouse_stock allocation and actual shipping/handoff.
	 */
	public const WOO_FULFILLMENT = 'woo_fulfillment';

	/**
	 * Inventory physically at a show/event (POS location).
	 * Represents live inventory available for demonstration and point-of-sale transactions.
	 */
	public const SHOW_LIVE = 'show_live';

	/**
	 * Inventory in custody of a stitcher (embroidery, customization, alteration vendor).
	 * Represents work-in-progress or completed custom work awaiting return to warehouse.
	 */
	public const STITCHER = 'stitcher';

	// ===== RETURN & RECONCILIATION BUCKETS =====
	/**
	 * Items returned from show/event awaiting inspection, sorting, and re-stocking decision.
	 * Intermediate holding state for returned event inventory (not yet cleared for warehouse_stock).
	 */
	public const RETURN_RECONCILIATION = 'return_reconciliation';

	/**
	 * Damaged, blemished, or defective stock awaiting disposition (scrap, write-off, or repair).
	 * Inventory quarantined from normal fulfillment workflows.
	 */
	public const DAMAGE_HOLD = 'damage_hold';

	// ===== VIRTUAL LEDGER BUCKETS (system tracking, not physical) =====
	/**
	 * Sold/consumed stock - virtual ledger bucket representing inventory removed via point-of-sale.
	 * Used to track sold quantities at shows/events without requiring physical bucket movement.
	 */
	public const CONSUMED_VIRTUAL = 'consumed_virtual';

	/**
	 * Lost or missing inventory - virtual ledger bucket for shrinkage and unaccounted losses.
	 * Used to track net loss (e.g., from inventory audits or damage write-offs).
	 */
	public const SHRINK_VIRTUAL = 'shrink_virtual';

	/**
	 * Production/work order virtual bucket - inventory allocated for stitcher customization work.
	 * Represents work orders created but not yet handed off to actual stitcher custody bucket.
	 * Asymmetric: quantities created here via work order creation; transferred to STITCHER on handoff.
	 */
	public const PRODUCTION_VIRTUAL = 'production_virtual';

	/**
	 * Get list of all allowed bucket types.
	 *
	 * @return array Indexed array of allowed bucket type keys.
	 */
	public static function allowed(): array {
		return array(
			self::WAREHOUSE_STOCK,
			self::WAREHOUSE_EVENT_PREPACK,
			self::WOO_FULFILLMENT,
			self::SHOW_LIVE,
			self::STITCHER,
			self::RETURN_RECONCILIATION,
			self::DAMAGE_HOLD,
			self::CONSUMED_VIRTUAL,
			self::SHRINK_VIRTUAL,
			self::PRODUCTION_VIRTUAL,
		);
	}

	/**
	 * Check if a bucket type is allowed.
	 *
	 * @param string $bucket_type The bucket type to validate.
	 * @return bool True if bucket type is allowed, false otherwise.
	 */
	public static function is_allowed( string $bucket_type ): bool {
		return in_array( sanitize_key( $bucket_type ), self::allowed(), true );
	}

	/**
	 * Normalize a bucket type to its canonical form.
	 * Returns the bucket type if allowed, otherwise defaults to WAREHOUSE_STOCK.
	 *
	 * @param string $bucket_type The bucket type to normalize.
	 * @return string Normalized bucket type.
	 */
	public static function normalize( string $bucket_type ): string {
		$bucket_type = sanitize_key( $bucket_type );

		return self::is_allowed( $bucket_type ) ? $bucket_type : self::WAREHOUSE_STOCK;
	}

	/**
	 * Get human-readable description for a bucket type.
	 * Useful for admin UI and logging.
	 *
	 * @param string $bucket_type The bucket type key.
	 * @return string Human-readable description.
	 */
	public static function description( string $bucket_type ): string {
		$descriptions = array(
			self::WAREHOUSE_STOCK          => 'Normal sellable warehouse inventory',
			self::WAREHOUSE_EVENT_PREPACK  => 'Event-specific separated inventory',
			self::WOO_FULFILLMENT          => 'Items pulled for Woo orders',
			self::SHOW_LIVE                => 'Inventory physically at a show',
			self::STITCHER                 => 'Inventory in custody of a stitcher',
			self::RETURN_RECONCILIATION    => 'Items returned from show awaiting inspection',
			self::DAMAGE_HOLD              => 'Damaged/blemish stock',
			self::CONSUMED_VIRTUAL         => 'Sold/consumed stock',
			self::SHRINK_VIRTUAL           => 'Lost or missing inventory',
			self::PRODUCTION_VIRTUAL       => 'Work orders for stitcher customization',
		);

		return isset( $descriptions[ $bucket_type ] ) ? $descriptions[ $bucket_type ] : 'Unknown bucket type';
	}

	/**
	 * Get bucket type categories for filtering and grouping.
	 *
	 * @return array Associative array mapping category names to arrays of bucket types.
	 */
	public static function by_category(): array {
		return array(
			'warehouse' => array(
				self::WAREHOUSE_STOCK,
				self::WAREHOUSE_EVENT_PREPACK,
			),
			'destination' => array(
				self::WOO_FULFILLMENT,
				self::SHOW_LIVE,
				self::STITCHER,
			),
			'reconciliation' => array(
				self::RETURN_RECONCILIATION,
				self::DAMAGE_HOLD,
			),
			'virtual' => array(
				self::CONSUMED_VIRTUAL,
				self::SHRINK_VIRTUAL,
				self::PRODUCTION_VIRTUAL,
			),
		);
	}

	/**
	 * Check if a bucket type is a virtual ledger type (non-physical).
	 * Virtual buckets are used for system tracking only, without physical bucket requirement.
	 *
	 * @param string $bucket_type The bucket type to check.
	 * @return bool True if bucket type is virtual, false if physical.
	 */
	public static function is_virtual( string $bucket_type ): bool {
		$virtual_types = array( self::CONSUMED_VIRTUAL, self::SHRINK_VIRTUAL, self::PRODUCTION_VIRTUAL );
		return in_array( sanitize_key( $bucket_type ), $virtual_types, true );
	}

	/**
	 * Get allowed bucket statuses for a given bucket type.
	 * Defines state machine constraints per bucket type.
	 *
	 * @param string $bucket_type The bucket type.
	 * @return array Array of allowed status values.
	 */
	public static function allowed_statuses_for_type( string $bucket_type ): array {
		$bucket_type = sanitize_key( $bucket_type );

		// Virtual buckets are always "available" (no physical state transitions).
		if ( self::is_virtual( $bucket_type ) ) {
			return array( 'available' );
		}

		// Destination/custody buckets have different constraints than warehouse buckets.
		$destination_types = array( self::WOO_FULFILLMENT, self::SHOW_LIVE, self::STITCHER );
		if ( in_array( $bucket_type, $destination_types, true ) ) {
			return array( 'available', 'in_transit', 'in_use', 'returned' );
		}

		// Warehouse and reconciliation/hold buckets have standard status lifecycle.
		return array( 'available', 'staging', 'in_transit', 'unavailable' );
	}

	/**
	 * Validate a bucket type and status combination.
	 * Ensures status is allowed for the given bucket type.
	 *
	 * @param string $bucket_type The bucket type.
	 * @param string $status The bucket status.
	 * @return bool True if combination is valid, false otherwise.
	 */
	public static function is_valid_status_for_type( string $bucket_type, string $status ): bool {
		$bucket_type = sanitize_key( $bucket_type );
		$status      = sanitize_key( $status );

		if ( ! self::is_allowed( $bucket_type ) ) {
			return false;
		}

		$allowed_statuses = self::allowed_statuses_for_type( $bucket_type );
		return in_array( $status, $allowed_statuses, true );
	}

	/**
	 * Get the primary warehouse bucket type (source for all outbound allocations).
	 *
	 * @return string The primary warehouse stock bucket type.
	 */
	public static function primary_warehouse(): string {
		return self::WAREHOUSE_STOCK;
	}

	/**
	 * Check if bucket type is a warehouse type (primary or event-prepack staging).
	 *
	 * @param string $bucket_type The bucket type to check.
	 * @return bool True if warehouse-related bucket type, false otherwise.
	 */
	public static function is_warehouse_type( string $bucket_type ): bool {
		$warehouse_types = array( self::WAREHOUSE_STOCK, self::WAREHOUSE_EVENT_PREPACK );
		return in_array( sanitize_key( $bucket_type ), $warehouse_types, true );
	}
}
