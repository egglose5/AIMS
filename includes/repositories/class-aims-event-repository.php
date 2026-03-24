<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_events';
	}

	public function all(): array {
		global $wpdb;

		return $wpdb->get_results(
			'SELECT * FROM ' . $this->get_table_name() . ' ORDER BY start_date DESC, id DESC',
			ARRAY_A
		);
	}

	public function find( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$event_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_matching_event( string $square_location_id, string $sold_at ): ?array {
		global $wpdb;

		$square_location_id = sanitize_text_field( $square_location_id );
		$sold_at            = sanitize_text_field( $sold_at );

		if ( '' === $square_location_id || '' === $sold_at ) {
			return null;
		}

		$sold_date = $this->normalize_date( $sold_at );

		$event = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_location_id = %s AND start_date <= %s AND end_date >= %s ORDER BY start_date DESC, id DESC LIMIT 1',
				$square_location_id,
				$sold_date,
				$sold_date
			),
			ARRAY_A
		);

		return is_array( $event ) ? $event : null;
	}

	private function normalize_date( string $value ): string {
		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d', $time ) : sanitize_text_field( $value );
	}

	public function save( array $data, int $event_id = 0 ): int {
		global $wpdb;

		$record = $this->build_base_record( $data );

		if ( $event_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $event_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $event_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function update_financials( int $event_id, array $financials ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'gross_sales_total'   => number_format( (float) ( $financials['gross_sales_total'] ?? 0 ), 2, '.', '' ),
				'discount_total'      => number_format( (float) ( $financials['discount_total'] ?? 0 ), 2, '.', '' ),
				'tip_total'           => number_format( (float) ( $financials['tip_total'] ?? 0 ), 2, '.', '' ),
				'net_sales_total'     => number_format( (float) ( $financials['net_sales_total'] ?? 0 ), 2, '.', '' ),
				'vendor_payout_total' => number_format( (float) ( $financials['vendor_payout_total'] ?? 0 ), 2, '.', '' ),
				'expense_total'       => number_format( (float) ( $financials['expense_total'] ?? 0 ), 2, '.', '' ),
				'profit_total'        => number_format( (float) ( $financials['profit_total'] ?? 0 ), 2, '.', '' ),
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $event_id ),
			array( '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	private function build_base_record( array $data ): array {
		return array(
			'event_code'         => sanitize_key( $data['event_code'] ?? '' ),
			'event_name'         => sanitize_text_field( $data['event_name'] ?? '' ),
			'status'             => sanitize_key( $data['status'] ?? 'draft' ),
			'start_date'         => sanitize_text_field( $data['start_date'] ?? '' ),
			'end_date'           => sanitize_text_field( $data['end_date'] ?? '' ),
			'location_name'      => sanitize_text_field( $data['location_name'] ?? '' ),
			'square_location_id' => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'gross_sales_total'  => number_format( (float) ( $data['gross_sales_total'] ?? 0 ), 2, '.', '' ),
			'discount_total'     => number_format( (float) ( $data['discount_total'] ?? 0 ), 2, '.', '' ),
			'tip_total'          => number_format( (float) ( $data['tip_total'] ?? 0 ), 2, '.', '' ),
			'net_sales_total'    => number_format( (float) ( $data['net_sales_total'] ?? 0 ), 2, '.', '' ),
			'vendor_payout_total'=> number_format( (float) ( $data['vendor_payout_total'] ?? 0 ), 2, '.', '' ),
			'expense_total'      => number_format( (float) ( $data['expense_total'] ?? 0 ), 2, '.', '' ),
			'profit_total'       => number_format( (float) ( $data['profit_total'] ?? 0 ), 2, '.', '' ),
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);
	}
}
