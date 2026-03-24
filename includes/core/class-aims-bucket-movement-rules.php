<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AIMS Bucket Movement Rules
 *
 * Defines the canonical rules for allowed bucket-to-bucket transitions.
 * This enforces strict state machine constraints on physical and virtual inventory movements.
 *
 * The movement rule matrix maps from source bucket type → destination bucket type with constraints:
 * - Qty Change: '+' = create new qty, '−' = consume/remove qty, '0' = transfer same qty
 * - Requires Event: Must reference an event_id
 * - Requires Notes: Note field is mandatory
 *
 * @package AIMS
 */
class AIMS_Bucket_Movement_Rules {

	/**
	 * Get the complete movement rule matrix.
	 * Each rule defines allowed transitions between bucket types with operational constraints.
	 *
	 * @return array Associative array mapping movement type → rule definition.
	 */
	public static function rules(): array {
		return array(
			// ===== WORK ORDER CREATION =====
			'stitch_order_release' => array(
				'from_bucket'     => 'production_virtual',
				'to_bucket'       => 'stitcher',
				'qty_change'      => '+',
				'requires_event'  => false,
				'requires_notes'  => false,
				'description'     => 'Work order released from production queue to stitcher custody',
			),

			// ===== STITCHER RETURN =====
			'stitcher_to_warehouse' => array(
				'from_bucket'     => 'stitcher',
				'to_bucket'       => 'warehouse_stock',
				'qty_change'      => '0',
				'requires_event'  => false,
				'requires_notes'  => false,
				'description'     => 'Completed custom work returned from stitcher to warehouse',
			),

			// ===== EVENT ALLOCATION =====
			'warehouse_to_event_prepack' => array(
				'from_bucket'     => 'warehouse_stock',
				'to_bucket'       => 'warehouse_event_prepack',
				'qty_change'      => '0',
				'requires_event'  => true,
				'requires_notes'  => false,
				'description'     => 'Inventory allocated from warehouse to event-specific prepack',
			),

			'event_prepack_to_show' => array(
				'from_bucket'     => 'warehouse_event_prepack',
				'to_bucket'       => 'show_live',
				'qty_change'      => '0',
				'requires_event'  => true,
				'requires_notes'  => false,
				'description'     => 'Event-prepack inventory transported to show venue',
			),

			// ===== POS SALES & RETURNS =====
			'show_sale' => array(
				'from_bucket'     => 'show_live',
				'to_bucket'       => 'consumed_virtual',
				'qty_change'      => '−',
				'requires_event'  => true,
				'requires_notes'  => false,
				'description'     => 'Inventory sold via POS at show/event',
			),

			'show_return_checkin' => array(
				'from_bucket'     => 'show_live',
				'to_bucket'       => 'return_reconciliation',
				'qty_change'      => '0',
				'requires_event'  => true,
				'requires_notes'  => false,
				'description'     => 'Inventory returned from show event, awaiting inspection',
			),

			// ===== RETURNS DISPOSITION =====
			'return_restock_to_warehouse' => array(
				'from_bucket'     => 'return_reconciliation',
				'to_bucket'       => 'warehouse_stock',
				'qty_change'      => '0',
				'requires_event'  => true,
				'requires_notes'  => false,
				'description'     => 'Inspected returns approved for restocking to warehouse',
			),

			// ===== LOSS WRITEOFF =====
			'show_shrink_writeoff' => array(
				'from_bucket'     => 'show_live',
				'to_bucket'       => 'shrink_virtual',
				'qty_change'      => '−',
				'requires_event'  => true,
				'requires_notes'  => true,
				'description'     => 'Inventory loss/shrinkage at show (damage, theft, error) - requires explanation',
			),
		);
	}

	/**
	 * Get a specific movement rule by movement type.
	 *
	 * @param string $movement_type The movement type key.
	 * @return array|null The rule definition or null if not found.
	 */
	public static function get_rule( string $movement_type ): ?array {
		$movement_type = sanitize_key( $movement_type );
		$matrix        = self::rules();

		return isset( $matrix[ $movement_type ] ) ? $matrix[ $movement_type ] : null;
	}

	/**
	 * Check if a movement type is defined in the rule matrix.
	 *
	 * @param string $movement_type The movement type to validate.
	 * @return bool True if movement type is allowed, false otherwise.
	 */
	public static function is_allowed_movement( string $movement_type ): bool {
		return null !== self::get_rule( sanitize_key( $movement_type ) );
	}

	/**
	 * Get all allowed movement types.
	 *
	 * @return array Indexed array of movement type keys.
	 */
	public static function allowed_movements(): array {
		return array_keys( self::rules() );
	}

	/**
	 * Validate a bucket-to-bucket transition against the rule matrix.
	 * Checks if the movement from source bucket to destination bucket is allowed.
	 *
	 * @param string $from_bucket Source bucket type.
	 * @param string $to_bucket Destination bucket type.
	 * @return bool True if transition is allowed, false otherwise.
	 */
	public static function is_allowed_transition( string $from_bucket, string $to_bucket ): bool {
		$from_bucket = sanitize_key( $from_bucket );
		$to_bucket   = sanitize_key( $to_bucket );

		foreach ( self::rules() as $rule ) {
			if ( $rule['from_bucket'] === $from_bucket && $rule['to_bucket'] === $to_bucket ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the movement type(s) that allow a specific bucket transition.
	 *
	 * @param string $from_bucket Source bucket type.
	 * @param string $to_bucket Destination bucket type.
	 * @return array Array of movement type keys that support this transition.
	 */
	public static function get_movements_for_transition( string $from_bucket, string $to_bucket ): array {
		$from_bucket = sanitize_key( $from_bucket );
		$to_bucket   = sanitize_key( $to_bucket );
		$results     = array();

		foreach ( self::rules() as $movement_type => $rule ) {
			if ( $rule['from_bucket'] === $from_bucket && $rule['to_bucket'] === $to_bucket ) {
				$results[] = $movement_type;
			}
		}

		return $results;
	}

	/**
	 * Check if a movement type requires an event reference.
	 *
	 * @param string $movement_type The movement type to check.
	 * @return bool True if event_id is required, false otherwise.
	 */
	public static function requires_event( string $movement_type ): bool {
		$rule = self::get_rule( $movement_type );
		return is_array( $rule ) && ! empty( $rule['requires_event'] );
	}

	/**
	 * Check if a movement type requires notes.
	 *
	 * @param string $movement_type The movement type to check.
	 * @return bool True if notes field is required, false otherwise.
	 */
	public static function requires_notes( string $movement_type ): bool {
		$rule = self::get_rule( $movement_type );
		return is_array( $rule ) && ! empty( $rule['requires_notes'] );
	}

	/**
	 * Get quantity change semantics for a movement type.
	 *
	 * @param string $movement_type The movement type to check.
	 * @return string One of: '+' (create), '−' (consume), '0' (transfer), or '' if not found.
	 */
	public static function qty_change_for_movement( string $movement_type ): string {
		$rule = self::get_rule( $movement_type );
		return is_array( $rule ) ? $rule['qty_change'] : '';
	}

	/**
	 * Check if a movement creates new inventory (qty_change = '+').
	 *
	 * @param string $movement_type The movement type to check.
	 * @return bool True if movement creates inventory, false otherwise.
	 */
	public static function is_creation_movement( string $movement_type ): bool {
		return '+' === self::qty_change_for_movement( sanitize_key( $movement_type ) );
	}

	/**
	 * Check if a movement consumes inventory (qty_change = '−').
	 *
	 * @param string $movement_type The movement type to check.
	 * @return bool True if movement consumes/removes inventory, false otherwise.
	 */
	public static function is_consumption_movement( string $movement_type ): bool {
		return '−' === self::qty_change_for_movement( sanitize_key( $movement_type ) );
	}

	/**
	 * Check if a movement transfers inventory (qty_change = '0').
	 *
	 * @param string $movement_type The movement type to check.
	 * @return bool True if movement is a transfer, false otherwise.
	 */
	public static function is_transfer_movement( string $movement_type ): bool {
		return '0' === self::qty_change_for_movement( sanitize_key( $movement_type ) );
	}

	/**
	 * Validate a movement against all applicable constraints.
	 * Comprehensive validation that checks event requirement, notes requirement, and quantity semantics.
	 *
	 * @param string $movement_type The movement type to validate.
	 * @param string $from_bucket Source bucket type.
	 * @param string $to_bucket Destination bucket type.
	 * @param int $event_id Event ID (0 if no event).
	 * @param string $notes Movement notes (empty if none).
	 * @return array Array with 'valid' boolean and 'errors' array (empty if valid).
	 */
	public static function validate_movement(
		string $movement_type,
		string $from_bucket,
		string $to_bucket,
		int $event_id = 0,
		string $notes = ''
	): array {
		$movement_type = sanitize_key( $movement_type );
		$from_bucket   = sanitize_key( $from_bucket );
		$to_bucket     = sanitize_key( $to_bucket );
		$errors        = array();

		// Check if movement type is defined.
		if ( ! self::is_allowed_movement( $movement_type ) ) {
			$errors[] = sprintf( 'Movement type "%s" is not defined in rule matrix.', $movement_type );
		}

		// Check if transition is allowed.
		if ( ! self::is_allowed_transition( $from_bucket, $to_bucket ) ) {
			$errors[] = sprintf(
				'Transition from bucket "%s" to "%s" is not allowed.',
				$from_bucket,
				$to_bucket
			);
		}

		// Check event requirement.
		if ( self::requires_event( $movement_type ) && $event_id <= 0 ) {
			$errors[] = sprintf( 'Movement type "%s" requires an event reference.', $movement_type );
		}

		// Check notes requirement.
		if ( self::requires_notes( $movement_type ) && '' === trim( $notes ) ) {
			$errors[] = sprintf( 'Movement type "%s" requires explanatory notes.', $movement_type );
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Get all movements that originate from a specific bucket.
	 *
	 * @param string $from_bucket Source bucket type.
	 * @return array Array of movement types that can originate from this bucket.
	 */
	public static function get_movements_from_bucket( string $from_bucket ): array {
		$from_bucket = sanitize_key( $from_bucket );
		$results     = array();

		foreach ( self::rules() as $movement_type => $rule ) {
			if ( $rule['from_bucket'] === $from_bucket ) {
				$results[] = $movement_type;
			}
		}

		return $results;
	}

	/**
	 * Get all movements that terminate in a specific bucket.
	 *
	 * @param string $to_bucket Destination bucket type.
	 * @return array Array of movement types that can terminate in this bucket.
	 */
	public static function get_movements_to_bucket( string $to_bucket ): array {
		$to_bucket = sanitize_key( $to_bucket );
		$results   = array();

		foreach ( self::rules() as $movement_type => $rule ) {
			if ( $rule['to_bucket'] === $to_bucket ) {
				$results[] = $movement_type;
			}
		}

		return $results;
	}

	/**
	 * Get movement workflow categories for filtering and documentation.
	 *
	 * @return array Associative array mapping category names to movement type arrays.
	 */
	public static function by_category(): array {
		return array(
			'work_orders' => array( 'stitch_order_release' ),
			'returns'     => array( 'stitcher_to_warehouse' ),
			'event_allocation' => array(
				'warehouse_to_event_prepack',
				'event_prepack_to_show',
			),
			'pos_transactions' => array(
				'show_sale',
				'show_return_checkin',
			),
			'disposition' => array(
				'return_restock_to_warehouse',
				'show_shrink_writeoff',
			),
		);
	}
}
