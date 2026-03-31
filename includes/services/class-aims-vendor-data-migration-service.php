<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AIMS_Vendor_Data_Migration_Service
 *
 * Migrates vendor data from wpdb aims_vendors table to WordPress user metadata
 * following the new "vendor is subtype of person" model.
 */
class AIMS_Vendor_Data_Migration_Service {
	private $metadata_service;
	private $vendor_repository;
	private $person_repository;

	public function __construct(
		AIMS_Vendor_User_Metadata_Service $metadata_service = null,
		AIMS_Vendor_Repository $vendor_repository = null,
		AIMS_Vendor_Person_Repository $person_repository = null
	) {
		$this->metadata_service = $metadata_service ?: new AIMS_Vendor_User_Metadata_Service();
		$this->vendor_repository = $vendor_repository ?: new AIMS_Vendor_Repository();
		$this->person_repository = $person_repository ?: new AIMS_Vendor_Person_Repository();
	}

	/**
	 * Migrate all vendors from legacy table to user metadata
	 *
	 * Processes each vendor record and stores attributes as user metadata.
	 * Handles user creation for vendors without WordPress accounts.
	 * Marks migration with metadata flag to prevent duplicate processing.
	 *
	 * @return array Migration results: migrated_count, skipped_count, errors
	 */
	public function migrate_all_vendors(): array {
		$vendors = (array) $this->vendor_repository->all();
		$results = array(
			'migrated_count' => 0,
			'skipped_count'  => 0,
			'errors'         => array(),
		);

		foreach ( $vendors as $vendor ) {
			if ( ! is_array( $vendor ) ) {
				continue;
			}

			$vendor_id = (int) ( $vendor['id'] ?? 0 );
			if ( $vendor_id <= 0 ) {
				continue;
			}

			// Check if already migrated
			if ( $this->is_vendor_migrated( $vendor_id ) ) {
				++$results['skipped_count'];
				continue;
			}

			// Attempt migration
			$migration_result = $this->migrate_vendor( $vendor );
			if ( is_wp_error( $migration_result ) ) {
				$results['errors'][] = array(
					'vendor_id' => $vendor_id,
					'vendor_name' => (string) ( $vendor['vendor_name'] ?? '' ),
					'error' => $migration_result->get_error_message(),
				);
				++$results['skipped_count'];
			} else {
				++$results['migrated_count'];
			}
		}

		return $results;
	}

	/**
	 * Migrate a single vendor
	 *
	 * @param array $vendor Vendor record from legacy table
	 * @return int|WP_Error User ID on success, WP_Error on failure
	 */
	public function migrate_vendor( array $vendor ) {
		$vendor_id = (int) ( $vendor['id'] ?? 0 );
		$vendor_name = sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) );
		$email = sanitize_email( (string) ( $vendor['email_address'] ?? '' ) );

		if ( $vendor_id <= 0 || '' === $vendor_name ) {
			return new WP_Error(
				'invalid_vendor_data',
				sprintf( 'Vendor ID %d has invalid data', $vendor_id )
			);
		}

		// Find or create WordPress user
		$user_id = $this->find_or_create_user( $vendor_name, $email );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Store vendor attributes as user metadata
		$metadata_result = $this->store_vendor_metadata( (int) $user_id, $vendor );
		if ( is_wp_error( $metadata_result ) ) {
			return $metadata_result;
		}

		// Mark as migrated
		$this->mark_vendor_as_migrated( $vendor_id, (int) $user_id );

		return (int) $user_id;
	}

	/**
	 * Find existing WordPress user or create new one for vendor
	 *
	 * Priority:
	 * 1. User with matching email
	 * 2. User with matching vendor code in usermeta
	 * 3. Create new user with sanitized vendor name
	 *
	 * @param string $vendor_name Name of vendor
	 * @param string $email Email address of vendor
	 * @return int|WP_Error User ID or WP_Error
	 */
	private function find_or_create_user( string $vendor_name, string $email ) {
		// First try to find by email
		if ( '' !== $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof WP_User ) {
				return (int) $user->ID;
			}
		}

		// Create new vendor user
		$username = $this->generate_vendor_username( $vendor_name );
		if ( '' === $username ) {
			return new WP_Error(
				'invalid_vendor_name',
				'Could not generate valid username from vendor name: ' . $vendor_name
			);
		}

		$user_email = '' !== $email && is_email( $email ) ? $email : 'vendor-' . sanitize_key( $vendor_name ) . '@local.invalid';

		$user_id = wp_create_user(
			$username,
			wp_generate_password(),
			$user_email
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Set user display name
		wp_update_user( array(
			'ID'           => (int) $user_id,
			'display_name' => $vendor_name,
		) );

		return (int) $user_id;
	}

	/**
	 * Generate valid WordPress username from vendor name
	 *
	 * Uses vendor name, sanitizes to alphanumeric + underscore + hyphen
	 * Ensures uniqueness by appending numeric suffix if needed
	 *
	 * @param string $vendor_name Name of vendor
	 * @return string Username or empty string if generation fails
	 */
	private function generate_vendor_username( string $vendor_name ): string {
		// Sanitize to valid WordPress username chars
		$base_username = sanitize_key( $vendor_name );
		$base_username = substr( $base_username, 0, 60 ); // Max username length

		if ( '' === $base_username ) {
			return '';
		}

		// Check if username already exists
		if ( ! username_exists( $base_username ) ) {
			return $base_username;
		}

		// Append numeric suffix to make unique
		for ( $i = 2; $i <= 100; ++$i ) {
			$candidate = substr( $base_username . '-' . $i, 0, 60 );
			if ( ! username_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Store vendor attributes as user metadata
	 *
	 * Maps vendor table columns to metadata keys using vocabulary from
	 * AIMS_Vendor_Metadata constants
	 *
	 * @param int   $user_id WordPress user ID
	 * @param array $vendor Vendor record from legacy table
	 * @return true|WP_Error True on success, WP_Error on failure
	 */
	private function store_vendor_metadata( int $user_id, array $vendor ) {
		$metadata = array(
			'vendor_code'         => (string) ( $vendor['vendor_code'] ?? '' ),
			'vendor_name'         => sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) ),
			'status'              => sanitize_key( (string) ( $vendor['status'] ?? 'active' ) ),
			'square_location_id'  => sanitize_text_field( (string) ( $vendor['square_location_id'] ?? '' ) ),
			'square_team_member_id' => sanitize_text_field( (string) ( $vendor['square_team_member_id'] ?? '' ) ),
			'default_bucket_id'   => (int) ( $vendor['default_bucket_id'] ?? 0 ),
			'default_bucket_code' => sanitize_text_field( (string) ( $vendor['default_bucket_code'] ?? '' ) ),
			'commission_rate'     => (float) ( $vendor['commission_rate'] ?? 0 ),
			'phone_number'        => sanitize_text_field( (string) ( $vendor['phone_number'] ?? '' ) ),
			'email_address'       => sanitize_email( (string) ( $vendor['email_address'] ?? '' ) ),
			'address_line_1'      => sanitize_text_field( (string) ( $vendor['address_line_1'] ?? '' ) ),
			'address_line_2'      => sanitize_text_field( (string) ( $vendor['address_line_2'] ?? '' ) ),
			'city'                => sanitize_text_field( (string) ( $vendor['city'] ?? '' ) ),
			'state_region'        => sanitize_text_field( (string) ( $vendor['state_region'] ?? '' ) ),
			'postal_code'         => sanitize_text_field( (string) ( $vendor['postal_code'] ?? '' ) ),
			'country_code'        => strtoupper( sanitize_text_field( (string) ( $vendor['country_code'] ?? 'US' ) ) ),
			'notes'               => isset( $vendor['notes'] ) ? wp_kses_post( (string) $vendor['notes'] ) : '',
			'legacy_vendor_id'    => (int) ( $vendor['id'] ?? 0 ),
			'migrated_at'         => current_time( 'mysql' ),
		);

		try {
			// Update each vendor attribute
			foreach ( $metadata as $key => $value ) {
				// Map legacy keys to standard metadata keys
				$meta_key = 'aims_vendor_' . $key;
				if ( ! $this->metadata_service->update_vendor_meta( $user_id, $meta_key, $value ) ) {
					return new WP_Error(
						'metadata_update_failed',
						sprintf( 'Failed to save vendor metadata key: %s', $key )
					);
				}
			}

			// Mark user as vendor
			if ( ! $this->metadata_service->mark_as_vendor( $user_id ) ) {
				return new WP_Error(
					'mark_as_vendor_failed',
					'Failed to mark user as vendor'
				);
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error(
				'metadata_save_failed',
				'Failed to save vendor metadata: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Mark vendor as migrated to prevent duplicate processing
	 *
	 * Stores migration flag in user metadata referencing original vendor ID
	 *
	 * @param int $vendor_id Original vendor table ID
	 * @param int $user_id WordPress user ID
	 * @return void
	 */
	private function mark_vendor_as_migrated( int $vendor_id, int $user_id ): void {
		update_user_meta( $user_id, 'aims_vendor_migration_complete', array(
			'migrated_at'       => current_time( 'mysql' ),
			'original_vendor_id' => $vendor_id,
		) );
	}

	/**
	 * Check if vendor has already been migrated
	 *
	 * Looks for migration flag in user metadata to avoid reprocessing
	 *
	 * @param int $vendor_id Original vendor table ID
	 * @return bool True if vendor already migrated, false otherwise
	 */
	private function is_vendor_migrated( int $vendor_id ): bool {
		global $wpdb;

		$migrated = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
				WHERE meta_key = %s
				AND meta_value LIKE %s",
				'aims_vendor_migration_complete',
				'%"original_vendor_id":"' . $vendor_id . '"%'
			)
		);

		return ! empty( $migrated );
	}

	/**
	 * Get migration status for all vendors
	 *
	 * Returns summary of which vendors have been migrated
	 *
	 * @return array Status with migrated_count, total_count, pending_count
	 */
	public function get_migration_status(): array {
		$all_vendors = (array) $this->vendor_repository->all();
		$total_count = count( $all_vendors );
		$migrated_count = 0;

		foreach ( $all_vendors as $vendor ) {
			if ( ! is_array( $vendor ) ) {
				continue;
			}

			$vendor_id = (int) ( $vendor['id'] ?? 0 );
			if ( $vendor_id > 0 && $this->is_vendor_migrated( $vendor_id ) ) {
				++$migrated_count;
			}
		}

		return array(
			'total_count'    => $total_count,
			'migrated_count' => $migrated_count,
			'pending_count'  => $total_count - $migrated_count,
		);
	}
}
