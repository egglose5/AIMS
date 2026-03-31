<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Movement_Service {
	private $movements;
	private $positions;
	private $auth_service;
	private $person_identity;

	public function __construct( $movements, $positions = null, AIMS_Responsibility_Authorization_Service $auth_service = null, AIMS_Person_Identity_Service $person_identity = null ) {
		$this->movements = $movements;
		$this->positions = $positions;
		$this->auth_service = $auth_service ?: new AIMS_Responsibility_Authorization_Service();
		$this->person_identity = $person_identity ?: new AIMS_Person_Identity_Service();
	}

	public function record_stock_in( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'stock_in';

		return $this->record_movement( $data );
	}

	public function record_stock_out( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'stock_out';

		return $this->record_movement( $data );
	}

	public function record_transfer( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? AIMS_Inventory_Movement_Events::WAREHOUSE_TRANSFER;

		return $this->record_movement( $data );
	}

	public function record_event_load_out( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'event_load_out';

		return $this->record_movement( $data );
	}

	public function record_event_return( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? AIMS_Inventory_Movement_Events::RETURN_FROM_EVENT;

		return $this->record_movement( $data );
	}

	public function record_pick( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'warehouse_pick';

		return $this->record_movement( $data );
	}

	public function record_adjustment( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'adjustment';

		return $this->record_movement( $data );
	}

	public function record_movement( array $data ) {
		$actor_user_id = (int) ( $data['applied_by'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$bucket_id      = ! empty( $data['bucket_id'] ) ? (int) $data['bucket_id'] : 0;
		$vendor_id      = (int) ( $data['vendor_id'] ?? 0 );
		$product_id     = (int) ( $data['product_id'] ?? 0 );
		$reference_type = sanitize_key( $data['reference_type'] ?? '' );
		$reference_id   = sanitize_text_field( $data['reference_id'] ?? '' );
		$movement_type  = sanitize_key( $data['movement_type'] ?? '' );
		$quantity_delta = (float) ( $data['quantity_delta'] ?? 0 );

		if ( $bucket_id <= 0 || $vendor_id <= 0 || $product_id <= 0 || '' === $reference_type || '' === $reference_id || '' === $movement_type || 0.0 === $quantity_delta ) {
			return new WP_Error( 'aims_invalid_bucket_movement', 'Bucket movement is missing required fields.' );
		}

		if ( $actor_user_id > 0 ) {
			if ( ! $this->person_identity->is_aims_person( $actor_user_id ) ) {
				return new WP_Error( 'aims_person_required', 'Only AIMS users can apply inventory movements.' );
			}

			if ( ! $this->auth_service->can_manage_vendor_inventory_for_vendor( $actor_user_id, $vendor_id ) ) {
				return new WP_Error( 'aims_inventory_scope_denied', 'This user is not authorized to manage inventory for the specified vendor.' );
			}
		}

		if ( ! AIMS_Inventory_Movement_Events::is_allowed( $movement_type ) ) {
			return new WP_Error( 'aims_invalid_bucket_movement_type', 'Bucket movement type is not allowed by policy.' );
		}

		if ( ! AIMS_Inventory_Movement_Events::is_allowed_reference_for_movement( $movement_type, $reference_type ) ) {
			return new WP_Error( 'aims_invalid_bucket_reference_type', 'Reference type is not allowed for this bucket movement type.' );
		}

		if ( method_exists( $this->movements, 'has_reference_application' ) && $this->movements->has_reference_application( $reference_type, $reference_id, $product_id, $bucket_id, $movement_type ) ) {
			return new WP_Error( 'aims_duplicate_bucket_movement', 'This bucket movement has already been applied.' );
		}

		$movement_id = (int) $this->movements->create( $data );
		$current_qty = 0.0;

		if ( method_exists( $this->movements, 'get_balance_for_bucket_product' ) ) {
			$current_qty = (float) $this->movements->get_balance_for_bucket_product( $bucket_id, $vendor_id, $product_id );
		}

		if ( is_object( $this->positions ) ) {
			if ( method_exists( $this->positions, 'synchronize_from_movements' ) && method_exists( $this->movements, 'get_balance_for_bucket_product' ) ) {
				$this->positions->synchronize_from_movements( $this->movements, $bucket_id, $vendor_id, $product_id );
			} elseif ( method_exists( $this->positions, 'upsert_position' ) ) {
				$this->positions->upsert_position(
					array(
						'bucket_id'               => $bucket_id,
						'vendor_id'               => $vendor_id,
						'product_id'              => $product_id,
						'quantity'                => $current_qty,
						'position_status'         => sanitize_key( $data['position_status'] ?? 'active' ),
						'last_bucket_movement_id' => $movement_id,
					)
				);
			}
		}

		return array(
			'movement_id'      => $movement_id,
			'current_quantity' => $current_qty,
		);
	}
}
