<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor User Access Repository Adapter
 *
 * Adapter that translates legacy vendor ID queries to the new person-based vendor model.
 * This allows gradual migration from the old vendors table to user metadata without breaking
 * existing code.
 *
 * DEPRECATION NOTE: This is a transitional bridge. Code should migrate to use
 * AIMS_Vendor_Person_Repository and responsibility assignments instead.
 */
class AIMS_Vendor_User_Access_Repository_Adapter {

	private $metadata_service;
	private $person_repository;

	public function __construct(
		AIMS_Vendor_User_Metadata_Service $metadata_service = null,
		AIMS_Vendor_Person_Repository $person_repository = null
	) {
		$this->metadata_service = $metadata_service ?: new AIMS_Vendor_User_Metadata_Service();
		$this->person_repository = $person_repository ?: new AIMS_Vendor_Person_Repository( $this->metadata_service );
	}

	/**
	 * Get vendor IDs for a user (translates user to vendors they're associated with)
	 *
	 * In the new model, this means:
	 * 1. If the user IS a vendor (has vendor metadata), return their user ID as a "vendor ID"
	 * 2. If looking for ancestor vendors (responsibility scope), fetch via responsibility assignments
	 *
	 * @param int $user_id The user ID
	 * @return array Array of vendor IDs (in new model, these are user IDs with vendor metadata)
	 */
	public function get_vendor_ids_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$vendor_ids = array();

		// If this user IS a vendor, they manage their own vendor scope
		if ( $this->metadata_service->is_vendor( $user_id ) ) {
			$vendor_ids[] = $user_id;
		}

		// Check if user has vendor responsibilities assigned to them
		// This would come from responsibility assignments with vendor scope
		$responsibility_repo = new AIMS_Responsibility_Assignment_Repository();

		// Get vendor scope assignments for this user
		$vendor_scope_ids = $responsibility_repo->get_scope_ref_ids_for_user(
			$user_id,
			AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT,
			AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR
		);

		// Merge any vendor-scoped permissions they have
		$vendor_ids = array_merge( $vendor_ids, $vendor_scope_ids );

		return array_values( array_unique( array_filter( array_map( 'intval', $vendor_ids ) ) ) );
	}

	/**
	 * Get user IDs for a vendor (translates legacy vendor ID to user ID)
	 *
	 * In the new model, the "vendor ID" IS the user ID of the vendor person.
	 *
	 * @param int $vendor_id The vendor ID (which is user ID in new model)
	 * @return array Array of user IDs who have access to this vendor
	 */
	public function get_user_ids_for_vendor( int $vendor_id ): array {
		if ( $vendor_id <= 0 ) {
			return array();
		}

		// The vendor_id is the user_id of the vendor person
		$vendor = $this->person_repository->find( $vendor_id );
		if ( ! $vendor ) {
			return array();
		}

		$user_ids = array( $vendor_id ); // The vendor owns their own vendor account

		// Find users with responsibility assignments for this vendor
		$responsibility_repo = new AIMS_Responsibility_Assignment_Repository();
		$assigned_users = $responsibility_repo->get_user_ids_for_responsibility(
			AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT,
			AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR,
			$vendor_id
		);

		$user_ids = array_merge( $user_ids, $assigned_users );

		return array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
	}

	/**
	 * Legacy save method - ensures vendor has user metadata
	 *
	 * DEPRECATED: Use AIMS_Vendor_Person_Repository::save() instead
	 *
	 * @param array $data Vendor access data
	 * @param int   $access_id Ignored in new model
	 * @return int The user ID
	 */
	public function save( array $data, int $access_id = 0 ): int {
		$vendor_id = (int) ( $data['vendor_id'] ?? 0 );
		$user_id = (int) ( $data['user_id'] ?? 0 );

		if ( $vendor_id <= 0 || $user_id <= 0 ) {
			return 0;
		}

		// In new model: create responsibility assignment for vendor access
		$responsibility_repo = new AIMS_Responsibility_Assignment_Repository();

		$access_role = sanitize_key( $data['access_role'] ?? 'viewer' );
		$responsibility = $this->map_access_role_to_responsibility( $access_role );

		if ( ! $responsibility ) {
			return 0;
		}

		// Create or update responsibility assignment
		$responsibility_repo->save(
			array(
				'user_id'        => $user_id,
				'responsibility' => $responsibility,
				'scope'          => AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR,
				'scope_ref_id'   => $vendor_id,
				'status'         => 'active',
			)
		);

		return $user_id;
	}

	/**
	 * Legacy find method
	 *
	 * @param int $access_id Ignored in new model
	 * @return array|null Empty array for compatibility
	 */
	public function find( int $access_id ): ?array {
		// In new model, this lookup doesn't really apply
		// Return null to indicate legacy data structure unavailable
		return null;
	}

	/**
	 * Get all vendor-user access records (legacy)
	 *
	 * DEPRECATED: Responsibility assignments should be used instead
	 *
	 * @return array Empty array for compatibility
	 */
	public function all(): array {
		// In new model, use responsibility assignments instead
		return array();
	}

	/**
	 * Get vendor-user access for a specific user
	 *
	 * DEPRECATED: Use get_vendor_ids_for_user() instead
	 *
	 * @param int $user_id The user ID
	 * @return array Array of vendor assignments
	 */
	public function get_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$vendor_ids = $this->get_vendor_ids_for_user( $user_id );
		$records = array();

		foreach ( $vendor_ids as $vendor_id ) {
			$records[] = array(
				'vendor_id'   => $vendor_id,
				'user_id'     => $user_id,
				'access_role' => $this->infer_access_role( $user_id, $vendor_id ),
			);
		}

		return $records;
	}

	/**
	 * Map legacy access role to new granular responsibility
	 *
	 * @param string $access_role Legacy role (viewer, manager, editor)
	 * @return string|false Responsibility key or false
	 */
	private function map_access_role_to_responsibility( string $access_role ) {
		switch ( $access_role ) {
			case 'viewer':
				return AIMS_Responsibility_Authorization_Service::RESP_VENDOR_VIEW_COMMISSION;
			case 'manager':
				return AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT;
			case 'editor':
				return AIMS_Responsibility_Authorization_Service::RESP_VENDOR_SUBMIT_CHECKIN;
			default:
				return false;
		}
	}

	/**
	 * Infer access role from responsibilities
	 *
	 * @param int $user_id The user ID
	 * @param int $vendor_id The vendor ID
	 * @return string Inferred access role (viewer, editor, manager)
	 */
	private function infer_access_role( int $user_id, int $vendor_id ): string {
		$auth = new AIMS_Responsibility_Authorization_Service();

		if ( $auth->can_manage_vendors( $user_id ) ) {
			return 'manager';
		}

		if ( $auth->can_submit_vendor_checkin( $user_id ) ) {
			return 'editor';
		}

		return 'viewer';
	}
}
