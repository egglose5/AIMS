<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Service {
	private $vendors;

	public function __construct( AIMS_Vendor_Repository $vendors ) {
		$this->vendors = $vendors;
	}

	public function list_vendors(): array {
		return $this->vendors->all();
	}

	public function create_vendor( array $data ): int {
		return $this->vendors->save( $data );
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
}

