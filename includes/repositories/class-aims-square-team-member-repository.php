<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Team_Member_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_square_team_members';
	}

	public function save( array $data, int $member_id = 0 ): int {
		global $wpdb;

		$record = array(
			'square_team_member_id' => sanitize_text_field( $data['square_team_member_id'] ?? '' ),
			'display_name'          => sanitize_text_field( $data['display_name'] ?? '' ),
			'given_name'            => sanitize_text_field( $data['given_name'] ?? '' ),
			'family_name'           => sanitize_text_field( $data['family_name'] ?? '' ),
			'email_address'         => sanitize_email( $data['email_address'] ?? '' ),
			'phone_number'          => sanitize_text_field( $data['phone_number'] ?? '' ),
			'status'                => sanitize_key( $data['status'] ?? 'active' ),
			'raw_payload_json'      => $this->encode_payload( $data['raw_payload_json'] ?? ( $data['raw_payload'] ?? null ) ),
			'last_synced_at'        => $this->normalize_datetime( $data['last_synced_at'] ?? null ),
			'updated_at'            => current_time( 'mysql' ),
		);

		if ( $member_id <= 0 && '' !== $record['square_team_member_id'] ) {
			$existing = $this->find_by_square_team_member_id( $record['square_team_member_id'] );
			if ( ! empty( $existing['id'] ) ) {
				$member_id = (int) $existing['id'];
			}
		}

		if ( $member_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $member_id ) );
			return $member_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function all(): array {
		global $wpdb;

		return $wpdb->get_results(
			'SELECT * FROM ' . $this->get_table_name() . ' ORDER BY display_name ASC, id ASC',
			ARRAY_A
		);
	}

	public function find_by_square_team_member_id( string $square_team_member_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_team_member_id = %s',
				sanitize_text_field( $square_team_member_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_email_address( string $email_address ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE email_address = %s',
				sanitize_email( $email_address )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_phone_number( string $phone_number ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE phone_number = %s',
				sanitize_text_field( $phone_number )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_active(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE status = %s ORDER BY display_name ASC, id ASC',
				'active'
			),
			ARRAY_A
		);
	}

	private function encode_payload( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		return wp_json_encode( $value );
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
