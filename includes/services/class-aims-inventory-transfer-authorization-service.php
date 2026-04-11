<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Transfer_Authorization_Service {
	public const TRANSFER_TYPE_STANDARD              = 'standard';
	public const TRANSFER_TYPE_DIRECT_COLLECTION     = 'direct_collection';
	public const TRANSFER_TYPE_RECOVERY              = 'recovery';
	public const TRANSFER_TYPE_TERMINATION_COLLECTION = 'termination_collection';

	public const NODE_CONTEXT_CREATE   = 'create';
	public const NODE_CONTEXT_DISPATCH = 'dispatch';
	public const NODE_CONTEXT_RECEIPT  = 'receipt';

	private $endpoint_directory;
	private $person_identity;
	private $responsibility_auth;

	public function __construct(
		AIMS_Inventory_Endpoint_Directory_Service $endpoint_directory = null,
		AIMS_Person_Identity_Service $person_identity = null,
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null
	) {
		$this->endpoint_directory = $endpoint_directory ?: new AIMS_Inventory_Endpoint_Directory_Service();
		$this->person_identity    = $person_identity ?: new AIMS_Person_Identity_Service();
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $this->has_global_custody_authority( $user_id ) ) {
			return true;
		}

		if ( $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_INVENTORY ) ) {
			return true;
		}

		return $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE );
	}

	public function can_override_transfer_route( int $user_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
		$user_id       = $this->resolve_user_id( $user_id );
		$transfer_type = $this->normalize_transfer_type( $transfer_type );

		if ( ! $this->can_manage_inventory_transfers( $user_id ) ) {
			return false;
		}

		$allowed = $this->has_global_custody_authority( $user_id );

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

	public function can_act_on_custody_node( int $user_id = 0, string $node_type = '', int $node_id = 0, string $context = self::NODE_CONTEXT_CREATE ): bool {
		$user_id   = $this->resolve_user_id( $user_id );
		$node_type = sanitize_key( $node_type );
		$node_id   = (int) $node_id;
		$context   = sanitize_key( $context );

		if ( $user_id <= 0 || '' === $node_type || $node_id <= 0 ) {
			return false;
		}

		if ( ! $this->can_manage_inventory_transfers( $user_id ) ) {
			return false;
		}

		if ( $this->has_global_custody_authority( $user_id ) ) {
			return true;
		}

		$resolved_endpoint_id = 0;
		if ( is_object( $this->endpoint_directory ) && method_exists( $this->endpoint_directory, 'resolve_endpoint_from_node' ) ) {
			$endpoint = $this->endpoint_directory->resolve_endpoint_from_node( $node_id, $node_type, $user_id );
			if ( is_array( $endpoint ) && ! empty( $endpoint ) ) {
				return true;
			}
			$resolved_endpoint_id = (int) ( $endpoint['id'] ?? 0 );
		}

		if ( $this->matches_node_type_authority( $user_id, $node_type, $context ) ) {
			return true;
		}

		// Fallback: check SCOPE_CUSTODY responsibility assignment directly.
		if ( $node_id > 0 && is_object( $this->responsibility_auth ) && method_exists( $this->responsibility_auth, 'user_can_access_custody_endpoint' ) ) {
			$check_id = $resolved_endpoint_id > 0 ? $resolved_endpoint_id : $node_id;
			if ( $this->responsibility_auth->user_can_access_custody_endpoint( $user_id, $check_id ) ) {
				return true;
			}
		}

		return false;
	}

	public function can_manage_transfer_nodes( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
		return $this->can_manage_transfer_route( $user_id, $source_node_type, $source_node_id, $target_node_type, $target_node_id, $transfer_type, self::NODE_CONTEXT_CREATE );
	}

	public function can_dispatch_transfer( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
		return $this->can_manage_transfer_route( $user_id, $source_node_type, $source_node_id, $target_node_type, $target_node_id, $transfer_type, self::NODE_CONTEXT_DISPATCH )
			|| $this->can_manage_transfer_nodes( $user_id, $source_node_type, $source_node_id, $target_node_type, $target_node_id, $transfer_type );
	}

	public function can_confirm_transfer_receipt( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
		return $this->can_manage_transfer_route( $user_id, $source_node_type, $source_node_id, $target_node_type, $target_node_id, $transfer_type, self::NODE_CONTEXT_RECEIPT )
			|| $this->can_manage_transfer_nodes( $user_id, $source_node_type, $source_node_id, $target_node_type, $target_node_id, $transfer_type );
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

	private function can_manage_transfer_route( int $user_id, string $source_node_type, int $source_node_id, string $target_node_type, int $target_node_id, string $transfer_type, string $context ): bool {
		$user_id          = $this->resolve_user_id( $user_id );
		$source_node_type = sanitize_key( $source_node_type );
		$target_node_type = sanitize_key( $target_node_type );
		$source_node_id   = (int) $source_node_id;
		$target_node_id   = (int) $target_node_id;
		$transfer_type    = $this->normalize_transfer_type( $transfer_type );
		$context          = sanitize_key( $context );

		if ( ! $this->can_manage_inventory_transfers( $user_id ) ) {
			return false;
		}

		if ( ! $this->has_valid_node_reference( $source_node_type, $source_node_id ) || ! $this->has_valid_node_reference( $target_node_type, $target_node_id ) ) {
			return false;
		}

		if ( self::NODE_CONTEXT_DISPATCH === $context ) {
			return $this->can_act_on_custody_node( $user_id, $source_node_type, $source_node_id, self::NODE_CONTEXT_DISPATCH );
		}

		if ( self::NODE_CONTEXT_RECEIPT === $context ) {
			return $this->can_act_on_custody_node( $user_id, $target_node_type, $target_node_id, self::NODE_CONTEXT_RECEIPT );
		}

		return $this->can_act_on_custody_node( $user_id, $source_node_type, $source_node_id, self::NODE_CONTEXT_CREATE )
			&& $this->can_act_on_custody_node( $user_id, $target_node_type, $target_node_id, self::NODE_CONTEXT_CREATE );
	}

	private function has_valid_node_reference( string $node_type, int $node_id ): bool {
		return '' !== sanitize_key( $node_type ) && $node_id > 0;
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

	private function has_global_custody_authority( int $user_id ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL )
			|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE );
	}

	private function matches_node_type_authority( int $user_id, string $node_type, string $context = self::NODE_CONTEXT_CREATE ): bool {
		$node_type = sanitize_key( $node_type );
		$context   = sanitize_key( $context );
		$is_person = is_object( $this->person_identity ) && method_exists( $this->person_identity, 'is_aims_person' ) && $this->person_identity->is_aims_person( $user_id );

		switch ( $node_type ) {
			case 'warehouse':
				return $this->has_global_custody_authority( $user_id )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_INVENTORY )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_PHYSICAL_BUCKETS )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_EVENT_BUCKETS );

			case 'supervisor':
				return $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING )
					|| ( $is_person && function_exists( 'apply_filters' ) && (bool) apply_filters( 'aims_inventory_transfer_allow_supervisor_node', false, $user_id, $context ) );

			case 'vendor':
				return $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_VENDOR_ACCESS )
					|| ( $is_person && $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR ) );

			case 'stitcher':
				return $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_STITCH )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_STITCH_ORDERS )
					|| ( $is_person && $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_STITCH ) );

			case 'event':
				return $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_VIEW_EVENTS_SHELL )
					|| $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_EVENTS );

			default:
				return $this->user_has_cap( $user_id, AIMS_Capabilities::CAP_MANAGE_INVENTORY );
		}
	}

	private function user_has_cap( int $user_id, string $cap ): bool {
		if ( $user_id <= 0 || '' === $cap ) {
			return false;
		}

		return function_exists( 'user_can' ) && user_can( $user_id, $cap );
	}
}
