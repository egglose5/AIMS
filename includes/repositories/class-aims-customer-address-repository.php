<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Customer_Address_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_customer_addresses';
	}

	public function save( array $data, int $address_id = 0 ): int {
		global $wpdb;

		$record = array(
			'customer_id'       => (int) ( $data['customer_id'] ?? 0 ),
			'square_address_id' => sanitize_text_field( $data['square_address_id'] ?? '' ),
			'address_type'      => sanitize_key( $data['address_type'] ?? 'shipping' ),
			'is_primary'        => ! empty( $data['is_primary'] ) ? 1 : 0,
			'address_line_1'    => sanitize_text_field( $data['address_line_1'] ?? '' ),
			'address_line_2'    => sanitize_text_field( $data['address_line_2'] ?? '' ),
			'city'              => sanitize_text_field( $data['city'] ?? '' ),
			'state_region'      => sanitize_text_field( $data['state_region'] ?? '' ),
			'postal_code'       => sanitize_text_field( $data['postal_code'] ?? '' ),
			'country_code'      => strtoupper( sanitize_text_field( $data['country_code'] ?? 'US' ) ),
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( $address_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $address_id ),
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $address_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function find_by_square_address_id( string $square_address_id ): ?array {
		global $wpdb;

		if ( '' === trim( $square_address_id ) ) {
			return null;
		}

		$address = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_address_id = %s',
				$square_address_id
			),
			ARRAY_A
		);

		return is_array( $address ) ? $address : null;
	}
}
