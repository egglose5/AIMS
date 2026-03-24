<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendors';
	}

	public function all(): array {
		global $wpdb;

		return $wpdb->get_results(
			'SELECT * FROM ' . $this->get_table_name() . ' ORDER BY vendor_name ASC, id ASC',
			ARRAY_A
		);
	}

	public function find( int $vendor_id ): ?array {
		global $wpdb;

		$vendor = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$vendor_id
			),
			ARRAY_A
		);

		return is_array( $vendor ) ? $vendor : null;
	}

	public function find_by_square_location_id( string $square_location_id ): ?array {
		global $wpdb;

		$vendor = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_location_id = %s',
				$square_location_id
			),
			ARRAY_A
		);

		return is_array( $vendor ) ? $vendor : null;
	}

	public function save( array $data, int $vendor_id = 0 ): int {
		global $wpdb;

		$vendor_code = sanitize_key( $data['vendor_code'] ?? '' );
		if ( '' === $vendor_code ) {
			$vendor_code = sanitize_key( $data['vendor_name'] ?? '' );
		}

		$record = array(
			'vendor_code'         => $vendor_code,
			'vendor_name'         => sanitize_text_field( $data['vendor_name'] ?? '' ),
			'status'              => sanitize_key( $data['status'] ?? 'active' ),
			'square_location_id'  => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'square_team_member_id' => sanitize_text_field( $data['square_team_member_id'] ?? '' ),
			'default_bucket_id'   => ! empty( $data['default_bucket_id'] ) ? (int) $data['default_bucket_id'] : null,
			'default_bucket_code' => sanitize_text_field( $data['default_bucket_code'] ?? '' ),
			'commission_rate'     => $this->normalize_rate( $data['commission_rate'] ?? 0 ),
			'phone_number'        => sanitize_text_field( $data['phone_number'] ?? '' ),
			'email_address'       => sanitize_email( $data['email_address'] ?? '' ),
			'address_line_1'      => sanitize_text_field( $data['address_line_1'] ?? '' ),
			'address_line_2'      => sanitize_text_field( $data['address_line_2'] ?? '' ),
			'city'                => sanitize_text_field( $data['city'] ?? '' ),
			'state_region'        => sanitize_text_field( $data['state_region'] ?? '' ),
			'postal_code'         => sanitize_text_field( $data['postal_code'] ?? '' ),
			'country_code'        => strtoupper( sanitize_text_field( $data['country_code'] ?? 'US' ) ),
			'notes'               => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'          => current_time( 'mysql' ),
		);

		if ( $vendor_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $vendor_id ),
				$this->get_formats(),
				array( '%d' )
			);

			return $vendor_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array_merge( $this->get_formats(), array( '%s' ) )
		);

		return (int) $wpdb->insert_id;
	}

	public function archive( int $vendor_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'status'     => 'archived',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $vendor_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	private function get_formats(): array {
		return array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%f',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);
	}

	private function normalize_rate( $value ): string {
		return number_format( (float) $value, 4, '.', '' );
	}
}

