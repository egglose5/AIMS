<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Service {
	private $person_repository;

	public function __construct( AIMS_Vendor_Person_Repository $person_repository = null ) {
		$this->person_repository = $person_repository ?: new AIMS_Vendor_Person_Repository();
	}

	/**
	 * List all vendors (uses new person-based model)
	 *
	 * @param string $status Optional status filter (active, inactive, archived)
	 * @return array List of vendor arrays
	 */
	public function list_vendors( string $status = '' ): array {
		return $this->person_repository->all( $status );
	}

	/**
	 * Create a vendor (uses new person-based model)
	 *
	 * @param array $data Vendor data (vendor_code, vendor_name, etc.)
	 * @return int The vendor user ID
	 */
	public function create_vendor( array $data ): int {
		return $this->person_repository->save( $data );
	}

	/**
	 * Get vendor by ID (uses new person-based model)
	 *
	 * @param int $vendor_id The vendor user ID
	 * @return array|null Vendor data or null
	 */
	public function get_vendor( int $vendor_id ): ?array {
		return $this->person_repository->find( $vendor_id );
	}

	/**
	 * Update vendor (uses new person-based model)
	 *
	 * @param int   $vendor_id The vendor user ID
	 * @param array $data Vendor data to update
	 * @return int The vendor user ID
	 */
	public function update_vendor( int $vendor_id, array $data ): int {
		return $this->person_repository->save( $data, $vendor_id );
	}

	/**
	 * Archive vendor (uses new person-based model)
	 *
	 * @param int $vendor_id The vendor user ID
	 * @return bool True if successfully archived
	 */
	public function archive_vendor( int $vendor_id ): bool {
		return $this->person_repository->archive( $vendor_id );
	}

	/**
	 * Delete vendor (used for explicit rollback on provisioning failures)
	 *
	 * @param int $vendor_id The vendor user ID
	 * @return bool True when the vendor record was removed or unmarked
	 */
	public function delete_vendor( int $vendor_id ): bool {
		return $this->person_repository->delete( $vendor_id );
	}

	/**
	 * Get sync mapping by Square location (uses new person-based model)
	 *
	 * @param string $square_location_id The Square location ID
	 * @return array|null Mapping data or null
	 */
	public function get_sync_mapping_by_square_location( string $square_location_id ): ?array {
		$vendor = $this->person_repository->find_by_square_location_id( $square_location_id );

		if ( empty( $vendor ) ) {
			return null;
		}

		return array(
			'vendor_id'           => (int) $vendor['user_id'],
			'vendor_name'         => (string) $vendor['vendor_name'],
			'square_location_id'  => (string) $vendor['square_location_id'],
			'square_team_member_id' => (string) ( $vendor['square_team_member_id'] ?? '' ),
			'default_bucket_code' => (string) $vendor['default_bucket_code'],
		);
	}

	/**
	 * Get vendor by vendor code (uses new person-based model)
	 *
	 * @param string $vendor_code The vendor code
	 * @return array|null Vendor data or null
	 */
	public function get_vendor_by_code( string $vendor_code ): ?array {
		return $this->person_repository->find_by_vendor_code( $vendor_code );
	}

	/**
	 * Check if current user is a vendor and has checkin permission
	 *
	 * Uses granular RBAC: RESP_VENDOR_SUBMIT_CHECKIN
	 *
	 * @param int $user_id The user ID to check
	 * @return bool True if user can submit vendor checkins
	 */
	public function can_submit_checkin( int $user_id = 0 ): bool {
		$auth = new AIMS_Responsibility_Authorization_Service();
		return $auth->can_submit_vendor_checkin( $user_id );
	}

	/**
	 * Check if user can view commission data
	 *
	 * Uses granular RBAC: RESP_VENDOR_VIEW_COMMISSION
	 *
	 * @param int $user_id The user ID to check
	 * @return bool True if user can view commission
	 */
	public function can_view_commission( int $user_id = 0 ): bool {
		$auth = new AIMS_Responsibility_Authorization_Service();
		return $auth->can_view_vendor_commission( $user_id );
	}

	/**
	 * Check if user can manage vendor inventory
	 *
	 * Uses granular RBAC: RESP_VENDOR_MANAGE_INVENTORY
	 *
	 * @param int $user_id The user ID to check
	 * @return bool True if user can manage vendor inventory
	 */
	public function can_manage_inventory( int $user_id = 0 ): bool {
		$auth = new AIMS_Responsibility_Authorization_Service();
		return $auth->can_manage_vendor_inventory( $user_id );
	}
}

