<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Service {
	private $vendors;
	private $access;

	public function __construct(
		AIMS_Vendor_Repository $vendors,
		AIMS_Vendor_Access_Service $access = null
	) {
		$this->vendors = $vendors;
		$this->access   = $access;
	}

	public function list_vendors( int $user_id = 0 ): array {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( null !== $this->access && $user_id > 0 && ! $this->access->can_manage_all_vendors( $user_id ) ) {
			return $this->access->get_accessible_vendors_for_user( $user_id );
		}

		return $this->vendors->all();
	}

	public function create_vendor( array $data, int $user_id = 0 ): int {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( null !== $this->access && ! $this->access->can_manage_all_vendors( $user_id ) ) {
			return 0;
		}

		return $this->vendors->save( $data );
	}

	public function get_vendor( int $vendor_id, int $user_id = 0 ): ?array {
		$user_id = $this->resolve_actor_user_id( $user_id );
		$vendor = $this->vendors->find( $vendor_id );

		if ( empty( $vendor ) ) {
			return null;
		}

		if ( null !== $this->access && $user_id > 0 && ! $this->access->user_has_vendor_access( $vendor_id, $user_id ) ) {
			return null;
		}

		return $vendor;
	}

	public function get_sync_mapping_by_square_location( string $square_location_id ): ?array {
		$vendor = $this->vendors->find_by_square_location_id( $square_location_id );

		if ( empty( $vendor ) ) {
			return null;
		}

		return array(
			'vendor_id'           => (int) $vendor['id'],
			'vendor_name'         => (string) $vendor['vendor_name'],
			'square_location_id'  => (string) $vendor['square_location_id'],
			'default_bucket_code' => (string) $vendor['default_bucket_code'],
		);
	}

	private function resolve_actor_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		return (int) get_current_user_id();
	}
}
