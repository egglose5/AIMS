<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Transfer_Authorization_Service {
	public const TRANSFER_TYPE_STANDARD              = 'standard';
	public const TRANSFER_TYPE_DIRECT_COLLECTION      = 'direct_collection';
	public const TRANSFER_TYPE_RECOVERY               = 'recovery';
	public const TRANSFER_TYPE_TERMINATION_COLLECTION = 'termination_collection';

	public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'current_user_can' ) && current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY ) ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( AIMS_Capabilities::CAP_MANAGE );
	}

	public function can_override_transfer_route( int $user_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
		$user_id       = $this->resolve_user_id( $user_id );
		$transfer_type = $this->normalize_transfer_type( $transfer_type );

		if ( ! $this->can_manage_inventory_transfers( $user_id ) ) {
			return false;
		}

		$allowed = function_exists( 'current_user_can' ) && (
			current_user_can( AIMS_Capabilities::CAP_MANAGE )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_PRODUCTION )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_FULFILLMENT )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_RECONCILIATION )
		);

		if ( function_exists( 'apply_filters' ) ) {
			$allowed = (bool) apply_filters( 'aims_inventory_transfer_can_override_route', $allowed, $user_id, $transfer_type );
		}

		return $allowed || ! $this->is_exceptional_transfer_type( $transfer_type );
	}

	public function can_use_exceptional_transfer_type( int $user_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
		$transfer_type = $this->normalize_transfer_type( $transfer_type );

		if ( ! $this->is_exceptional_transfer_type( $transfer_type ) ) {
			return $this->can_manage_inventory_transfers( $user_id );
		}

		return $this->can_override_transfer_route( $user_id, $transfer_type );
	}

	public function is_exceptional_transfer_type( string $transfer_type ): bool {
		$transfer_type = $this->normalize_transfer_type( $transfer_type );

		return in_array(
			$transfer_type,
			array(
				self::TRANSFER_TYPE_DIRECT_COLLECTION,
				self::TRANSFER_TYPE_RECOVERY,
				self::TRANSFER_TYPE_TERMINATION_COLLECTION,
			),
			true
		);
	}

	public function normalize_transfer_type( string $transfer_type ): string {
		$transfer_type = sanitize_key( $transfer_type );

		$allowed = array(
			self::TRANSFER_TYPE_STANDARD,
			self::TRANSFER_TYPE_DIRECT_COLLECTION,
			self::TRANSFER_TYPE_RECOVERY,
			self::TRANSFER_TYPE_TERMINATION_COLLECTION,
		);

		return in_array( $transfer_type, $allowed, true ) ? $transfer_type : self::TRANSFER_TYPE_STANDARD;
	}

	private function resolve_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			return (int) get_current_user_id();
		}

		return 0;
	}
}
