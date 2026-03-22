<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Access_Service {
	private $access;
	private $vendors;

	public function __construct(
		AIMS_Vendor_User_Access_Repository $access,
		AIMS_Vendor_Repository $vendors
	) {
		$this->access  = $access;
		$this->vendors = $vendors;
	}

	public function grant_vendor_responsibility( int $vendor_id, int $user_id, array $data = array() ): int {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( ! $this->can_manage_vendor_scope( $vendor_id, $user_id ) ) {
			return 0;
		}

		return $this->access->grant_access( $vendor_id, $user_id, $data );
	}

	public function revoke_vendor_responsibility( int $vendor_id, int $user_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( ! $this->can_manage_vendor_scope( $vendor_id, $user_id ) ) {
			return false;
		}

		return $this->access->revoke_access( $vendor_id, $user_id );
	}

	public function get_accessible_vendors_for_user( int $user_id ): array {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return $this->vendors->all();
		}

		return $this->vendors->find_by_ids( $this->access->get_vendor_ids_for_user( $user_id ) );
	}

	public function user_has_vendor_access( int $vendor_id, int $user_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_view_vendor( $vendor_id, $user_id );
	}

	public function user_can_manage_vendor( int $vendor_id, int $user_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_manage_vendor( $vendor_id, $user_id );
	}

	public function require_vendor_view_access( int $vendor_id, int $user_id ): ?WP_Error {
		if ( $this->user_has_vendor_access( $vendor_id, $user_id ) ) {
			return null;
		}

		return new WP_Error( 'aims_vendor_access_denied', 'The current user cannot view this vendor.' );
	}

	public function require_vendor_manage_access( int $vendor_id, int $user_id ): ?WP_Error {
		if ( $this->user_can_manage_vendor( $vendor_id, $user_id ) ) {
			return null;
		}

		return new WP_Error( 'aims_vendor_manage_denied', 'The current user cannot manage this vendor.' );
	}

	public function can_manage_all_vendors( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return user_can( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_VENDORS );
	}

	private function can_manage_vendor_scope( int $vendor_id, int $user_id ): bool {
		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_manage_vendor( $vendor_id, $user_id );
	}

	private function resolve_actor_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		return (int) get_current_user_id();
	}
}
