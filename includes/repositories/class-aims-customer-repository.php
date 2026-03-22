<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Customer_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_customers';
	}

	public function find_by_square_customer_id( string $square_customer_id ): ?array {
		global $wpdb;

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_customer_id = %s',
				$square_customer_id
			),
			ARRAY_A
		);

		return is_array( $customer ) ? $customer : null;
	}

	public function save( array $data, int $customer_id = 0 ): int {
		global $wpdb;

		$record = array(
			'square_customer_id' => sanitize_text_field( $data['square_customer_id'] ?? '' ),
			'first_name'         => sanitize_text_field( $data['first_name'] ?? '' ),
			'last_name'          => sanitize_text_field( $data['last_name'] ?? '' ),
			'company_name'       => sanitize_text_field( $data['company_name'] ?? '' ),
			'email_address'      => sanitize_email( $data['email_address'] ?? '' ),
			'phone_number'       => sanitize_text_field( $data['phone_number'] ?? '' ),
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $customer_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $customer_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $customer_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}
}

