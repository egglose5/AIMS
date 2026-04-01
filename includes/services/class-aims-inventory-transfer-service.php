<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Transfer_Service {
	private $transfer_repo;
	private $items_repo;
	private $custody_service;
	private $bucket_repo;
	private $authorization_service;

	public function __construct(
		AIMS_Inventory_Transfer_Repository $transfer_repo = null,
		AIMS_Inventory_Transfer_Items_Repository $items_repo = null,
		AIMS_Inventory_Custody_Transfer_Service $custody_service = null,
		AIMS_Physical_Bucket_Repository $bucket_repo = null,
		AIMS_Inventory_Transfer_Authorization_Service $authorization_service = null
	) {
		$this->transfer_repo         = $transfer_repo ?: new AIMS_Inventory_Transfer_Repository();
		$this->items_repo            = $items_repo ?: new AIMS_Inventory_Transfer_Items_Repository();
		$this->custody_service       = $custody_service ?: new AIMS_Inventory_Custody_Transfer_Service(
			new AIMS_Bucket_Movement_Service( new AIMS_Bucket_Inventory_Movement_Repository() )
		);
		$this->bucket_repo           = $bucket_repo ?: new AIMS_Physical_Bucket_Repository();
		$this->authorization_service  = $authorization_service ?: new AIMS_Inventory_Transfer_Authorization_Service();
	}

	/**
	 * Create a new transfer draft.
	 *
	 * @param int   $source_node_id Source custody node ID.
	 * @param int   $target_node_id Target custody node ID.
	 * @param array $data Optional data array with reference_type, reference_id, notes.
	 * @return array Success or error response with transfer_id.
	 */
	public function create_draft( int $source_node_id, int $target_node_id, array $data = array() ): array {
		if ( $source_node_id <= 0 ) {
			return $this->error_response( 'Source node is required.', 'missing_source_node' );
		}

		if ( $target_node_id <= 0 ) {
			return $this->error_response( 'Target node is required.', 'missing_target_node' );
		}

		$source_node_type = sanitize_key( (string) ( $data['source_node_type'] ?? 'vendor' ) );
		$target_node_type = sanitize_key( (string) ( $data['target_node_type'] ?? 'vendor' ) );
		$transfer_type    = $this->normalize_transfer_type( (string) ( $data['transfer_type'] ?? AIMS_Inventory_Transfer_Authorization_Service::TRANSFER_TYPE_STANDARD ) );
		$override_route   = ! empty( $data['override_route'] ) || $this->is_exceptional_transfer_type( $transfer_type );
		$override_reason  = isset( $data['override_reason'] ) ? sanitize_textarea_field( (string) $data['override_reason'] ) : '';
		$route_guidance   = isset( $data['route_guidance'] ) ? sanitize_text_field( (string) $data['route_guidance'] ) : '';
		$initiated_by     = (int) ( $data['initiated_by'] ?? get_current_user_id() );

		if ( $override_route && ! $this->authorization_service->can_override_transfer_route( $initiated_by, $transfer_type ) ) {
			return $this->error_response( 'You are not authorized to override the default transfer route.', 'transfer_override_denied' );
		}

		if ( $override_route && '' === $override_reason ) {
			return $this->error_response( 'An override reason is required for exceptional routing.', 'missing_override_reason' );
		}

		$transfer_data = array(
			'source_node_type' => $source_node_type,
			'source_node_id'   => $source_node_id,
			'target_node_type' => $target_node_type,
			'target_node_id'   => $target_node_id,
			'source_vendor_id' => ( 'vendor' === $source_node_type ) ? $source_node_id : (int) ( $data['source_vendor_id'] ?? 0 ),
			'target_vendor_id' => ( 'vendor' === $target_node_type ) ? $target_node_id : (int) ( $data['target_vendor_id'] ?? 0 ),
			'transfer_status'  => 'pending',
			'transfer_type'    => $transfer_type,
			'initiated_by'     => $initiated_by,
			'reference_type'   => $this->resolve_reference_type( $transfer_type, $override_route, (string) ( $data['reference_type'] ?? '' ) ),
			'reference_id'     => sanitize_text_field( $data['reference_id'] ?? '' ),
			'override_route'   => $override_route ? 'override' : 'guidance',
			'override_reason'  => $override_reason,
			'override_note'    => $route_guidance,
			'override_actor_id'=> $override_route ? $initiated_by : 0,
			'override_at'      => $override_route ? current_time( 'mysql' ) : null,
			'notes'            => $this->compose_audit_notes(
				isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
				array(
					'transfer_type'   => $transfer_type,
					'override_route'  => $override_route,
					'override_reason' => $override_reason,
					'route_guidance'  => $route_guidance,
				)
			),
		);

		$transfer_id = $this->transfer_repo->create( $transfer_data );

		if ( $transfer_id <= 0 ) {
			return $this->error_response( 'Could not create transfer.', 'transfer_create_failed' );
		}

		return array(
			'success'     => true,
			'transfer_id' => $transfer_id,
			'message'     => 'Transfer draft created.',
			'audit'       => array(
				'transfer_type'   => $transfer_type,
				'override_route'  => $override_route,
				'override_reason' => $override_reason,
				'route_guidance'  => $route_guidance,
			),
		);
	}

	/**
	 * Add an item to a transfer via product ID and source/target bucket.
	 *
	 * @param int   $transfer_id Transfer ID.
	 * @param int   $product_id WC product ID.
	 * @param int   $source_bucket_id Source bucket ID.
	 * @param int   $target_bucket_id Target bucket ID.
	 * @param float $quantity Quantity to transfer.
	 * @param array $data Optional data (notes, vendor_id).
	 * @return array Success or error response with item_id.
	 */
	public function add_item_to_transfer( int $transfer_id, int $product_id, int $source_bucket_id, int $target_bucket_id, float $quantity, array $data = array() ): array {
		$transfer = $this->transfer_repo->find( $transfer_id );
		if ( ! is_array( $transfer ) ) {
			return $this->error_response( 'Transfer not found.', 'transfer_not_found' );
		}

		if ( 'pending' !== (string) $transfer['transfer_status'] ) {
			return $this->error_response( 'Only pending transfers can receive new items.', 'invalid_transfer_status' );
		}

		if ( $product_id <= 0 ) {
			return $this->error_response( 'Product ID is required.', 'invalid_product_id' );
		}

		if ( $source_bucket_id <= 0 ) {
			return $this->error_response( 'Source bucket is required.', 'invalid_source_bucket' );
		}

		if ( $target_bucket_id <= 0 ) {
			return $this->error_response( 'Target bucket is required.', 'invalid_target_bucket' );
		}

		if ( $quantity <= 0 ) {
			return $this->error_response( 'Quantity must be greater than zero.', 'invalid_quantity' );
		}

		$vendor_id = (int) ( $data['vendor_id'] ?? $transfer['source_vendor_id'] ?? 0 );
		if ( $vendor_id <= 0 && 'vendor' === (string) ( $transfer['source_node_type'] ?? '' ) ) {
			$vendor_id = (int) ( $transfer['source_node_id'] ?? 0 );
		}
		if ( $vendor_id <= 0 ) {
			$vendor_id = $this->derive_vendor_from_bucket( $source_bucket_id );
		}

		$source_bucket = $this->bucket_repo->find( $source_bucket_id );
		$source_code   = is_array( $source_bucket ) ? ( $source_bucket['bucket_code'] ?? '' ) : '';

		$target_bucket = $this->bucket_repo->find( $target_bucket_id );
		$target_code   = is_array( $target_bucket ) ? ( $target_bucket['bucket_code'] ?? '' ) : '';

		$line_number = $this->items_repo->next_line_number( $transfer_id );

		$item_data = array(
			'transfer_id'        => $transfer_id,
			'transfer_uuid'      => (string) ( $transfer['transfer_uuid'] ?? '' ),
			'line_number'        => $line_number,
			'product_id'         => $product_id,
			'vendor_id'          => $vendor_id,
			'requested_quantity' => number_format( $quantity, 4, '.', '' ),
			'line_status'        => 'pending',
			'source_bucket_id'   => $source_bucket_id,
			'target_bucket_id'   => $target_bucket_id,
			'source_bucket_code' => $source_code,
			'target_bucket_code' => $target_code,
			'notes'              => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
		);

		$item_id = $this->items_repo->create( $item_data );

		if ( $item_id <= 0 ) {
			return $this->error_response( 'Could not add item to transfer.', 'item_create_failed' );
		}

		return array(
			'success'  => true,
			'item_id'  => $item_id,
			'message'  => 'Item added to transfer.',
		);
	}

	/**
	 * Add an item to a transfer via WooCommerce product lookup (SKU-based).
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $sku_or_product_id SKU or WC product ID.
	 * @param int    $source_bucket_id Source bucket ID.
	 * @param int    $target_bucket_id Target bucket ID.
	 * @param float  $quantity Quantity to transfer.
	 * @param array  $data Optional data (notes).
	 * @return array Success or error response with item_id.
	 */
	public function add_item_via_wc_product( int $transfer_id, $sku_or_product_id, int $source_bucket_id, int $target_bucket_id, float $quantity, array $data = array() ): array {
		// Try to resolve to a product ID
		$product_id = $this->resolve_wc_product_id( $sku_or_product_id );

		if ( $product_id <= 0 ) {
			return $this->error_response(
				sprintf( 'WooCommerce product not found: %s', esc_attr( $sku_or_product_id ) ),
				'product_not_found'
			);
		}

		// Validate that it's a physical product
		if ( ! $this->is_physical_product( $product_id ) ) {
			return $this->error_response(
				'Only physical products can be transferred.',
				'virtual_product'
			);
		}

		return $this->add_item_to_transfer( $transfer_id, $product_id, $source_bucket_id, $target_bucket_id, $quantity, $data );
	}

	/**
	 * Dispatch a transfer (mark as in_transit).
	 *
	 * @param int   $transfer_id Transfer ID.
	 * @param array $data Optional data (notes, user_id for ledger).
	 * @return array Success or error response.
	 */
	public function dispatch_transfer( int $transfer_id, array $data = array() ): array {
		$transfer = $this->transfer_repo->find( $transfer_id );
		if ( ! is_array( $transfer ) ) {
			return $this->error_response( 'Transfer not found.', 'transfer_not_found' );
		}

		if ( 'pending' !== (string) $transfer['transfer_status'] ) {
			return $this->error_response( 'Only pending transfers can be dispatched.', 'invalid_transfer_status' );
		}

		$items = $this->items_repo->get_for_transfer( $transfer_id );
		if ( empty( $items ) ) {
			return $this->error_response( 'Transfer has no items to dispatch.', 'no_items' );
		}

		$user_id = (int) ( $data['user_id'] ?? get_current_user_id() );
		$audit_note = $this->compose_stage_audit_notes(
			$transfer,
			'dispatch',
			$data
		);

		// Dispatch all items via custody transfer service
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id           = (int) ( $item['id'] ?? 0 );
			$requested_qty     = (float) ( $item['requested_quantity'] ?? 0 );
			$source_bucket_id  = (int) ( $item['source_bucket_id'] ?? 0 );
			$target_bucket_id  = (int) ( $item['target_bucket_id'] ?? 0 );
			$product_id        = (int) ( $item['product_id'] ?? 0 );
			$vendor_id         = (int) ( $item['vendor_id'] ?? 0 );

			$custody_result = $this->custody_service->create_transfer_out( array(
				'product_id'       => $product_id,
				'source_bucket_id' => $source_bucket_id,
				'target_bucket_id' => $target_bucket_id,
				'quantity_delta'   => $requested_qty,
				'vendor_id'        => $vendor_id,
				'applied_by'       => $user_id,
				'reference_id'     => 'transfer-' . $transfer_id . '-' . $item_id,
				'note'             => $audit_note ?: sprintf( 'Transfer dispatch from transfer ID %d', $transfer_id ),
				'audit_reason'     => isset( $data['audit_reason'] ) ? sanitize_textarea_field( (string) $data['audit_reason'] ) : null,
				'route_guidance'   => isset( $data['route_guidance'] ) ? sanitize_text_field( (string) $data['route_guidance'] ) : null,
				'route_mode'       => ! empty( $data['override_route'] ) ? 'override' : 'guidance',
				'transfer_type'    => (string) ( $transfer['transfer_type'] ?? '' ),
			) );

			if ( ! isset( $custody_result['success'] ) || ! $custody_result['success'] ) {
				return $this->error_response(
					sprintf( 'Failed to dispatch item %d: %s', $item_id, $custody_result['message'] ?? 'Unknown error' ),
					'dispatch_failed'
				);
			}

			$movement_id = (int) ( $custody_result['movement_id'] ?? 0 );
			if ( $movement_id > 0 ) {
				$this->items_repo->update_status( $item_id, 'dispatched', array(
					'dispatched_quantity' => $requested_qty,
					'dispatch_movement_id' => $movement_id,
				) );
			}
		}

		// Update transfer status to dispatched/in_transit
		$this->transfer_repo->update_status(
			$transfer_id,
			'dispatched',
			array(
				'notes'       => $audit_note,
				'transfer_type' => (string) ( $transfer['transfer_type'] ?? '' ),
			)
		);

		return array(
			'success'  => true,
			'message'  => 'Transfer dispatched and marked in transit.',
		);
	}

	/**
	 * Confirm receipt of a transfer.
	 *
	 * @param int   $transfer_id Transfer ID.
	 * @param array $item_receipts Array of item_id => received_quantity.
	 * @param array $data Optional data (notes, user_id).
	 * @return array Success or error response.
	 */
	public function confirm_receipt( int $transfer_id, array $item_receipts = array(), array $data = array() ): array {
		$transfer = $this->transfer_repo->find( $transfer_id );
		if ( ! is_array( $transfer ) ) {
			return $this->error_response( 'Transfer not found.', 'transfer_not_found' );
		}

		if ( 'dispatched' !== (string) $transfer['transfer_status'] ) {
			return $this->error_response( 'Only dispatched transfers can be received.', 'invalid_transfer_status' );
		}

		$items = $this->items_repo->get_for_transfer( $transfer_id );
		if ( empty( $items ) ) {
			return $this->error_response( 'Transfer has no items.', 'no_items' );
		}

		$user_id = (int) ( $data['user_id'] ?? get_current_user_id() );
		$audit_note = $this->compose_stage_audit_notes(
			$transfer,
			'receipt',
			$data
		);

		// Process receipts for all items
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id              = (int) ( $item['id'] ?? 0 );
			$requested_qty        = (float) ( $item['requested_quantity'] ?? 0 );
			$received_qty         = (float) ( $item_receipts[ $item_id ] ?? $requested_qty );
			$source_bucket_id     = (int) ( $item['source_bucket_id'] ?? 0 );
			$target_bucket_id     = (int) ( $item['target_bucket_id'] ?? 0 );
			$product_id           = (int) ( $item['product_id'] ?? 0 );
			$vendor_id            = (int) ( $item['vendor_id'] ?? 0 );
			$dispatch_movement_id = (int) ( $item['dispatch_movement_id'] ?? 0 );

			$variance = $received_qty - $requested_qty;

			// Record the receipt via custody service
			$custody_result = $this->custody_service->confirm_transfer_receipt( array(
				'product_id'       => $product_id,
				'source_bucket_id' => $source_bucket_id,
				'target_bucket_id' => $target_bucket_id,
				'quantity_delta'   => $received_qty,
				'vendor_id'        => $vendor_id,
				'applied_by'       => $user_id,
				'reference_id'     => 'transfer-receipt-' . $transfer_id . '-' . $item_id,
				'note'             => $audit_note ?: sprintf( 'Transfer receipt for transfer ID %d', $transfer_id ),
				'audit_reason'     => isset( $data['audit_reason'] ) ? sanitize_textarea_field( (string) $data['audit_reason'] ) : null,
				'route_guidance'   => isset( $data['route_guidance'] ) ? sanitize_text_field( (string) $data['route_guidance'] ) : null,
				'route_mode'       => ! empty( $data['override_route'] ) ? 'override' : 'guidance',
				'transfer_type'    => (string) ( $transfer['transfer_type'] ?? '' ),
			) );

			if ( ! isset( $custody_result['success'] ) || ! $custody_result['success'] ) {
				return $this->error_response(
					sprintf( 'Failed to confirm receipt for item %d: %s', $item_id, $custody_result['message'] ?? 'Unknown error' ),
					'receipt_failed'
				);
			}

			$receipt_movement_id = (int) ( $custody_result['movement_id'] ?? 0 );

			// Update item with receipt information
			$item_update = array(
				'received_quantity'  => $received_qty,
				'receipt_movement_id' => $receipt_movement_id,
			);

			if ( 0 !== $variance ) {
				$item_update['variance_quantity'] = abs( $variance );
				$this->items_repo->update_status( $item_id, 'received_with_variance', $item_update );
			} else {
				$this->items_repo->update_status( $item_id, 'received', $item_update );
			}
		}

		// Update transfer status to received
		$this->transfer_repo->update_status( $transfer_id, 'received', array(
			'received_by'   => $user_id,
			'notes'         => $audit_note,
			'transfer_type' => (string) ( $transfer['transfer_type'] ?? '' ),
		) );

		return array(
			'success'  => true,
			'message'  => 'Transfer receipt confirmed.',
		);
	}

	/**
	 * Resolve a WooCommerce product ID from SKU or product ID.
	 *
	 * @param string|int $sku_or_product_id SKU string or product ID.
	 * @return int Product ID, or 0 if not found.
	 */
	private function resolve_wc_product_id( $sku_or_product_id ): int {
		// If it's already a number, try it as product ID
		if ( is_numeric( $sku_or_product_id ) ) {
			$product_id = (int) $sku_or_product_id;
			if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					return $product_id;
				}
			}
		}

		// Try as SKU lookup
		$sku = $this->normalize_sku( $sku_or_product_id );
		if ( '' === $sku ) {
			return 0;
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$products = wc_get_products( array(
			'sku'    => $sku,
			'limit'  => 1,
			'return' => 'ids',
		) );

		return ! empty( $products[0] ) ? (int) $products[0] : 0;
	}

	/**
	 * Normalize a SKU string (trim, uppercase).
	 *
	 * @param string $sku SKU string.
	 * @return string Normalized SKU.
	 */
	private function normalize_sku( $sku ): string {
		return strtoupper( trim( sanitize_text_field( (string) $sku ) ) );
	}

	/**
	 * Check if a WooCommerce product is physical (not virtual).
	 *
	 * @param int $product_id WC product ID.
	 * @return bool True if physical, false otherwise.
	 */
	private function is_physical_product( int $product_id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return true; // Can't verify, assume physical
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		return ! $product->is_virtual();
	}

	/**
	 * Derive vendor ID from a bucket.
	 *
	 * @param int $bucket_id Bucket ID.
	 * @return int Vendor ID, or 0 if not found.
	 */
	private function derive_vendor_from_bucket( int $bucket_id ): int {
		if ( $bucket_id <= 0 ) {
			return 0;
		}

		$bucket = $this->bucket_repo->find( $bucket_id );
		if ( ! is_array( $bucket ) ) {
			return 0;
		}

		return (int) ( $bucket['vendor_id'] ?? 0 );
	}

	/**
	 * Helper method for error responses.
	 *
	 * @param string $message Error message.
	 * @param string $code Error code.
	 * @return array Error response array.
	 */
	private function error_response( string $message, string $code ): array {
		return array(
			'success' => false,
			'message' => $message,
			'code'    => $code,
		);
	}

	private function normalize_transfer_type( string $transfer_type ): string {
		return $this->authorization_service->normalize_transfer_type( $transfer_type );
	}

	private function is_exceptional_transfer_type( string $transfer_type ): bool {
		return $this->authorization_service->is_exceptional_transfer_type( $transfer_type );
	}

	private function resolve_reference_type( string $transfer_type, bool $override_route, string $reference_type ): string {
		$reference_type = sanitize_key( $reference_type );

		if ( '' !== $reference_type ) {
			return $reference_type;
		}

		if ( $override_route || $this->is_exceptional_transfer_type( $transfer_type ) ) {
			return 'inventory_route_override';
		}

		return 'inventory_transfer';
	}

	private function compose_audit_notes( ?string $notes, array $audit_data ): ?string {
		$parts = array();
		$notes  = is_string( $notes ) ? trim( $notes ) : '';

		if ( '' !== $notes ) {
			$parts[] = $notes;
		}

		$transfer_type = sanitize_key( (string) ( $audit_data['transfer_type'] ?? '' ) );
		if ( '' !== $transfer_type ) {
			$parts[] = 'Transfer type: ' . $transfer_type;
		}

		$route_mode = sanitize_key( (string) ( $audit_data['route_mode'] ?? '' ) );
		if ( '' !== $route_mode ) {
			$parts[] = 'Route mode: ' . $route_mode;
		}

		$route_guidance = trim( (string) ( $audit_data['route_guidance'] ?? '' ) );
		if ( '' !== $route_guidance ) {
			$parts[] = 'Route guidance: ' . $route_guidance;
		}

		$override_reason = trim( (string) ( $audit_data['override_reason'] ?? '' ) );
		if ( '' !== $override_reason ) {
			$parts[] = 'Override reason: ' . $override_reason;
		}

		$parts = array_values( array_unique( array_filter( $parts ) ) );

		if ( empty( $parts ) ) {
			return null;
		}

		return implode( "\n", $parts );
	}

	private function compose_stage_audit_notes( array $transfer, string $stage, array $data ): ?string {
		$parts = array();

		$notes = isset( $data['notes'] ) ? trim( (string) $data['notes'] ) : '';
		if ( '' !== $notes ) {
			$parts[] = $notes;
		}

		$transfer_type = sanitize_key( (string) ( $transfer['transfer_type'] ?? '' ) );
		if ( '' !== $transfer_type ) {
			$parts[] = 'Transfer type: ' . $transfer_type;
		}

		$route_mode = ! empty( $data['override_route'] ) ? 'override' : 'guidance';
		$parts[]    = 'Route mode: ' . $route_mode;

		$route_guidance = trim( (string) ( $data['route_guidance'] ?? '' ) );
		if ( '' !== $route_guidance ) {
			$parts[] = 'Route guidance: ' . $route_guidance;
		}

		$audit_reason = trim( (string) ( $data['audit_reason'] ?? $data['override_reason'] ?? '' ) );
		if ( '' !== $audit_reason ) {
			$parts[] = ucfirst( $stage ) . ' reason: ' . $audit_reason;
		}

		$parts = array_values( array_unique( array_filter( $parts ) ) );

		if ( empty( $parts ) ) {
			return null;
		}

		return implode( "\n", $parts );
	}
}
