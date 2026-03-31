<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor Metadata Constants and Schema
 *
 * Defines WordPress user meta keys and structure for vendor attributes.
 * Vendors are subtypes of Person (WordPress users) with vendor-specific properties.
 */
class AIMS_Vendor_Metadata {

	// Meta key prefix for all vendor attributes
	private const META_PREFIX = 'aims_vendor_';

	// Vendor status/existence indicator
	public const META_IS_VENDOR = 'aims_is_vendor';

	// Business identifiers
	public const META_VENDOR_CODE = 'aims_vendor_code';
	public const META_VENDOR_NAME = 'aims_vendor_name';

	// Financial attributes
	public const META_COMMISSION_RATE = 'aims_commission_rate';

	// Contact information
	public const META_PHONE_NUMBER = 'aims_phone_number';
	public const META_EMAIL_ADDRESS = 'aims_email_address';

	// Address fields
	public const META_ADDRESS_LINE_1 = 'aims_address_line_1';
	public const META_ADDRESS_LINE_2 = 'aims_address_line_2';
	public const META_CITY = 'aims_city';
	public const META_STATE_REGION = 'aims_state_region';
	public const META_POSTAL_CODE = 'aims_postal_code';
	public const META_COUNTRY_CODE = 'aims_country_code';

	// Square integration attributes
	public const META_SQUARE_LOCATION_ID = 'aims_square_location_id';
	public const META_SQUARE_TEAM_MEMBER_ID = 'aims_square_team_member_id';

	// Inventory defaults
	public const META_DEFAULT_BUCKET_ID = 'aims_default_bucket_id';
	public const META_DEFAULT_BUCKET_CODE = 'aims_default_bucket_code';

	// Notes/metadata
	public const META_VENDOR_NOTES = 'aims_vendor_notes';
	public const META_VENDOR_STATUS = 'aims_vendor_status';

	/**
	 * Get all vendor meta keys
	 *
	 * @return array List of all vendor meta key constants
	 */
	public static function get_all_meta_keys(): array {
		return array(
			self::META_IS_VENDOR,
			self::META_VENDOR_CODE,
			self::META_VENDOR_NAME,
			self::META_COMMISSION_RATE,
			self::META_PHONE_NUMBER,
			self::META_EMAIL_ADDRESS,
			self::META_ADDRESS_LINE_1,
			self::META_ADDRESS_LINE_2,
			self::META_CITY,
			self::META_STATE_REGION,
			self::META_POSTAL_CODE,
			self::META_COUNTRY_CODE,
			self::META_SQUARE_LOCATION_ID,
			self::META_SQUARE_TEAM_MEMBER_ID,
			self::META_DEFAULT_BUCKET_ID,
			self::META_DEFAULT_BUCKET_CODE,
			self::META_VENDOR_NOTES,
			self::META_VENDOR_STATUS,
		);
	}

	/**
	 * Get schema for vendor metadata
	 *
	 * @return array Schema describing vendor meta structure
	 */
	public static function get_schema(): array {
		return array(
			'is_vendor'              => array(
				'type'        => 'boolean',
				'description' => 'Whether this user is marked as a vendor',
				'meta_key'    => self::META_IS_VENDOR,
			),
			'vendor_code'            => array(
				'type'        => 'string',
				'description' => 'Unique vendor code/identifier',
				'meta_key'    => self::META_VENDOR_CODE,
			),
			'vendor_name'            => array(
				'type'        => 'string',
				'description' => 'Vendor business name',
				'meta_key'    => self::META_VENDOR_NAME,
			),
			'commission_rate'        => array(
				'type'        => 'number',
				'description' => 'Commission rate as decimal (e.g., 0.15 for 15%)',
				'meta_key'    => self::META_COMMISSION_RATE,
			),
			'phone_number'           => array(
				'type'        => 'string',
				'description' => 'Vendor contact phone number',
				'meta_key'    => self::META_PHONE_NUMBER,
			),
			'email_address'          => array(
				'type'        => 'string',
				'description' => 'Vendor contact email (alternate to user email)',
				'meta_key'    => self::META_EMAIL_ADDRESS,
			),
			'address'                => array(
				'type'        => 'object',
				'description' => 'Vendor address',
				'properties'  => array(
					'line_1'       => array(
						'type'     => 'string',
						'meta_key' => self::META_ADDRESS_LINE_1,
					),
					'line_2'       => array(
						'type'     => 'string',
						'meta_key' => self::META_ADDRESS_LINE_2,
					),
					'city'         => array(
						'type'     => 'string',
						'meta_key' => self::META_CITY,
					),
					'state_region' => array(
						'type'     => 'string',
						'meta_key' => self::META_STATE_REGION,
					),
					'postal_code'  => array(
						'type'     => 'string',
						'meta_key' => self::META_POSTAL_CODE,
					),
					'country_code' => array(
						'type'     => 'string',
						'meta_key' => self::META_COUNTRY_CODE,
					),
				),
			),
			'square_integration'     => array(
				'type'        => 'object',
				'description' => 'Square account integration details',
				'properties'  => array(
					'location_id'     => array(
						'type'     => 'string',
						'meta_key' => self::META_SQUARE_LOCATION_ID,
					),
					'team_member_id'  => array(
						'type'     => 'string',
						'meta_key' => self::META_SQUARE_TEAM_MEMBER_ID,
					),
				),
			),
			'inventory_defaults'     => array(
				'type'        => 'object',
				'description' => 'Default inventory bucket for vendor',
				'properties'  => array(
					'bucket_id'   => array(
						'type'     => 'integer',
						'meta_key' => self::META_DEFAULT_BUCKET_ID,
					),
					'bucket_code' => array(
						'type'     => 'string',
						'meta_key' => self::META_DEFAULT_BUCKET_CODE,
					),
				),
			),
			'status'                 => array(
				'type'        => 'string',
				'description' => 'Vendor status (active, inactive, archived)',
				'meta_key'    => self::META_VENDOR_STATUS,
			),
			'notes'                  => array(
				'type'        => 'string',
				'description' => 'Internal vendor notes',
				'meta_key'    => self::META_VENDOR_NOTES,
			),
		);
	}

	/**
	 * Check if a value should be numeric (for schema validation)
	 *
	 * @param string $meta_key The meta key to check
	 * @return bool True if this meta key should be stored as numeric
	 */
	public static function should_be_numeric( string $meta_key ): bool {
		return in_array(
			$meta_key,
			array(
				self::META_COMMISSION_RATE,
				self::META_DEFAULT_BUCKET_ID,
			),
			true
		);
	}

	/**
	 * Check if a value should be boolean (for schema validation)
	 *
	 * @param string $meta_key The meta key to check
	 * @return bool True if this meta key should be stored as boolean
	 */
	public static function should_be_boolean( string $meta_key ): bool {
		return self::META_IS_VENDOR === $meta_key;
	}

	/**
	 * Get default value for a meta key
	 *
	 * @param string $meta_key The meta key
	 * @return mixed Default value, or null if no default
	 */
	public static function get_default( string $meta_key ) {
		switch ( $meta_key ) {
			case self::META_IS_VENDOR:
				return false;
			case self::META_COMMISSION_RATE:
				return 0.0;
			case self::META_COUNTRY_CODE:
				return 'US';
			case self::META_VENDOR_STATUS:
				return 'active';
			default:
				return null;
		}
	}
}
