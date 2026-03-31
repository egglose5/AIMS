<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor User Metadata Service
 *
 * Handles reading and writing vendor attributes to/from WordPress user metadata.
 * Implements the concept of "vendor is a subtype of person" by storing vendor properties
 * as user meta instead of in a separate vendors table.
 */
class AIMS_Vendor_User_Metadata_Service {

	/**
	 * Get a vendor attribute from user metadata
	 *
	 * @param int    $user_id The WordPress user ID
	 * @param string $meta_key The vendor meta key (from AIMS_Vendor_Metadata)
	 * @param mixed  $default Default value if meta not found
	 * @return mixed The meta value
	 */
	public function get_vendor_meta( int $user_id, string $meta_key, $default = null ) {
		if ( $user_id <= 0 ) {
			return $default;
		}

		$value = get_user_meta( $user_id, $meta_key, true );

		if ( '' === $value ) {
			return AIMS_Vendor_Metadata::get_default( $meta_key ) ?? $default;
		}

		// Type casting
		if ( AIMS_Vendor_Metadata::should_be_boolean( $meta_key ) ) {
			return (bool) $value;
		}

		if ( AIMS_Vendor_Metadata::should_be_numeric( $meta_key ) ) {
			return (float) $value;
		}

		return $value;
	}

	/**
	 * Set a vendor attribute in user metadata
	 *
	 * @param int    $user_id The WordPress user ID
	 * @param string $meta_key The vendor meta key (from AIMS_Vendor_Metadata)
	 * @param mixed  $value The value to store
	 * @return bool True if successfully updated
	 */
	public function update_vendor_meta( int $user_id, string $meta_key, $value ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		// Validate/normalize value
		if ( AIMS_Vendor_Metadata::should_be_boolean( $meta_key ) ) {
			$value = (bool) $value;
		} elseif ( AIMS_Vendor_Metadata::should_be_numeric( $meta_key ) ) {
			$value = (float) $value;
		} else {
			$value = (string) $value;
		}

		return (bool) update_user_meta( $user_id, $meta_key, $value );
	}

	/**
	 * Delete a vendor attribute from user metadata
	 *
	 * @param int    $user_id The WordPress user ID
	 * @param string $meta_key The vendor meta key
	 * @return bool True if successfully deleted
	 */
	public function delete_vendor_meta( int $user_id, string $meta_key ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) delete_user_meta( $user_id, $meta_key );
	}

	/**
	 * Get all vendor metadata for a user as an associative array
	 *
	 * @param int $user_id The WordPress user ID
	 * @return array Associative array of vendor attributes
	 */
	public function get_all_vendor_attributes( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$attributes = array();

		foreach ( AIMS_Vendor_Metadata::get_all_meta_keys() as $meta_key ) {
			$attributes[ $meta_key ] = $this->get_vendor_meta( $user_id, $meta_key );
		}

		return $attributes;
	}

	/**
	 * Check if a user is marked as a vendor
	 *
	 * @param int $user_id The WordPress user ID
	 * @return bool True if user has vendor attributes
	 */
	public function is_vendor( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_IS_VENDOR, false );
	}

	/**
	 * Mark a user as a vendor
	 *
	 * @param int $user_id The WordPress user ID
	 * @return bool True if successfully marked
	 */
	public function mark_as_vendor( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->update_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_IS_VENDOR, true );
	}

	/**
	 * Unmark a user as vendor (remove vendor attributes)
	 *
	 * @param int $user_id The WordPress user ID
	 * @return bool True if successfully unmarked
	 */
	public function unmark_as_vendor( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		foreach ( AIMS_Vendor_Metadata::get_all_meta_keys() as $meta_key ) {
			$this->delete_vendor_meta( $user_id, $meta_key );
		}

		return true;
	}

	/**
	 * Get vendor code for user
	 *
	 * @param int $user_id The WordPress user ID
	 * @return string Vendor code or empty string
	 */
	public function get_vendor_code( int $user_id ): string {
		return (string) $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_VENDOR_CODE, '' );
	}

	/**
	 * Get vendor name for user
	 *
	 * @param int $user_id The WordPress user ID
	 * @return string Vendor name or user display name as fallback
	 */
	public function get_vendor_name( int $user_id ): string {
		$vendor_name = (string) $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_VENDOR_NAME, '' );

		if ( '' === $vendor_name && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'ID', $user_id );
			if ( $user && isset( $user->display_name ) ) {
				$vendor_name = (string) $user->display_name;
			}
		}

		return $vendor_name;
	}

	/**
	 * Get commission rate for user
	 *
	 * @param int $user_id The WordPress user ID
	 * @return float Commission rate (0.15 = 15%)
	 */
	public function get_commission_rate( int $user_id ): float {
		return (float) $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_COMMISSION_RATE, 0.0 );
	}

	/**
	 * Get vendor status
	 *
	 * @param int $user_id The WordPress user ID
	 * @return string Status (active, inactive, archived)
	 */
	public function get_vendor_status( int $user_id ): string {
		return (string) $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_VENDOR_STATUS, 'active' );
	}

	/**
	 * Get Square location ID for vendor
	 *
	 * @param int $user_id The WordPress user ID
	 * @return string Square location ID or empty string
	 */
	public function get_square_location_id( int $user_id ): string {
		return (string) $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_SQUARE_LOCATION_ID, '' );
	}

	/**
	 * Find user by Square location ID
	 *
	 * @param string $square_location_id The Square location ID
	 * @return int|null User ID if found, null otherwise
	 */
	public function find_user_by_square_location( string $square_location_id ): ?int {
		if ( '' === $square_location_id ) {
			return null;
		}

		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} 
				 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				AIMS_Vendor_Metadata::META_SQUARE_LOCATION_ID,
				$square_location_id
			)
		);

		return ! empty( $user_id ) ? (int) $user_id : null;
	}

	/**
	 * Get all users with vendor status
	 *
	 * @param string $status Vendor status filter (active, inactive, archived), or empty for all
	 * @return array Array of user IDs
	 */
	public function get_all_vendors( string $status = '' ): array {
		global $wpdb;

		$query = "SELECT user_id FROM {$wpdb->usermeta} 
				  WHERE meta_key = %s AND meta_value = 1";
		$params = array( AIMS_Vendor_Metadata::META_IS_VENDOR );

		if ( '' !== $status ) {
			$query .= " AND user_id IN (
				SELECT user_id FROM {$wpdb->usermeta} 
				WHERE meta_key = %s AND meta_value = %s
			)";
			$params[] = AIMS_Vendor_Metadata::META_VENDOR_STATUS;
			$params[] = $status;
		}

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, ...$params ),
			ARRAY_A
		);

		$user_ids = array();
		foreach ( (array) $results as $row ) {
			$user_id = (int) ( $row['user_id'] ?? 0 );
			if ( $user_id > 0 ) {
				$user_ids[] = $user_id;
			}
		}

		return array_unique( $user_ids );
	}

	/**
	 * Get address as array for a vendor user
	 *
	 * @param int $user_id The WordPress user ID
	 * @return array Address components
	 */
	public function get_vendor_address( int $user_id ): array {
		return array(
			'line_1'       => $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_ADDRESS_LINE_1, '' ),
			'line_2'       => $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_ADDRESS_LINE_2, '' ),
			'city'         => $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_CITY, '' ),
			'state_region' => $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_STATE_REGION, '' ),
			'postal_code'  => $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_POSTAL_CODE, '' ),
			'country_code' => $this->get_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_COUNTRY_CODE, 'US' ),
		);
	}

	/**
	 * Update address for a vendor user
	 *
	 * @param int   $user_id The WordPress user ID
	 * @param array $address Address components
	 * @return bool True if successfully updated
	 */
	public function update_vendor_address( int $user_id, array $address ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$updated = true;

		if ( isset( $address['line_1'] ) ) {
			$updated &= $this->update_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_ADDRESS_LINE_1, $address['line_1'] );
		}
		if ( isset( $address['line_2'] ) ) {
			$updated &= $this->update_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_ADDRESS_LINE_2, $address['line_2'] );
		}
		if ( isset( $address['city'] ) ) {
			$updated &= $this->update_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_CITY, $address['city'] );
		}
		if ( isset( $address['state_region'] ) ) {
			$updated &= $this->update_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_STATE_REGION, $address['state_region'] );
		}
		if ( isset( $address['postal_code'] ) ) {
			$updated &= $this->update_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_POSTAL_CODE, $address['postal_code'] );
		}
		if ( isset( $address['country_code'] ) ) {
			$updated &= $this->update_vendor_meta( $user_id, AIMS_Vendor_Metadata::META_COUNTRY_CODE, $address['country_code'] );
		}

		return (bool) $updated;
	}
}
