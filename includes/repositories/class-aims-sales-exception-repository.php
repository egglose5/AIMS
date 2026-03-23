<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Sales_Exception_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_sales_exceptions';
	}

	public function save( array $data, int $exception_id = 0 ): int {
		global $wpdb;

		$record = array(
			'normalized_sale_id' => (int) ( $data['normalized_sale_id'] ?? 0 ),
			'exception_type'     => sanitize_key( $data['exception_type'] ?? '' ),
			'severity'           => sanitize_key( $data['severity'] ?? 'medium' ),
			'message'            => sanitize_textarea_field( $data['message'] ?? '' ),
			'resolution_status'  => sanitize_key( $data['resolution_status'] ?? 'open' ),
			'resolved_by'        => (int) ( $data['resolved_by'] ?? 0 ),
			'resolved_at'        => $this->normalize_datetime( $data['resolved_at'] ?? null ),
			'resolution_notes'   => sanitize_textarea_field( $data['resolution_notes'] ?? '' ),
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $exception_id <= 0 && ! empty( $record['normalized_sale_id'] ) ) {
			$existing = $this->find_by_normalized_sale_id( $record['normalized_sale_id'] );
			if ( ! empty( $existing['id'] ) ) {
				$exception_id = (int) $existing['id'];
			}
		}

		if ( $exception_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $exception_id ) );
			return $exception_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find_by_normalized_sale_id( int $normalized_sale_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE normalized_sale_id = %d ORDER BY id DESC LIMIT 1',
				$normalized_sale_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_open_exceptions( int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE resolution_status = %s ORDER BY severity DESC, created_at ASC, id ASC LIMIT %d',
				'open',
				$limit
			),
			ARRAY_A
		);
	}

	public function get_for_status( string $status, int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE resolution_status = %s ORDER BY created_at ASC, id ASC LIMIT %d',
				sanitize_key( $status ),
				$limit
			),
			ARRAY_A
		);
	}

	public function update_resolution( int $exception_id, array $data ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'resolution_status' => sanitize_key( $data['resolution_status'] ?? 'open' ),
				'resolved_by'       => (int) ( $data['resolved_by'] ?? 0 ),
				'resolved_at'       => $this->normalize_datetime( $data['resolved_at'] ?? current_time( 'mysql' ) ),
				'resolution_notes'  => sanitize_textarea_field( $data['resolution_notes'] ?? '' ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $exception_id )
		);

		return false !== $updated;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
