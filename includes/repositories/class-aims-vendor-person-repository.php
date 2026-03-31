<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor Person Repository
 *
 * Implements "vendor is a subtype of person" by treating WordPress users with vendor metadata
 * as vendors using a metadata-based model.
 */
class AIMS_Vendor_Person_Repository {

	private $metadata_service;
	private $assignment_repository;

	public function __construct(
		AIMS_Vendor_User_Metadata_Service $metadata_service = null,
		AIMS_Responsibility_Assignment_Repository $assignment_repository = null
	) {
		$this->metadata_service = $metadata_service ?: new AIMS_Vendor_User_Metadata_Service();
		$this->assignment_repository = $assignment_repository ?: new AIMS_Responsibility_Assignment_Repository();
	}

	/**
	 * Get all vendors (users with vendor metadata)
	 *
	 * @param string $status Filter by vendor status (active, inactive, archived), or '' for all
	 * @return array List of vendor user arrays with metadata
	 */
	public function all( string $status = '' ): array {
		$user_ids = $this->metadata_service->get_all_vendors( $status );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$vendors = array();

		foreach ( $user_ids as $user_id ) {
			$vendor = $this->format_vendor_user( $user_id );
			if ( $vendor ) {
				$vendors[] = $vendor;
			}
		}

		return $vendors;
	}

	/**
	 * Find a vendor by user ID
	 *
	 * @param int $user_id The user ID
	 * @return array|null Vendor user data or null if not a vendor
	 */
	public function find( int $user_id ): ?array {
		if ( $user_id <= 0 || ! $this->metadata_service->is_vendor( $user_id ) ) {
			return null;
		}

		return $this->format_vendor_user( $user_id );
	}

	/**
	 * Find vendor by vendor code
	 *
	 * @param string $vendor_code The vendor code
	 * @return array|null Vendor data or null
	 */
	public function find_by_vendor_code( string $vendor_code ): ?array {
		if ( '' === $vendor_code ) {
			return null;
		}

		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} 
				 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				AIMS_Vendor_Metadata::META_VENDOR_CODE,
				$vendor_code
			)
		);

		if ( empty( $user_id ) ) {
			return null;
		}

		return $this->find( (int) $user_id );
	}

	/**
	 * Find vendor by Square location ID
	 *
	 * @param string $square_location_id The Square location ID
	 * @return array|null Vendor data or null
	 */
	public function find_by_square_location_id( string $square_location_id ): ?array {
		$user_id = $this->metadata_service->find_user_by_square_location( $square_location_id );

		if ( ! $user_id ) {
			return null;
		}

		return $this->find( $user_id );
	}

	/**
	 * Save vendor metadata for a user (create or update)
	 *
	 * @param array $data Vendor data to save
	 * @param int   $user_id The user ID (0 to create new user)
	 * @return int The user ID
	 */
	public function save( array $data, int $user_id = 0 ): int {
		// If no user ID, create WordPress user account
		if ( $user_id <= 0 ) {
			$user_id = $this->create_vendor_user( $data );
			if ( $user_id <= 0 ) {
				return 0;
			}
		}

		// Update vendor metadata
		$this->metadata_service->mark_as_vendor( $user_id );

		// Update specific vendor attributes
		$meta_mappings = array(
			'vendor_code'         => AIMS_Vendor_Metadata::META_VENDOR_CODE,
			'vendor_name'         => AIMS_Vendor_Metadata::META_VENDOR_NAME,
			'commission_rate'     => AIMS_Vendor_Metadata::META_COMMISSION_RATE,
			'phone_number'        => AIMS_Vendor_Metadata::META_PHONE_NUMBER,
			'email_address'       => AIMS_Vendor_Metadata::META_EMAIL_ADDRESS,
			'address_line_1'      => AIMS_Vendor_Metadata::META_ADDRESS_LINE_1,
			'address_line_2'      => AIMS_Vendor_Metadata::META_ADDRESS_LINE_2,
			'city'                => AIMS_Vendor_Metadata::META_CITY,
			'state_region'        => AIMS_Vendor_Metadata::META_STATE_REGION,
			'postal_code'         => AIMS_Vendor_Metadata::META_POSTAL_CODE,
			'country_code'        => AIMS_Vendor_Metadata::META_COUNTRY_CODE,
			'square_location_id'  => AIMS_Vendor_Metadata::META_SQUARE_LOCATION_ID,
			'square_team_member_id' => AIMS_Vendor_Metadata::META_SQUARE_TEAM_MEMBER_ID,
			'default_bucket_id'   => AIMS_Vendor_Metadata::META_DEFAULT_BUCKET_ID,
			'default_bucket_code' => AIMS_Vendor_Metadata::META_DEFAULT_BUCKET_CODE,
			'notes'               => AIMS_Vendor_Metadata::META_VENDOR_NOTES,
			'status'              => AIMS_Vendor_Metadata::META_VENDOR_STATUS,
		);

		foreach ( $meta_mappings as $data_key => $meta_key ) {
			if ( isset( $data[ $data_key ] ) ) {
				$this->metadata_service->update_vendor_meta( $user_id, $meta_key, $data[ $data_key ] );
			}
		}

		return $user_id;
	}

	/**
	 * Archive vendor (mark as archived, don't delete user)
	 *
	 * @param int $user_id The user ID to archive
	 * @return bool True if successfully archived
	 */
	public function archive( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->metadata_service->update_vendor_meta(
			$user_id,
			AIMS_Vendor_Metadata::META_VENDOR_STATUS,
			'archived'
		);
	}

	/**
	 * Get vendor users for a specific responsibility scope (event-based, vendor-based, etc.)
	 *
	 * Uses responsibility assignments to find users with vendor responsibilities.
	 *
	 * @param string $responsibility The responsibility key to filter by
	 * @param int    $scope_ref_id The scope reference ID (event ID, vendor ID, etc.)
	 * @return array List of user IDs with this vendor responsibility
	 */
	public function get_users_with_vendor_responsibility( string $responsibility, int $scope_ref_id = 0 ): array {
		// Resolve through the canonical responsibility assignment repository.
		if ( $scope_ref_id > 0 ) {
			return $this->assignment_repository->get_user_ids_for_responsibility(
				$responsibility,
				AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR,
				$scope_ref_id
			);
		}

		return $this->assignment_repository->get_user_ids_for_responsibility( $responsibility );
	}

	/**
	 * Find users by email address (vendor or personal)
	 *
	 * @param string $email The email address
	 * @return array Array of user IDs
	 */
	public function find_users_by_email( string $email ): array {
		if ( '' === $email || ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'search' => $email,
				'fields' => 'ID',
			)
		);

		return (array) $users;
	}

	/**
	 * Format vendor user data into a consistent array
	 *
	 * @param int $user_id The user ID
	 * @return array|null Formatted vendor array or null if not a vendor
	 */
	private function format_vendor_user( int $user_id ): ?array {
		if ( ! $this->metadata_service->is_vendor( $user_id ) ) {
			return null;
		}

		if ( ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return null;
		}

		return array(
			'id'                  => $user_id,
			'user_id'             => $user_id,
			'vendor_code'         => $this->metadata_service->get_vendor_code( $user_id ),
			'vendor_name'         => $this->metadata_service->get_vendor_name( $user_id ),
			'commission_rate'     => $this->metadata_service->get_commission_rate( $user_id ),
			'phone_number'        => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_PHONE_NUMBER, '' ),
			'email_address'       => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_EMAIL_ADDRESS, $user->user_email ),
			'address_line_1'      => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_ADDRESS_LINE_1, '' ),
			'address_line_2'      => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_ADDRESS_LINE_2, '' ),
			'city'                => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_CITY, '' ),
			'state_region'        => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_STATE_REGION, '' ),
			'postal_code'         => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_POSTAL_CODE, '' ),
			'country_code'        => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_COUNTRY_CODE, 'US' ),
			'square_location_id'  => $this->metadata_service->get_square_location_id( $user_id ),
			'square_team_member_id' => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_SQUARE_TEAM_MEMBER_ID, '' ),
			'default_bucket_id'   => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_DEFAULT_BUCKET_ID, 0 ),
			'default_bucket_code' => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_DEFAULT_BUCKET_CODE, '' ),
			'status'              => $this->metadata_service->get_vendor_status( $user_id ),
			'notes'               => $this->metadata_service->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_VENDOR_NOTES, '' ),
			'created_at'          => $user->user_registered ?? current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);
	}

	/**
	 * Create a WordPress user account for vendor
	 *
	 * @param array $data Vendor data including user_login, user_email
	 * @return int The new user ID or 0 on failure
	 */
	private function create_vendor_user( array $data ): int {
		if ( ! function_exists( 'wp_create_user' ) ) {
			return 0;
		}

		$username = sanitize_user( $data['user_login'] ?? $data['vendor_code'] ?? '' );
		$email = sanitize_email( $data['email_address'] ?? $data['user_email'] ?? '' );
		$password = wp_generate_password();

		if ( '' === $username || '' === $email ) {
			return 0;
		}

		$user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		// Update display name
		if ( isset( $data['vendor_name'] ) && ! empty( $data['vendor_name'] ) ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $data['vendor_name'],
				)
			);
		}

		return (int) $user_id;
	}
}
