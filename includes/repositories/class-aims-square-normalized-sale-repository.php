<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Normalized_Sale_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_square_normalized_sales';
	}

	public function save( array $data, int $sale_id = 0 ): int {
		global $wpdb;

		$record = array(
			'square_order_id'       => sanitize_text_field( $data['square_order_id'] ?? '' ),
			'square_line_item_uid'  => sanitize_text_field( $data['square_line_item_uid'] ?? '' ),
			'square_payment_id'     => sanitize_text_field( $data['square_payment_id'] ?? '' ),
			'square_location_id'    => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'square_team_member_id' => sanitize_text_field( $data['square_team_member_id'] ?? '' ),
			'occurred_at'           => $this->normalize_datetime( $data['occurred_at'] ?? current_time( 'mysql' ) ),
			'gross_sales'           => number_format( (float) ( $data['gross_sales'] ?? 0 ), 2, '.', '' ),
			'tax_amount'            => number_format( (float) ( $data['tax_amount'] ?? 0 ), 2, '.', '' ),
			'discount_amount'       => number_format( (float) ( $data['discount_amount'] ?? 0 ), 2, '.', '' ),
			'tip_amount'            => number_format( (float) ( $data['tip_amount'] ?? 0 ), 2, '.', '' ),
			'refund_amount'         => number_format( (float) ( $data['refund_amount'] ?? 0 ), 2, '.', '' ),
			'net_sales'             => number_format( (float) ( $data['net_sales'] ?? 0 ), 2, '.', '' ),
			'currency'              => strtoupper( sanitize_text_field( $data['currency'] ?? 'USD' ) ),
			'source_raw_event_id'   => (int) ( $data['source_raw_event_id'] ?? 0 ),
			'source_queue_id'       => (int) ( $data['source_queue_id'] ?? 0 ),
			'normalization_status'  => sanitize_key( $data['normalization_status'] ?? 'pending' ),
			'payload_json'          => $this->encode_payload( $data['payload_json'] ?? ( $data['payload'] ?? null ) ),
			'updated_at'            => current_time( 'mysql' ),
		);

		if ( $sale_id <= 0 ) {
			$existing = $this->find_by_order_line( $record['square_order_id'], $record['square_line_item_uid'] );
			if ( ! empty( $existing['id'] ) ) {
				$sale_id = (int) $existing['id'];
			}
		}

		if ( $sale_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $sale_id ) );
			return $sale_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find_by_order_line( string $square_order_id, string $square_line_item_uid ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_order_id = %s AND square_line_item_uid = %s',
				sanitize_text_field( $square_order_id ),
				sanitize_text_field( $square_line_item_uid )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_raw_event( int $raw_event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE source_raw_event_id = %d ORDER BY occurred_at ASC, id ASC',
				$raw_event_id
			),
			ARRAY_A
		);
	}

	public function get_for_location_and_date( string $square_location_id, string $occurred_at ): array {
		global $wpdb;

		$occurred_date = $this->normalize_date( $occurred_at );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_location_id = %s AND DATE(occurred_at) = %s ORDER BY occurred_at ASC, id ASC',
				sanitize_text_field( $square_location_id ),
				$occurred_date
			),
			ARRAY_A
		);
	}

	public function get_for_status( string $status, int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE normalization_status = %s ORDER BY occurred_at ASC, id ASC LIMIT %d',
				sanitize_key( $status ),
				$limit
			),
			ARRAY_A
		);
	}

	public function update_status( int $sale_id, string $status ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'normalization_status' => sanitize_key( $status ),
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $sale_id )
		);

		return false !== $updated;
	}

	private function normalize_datetime( $value ): string {
		if ( is_array( $value ) ) {
			return current_time( 'mysql' );
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : current_time( 'mysql' );
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

	private function normalize_date( string $value ): string {
		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d', $time ) : sanitize_text_field( $value );
	}
}
