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

	public function find_by_id( int $event_id ): ?array {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return null;
		}

		$event = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$event_id
			),
			ARRAY_A
		);

		return is_array( $event ) ? $event : null;
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
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s' ),
				array( '%d' )
			);

			return $event_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s' )
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

	public function update_commission_policy( int $event_id, array $policy ): bool {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return false;
		}

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'commission_cap_rate'     => number_format( $this->normalize_commission_cap_rate( $policy['commission_cap_rate'] ?? 30 ), 4, '.', '' ),
				'commission_split_policy' => $this->normalize_commission_split_policy( (string) ( $policy['commission_split_policy'] ?? 'proportional' ) ),
				'updated_at'              => current_time( 'mysql' ),
			),
			array( 'id' => $event_id ),
			array( '%f', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function get_commission_policy_for_event( int $event_id ): array {
		$event = $this->find_by_id( $event_id );

		if ( empty( $event ) ) {
			return array(
				'commission_cap_rate'      => 30.0,
				'commission_split_policy'  => 'proportional',
			);
		}

		return array(
			'commission_cap_rate'      => $this->normalize_commission_cap_rate( $event['commission_cap_rate'] ?? 30 ),
			'commission_split_policy'  => $this->normalize_commission_split_policy( (string) ( $event['commission_split_policy'] ?? 'proportional' ) ),
		);
	}

	public function get_financial_context( int $event_id ): array {
		$event = $this->find_by_id( $event_id );

		if ( empty( $event ) ) {
			return array(
				'event_id'                => $event_id,
				'event_name'              => '',
				'commission_cap_rate'     => 30.0,
				'commission_split_policy' => 'proportional',
			);
		}

		$policy = $this->get_commission_policy_for_event( $event_id );

		return array(
			'event_id'                => (int) $event['id'],
			'event_name'              => ! empty( $event['event_name'] ) ? (string) $event['event_name'] : '',
			'status'                  => ! empty( $event['status'] ) ? (string) $event['status'] : 'draft',
			'participation_status'    => ! empty( $event['participation_status'] ) ? (string) $event['participation_status'] : 'draft',
			'start_date'              => ! empty( $event['start_date'] ) ? (string) $event['start_date'] : '',
			'end_date'                => ! empty( $event['end_date'] ) ? (string) $event['end_date'] : '',
			'square_location_id'      => ! empty( $event['square_location_id'] ) ? (string) $event['square_location_id'] : '',
			'commission_cap_rate'     => (float) $policy['commission_cap_rate'],
			'commission_split_policy' => (string) $policy['commission_split_policy'],
			'commission_policy'       => $policy,
			'gross_sales_total'       => (float) ( $event['gross_sales_total'] ?? 0 ),
			'discount_total'          => (float) ( $event['discount_total'] ?? 0 ),
			'tip_total'               => (float) ( $event['tip_total'] ?? 0 ),
			'net_sales_total'         => (float) ( $event['net_sales_total'] ?? 0 ),
			'vendor_payout_total'     => (float) ( $event['vendor_payout_total'] ?? 0 ),
			'expense_total'           => (float) ( $event['expense_total'] ?? 0 ),
			'profit_total'            => (float) ( $event['profit_total'] ?? 0 ),
		);
	}

	private function build_base_record( array $data ): array {
		return array(
			'event_code'              => sanitize_key( $data['event_code'] ?? '' ),
			'event_name'              => sanitize_text_field( $data['event_name'] ?? '' ),
			'status'                  => sanitize_key( $data['status'] ?? 'draft' ),
			'participation_status'    => sanitize_key( $data['participation_status'] ?? 'draft' ),
			'start_date'              => sanitize_text_field( $data['start_date'] ?? '' ),
			'end_date'                => sanitize_text_field( $data['end_date'] ?? '' ),
			'location_name'           => sanitize_text_field( $data['location_name'] ?? '' ),
			'square_location_id'      => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'vendor_capacity'         => (int) ( $data['vendor_capacity'] ?? 0 ),
			'vendor_request_limit'    => (int) ( $data['vendor_request_limit'] ?? 0 ),
			'vendor_request_count'    => (int) ( $data['vendor_request_count'] ?? 0 ),
			'commission_cap_rate'     => number_format( (float) ( $data['commission_cap_rate'] ?? 30 ), 4, '.', '' ),
			'commission_split_policy' => $this->normalize_commission_split_policy( (string) ( $data['commission_split_policy'] ?? 'proportional' ) ),
			'gross_sales_total'       => number_format( (float) ( $data['gross_sales_total'] ?? 0 ), 2, '.', '' ),
			'discount_total'          => number_format( (float) ( $data['discount_total'] ?? 0 ), 2, '.', '' ),
			'tip_total'               => number_format( (float) ( $data['tip_total'] ?? 0 ), 2, '.', '' ),
			'net_sales_total'         => number_format( (float) ( $data['net_sales_total'] ?? 0 ), 2, '.', '' ),
			'vendor_payout_total'     => number_format( (float) ( $data['vendor_payout_total'] ?? 0 ), 2, '.', '' ),
			'expense_total'           => number_format( (float) ( $data['expense_total'] ?? 0 ), 2, '.', '' ),
			'profit_total'            => number_format( (float) ( $data['profit_total'] ?? 0 ), 2, '.', '' ),
			'notes'                   => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'              => current_time( 'mysql' ),
		);
	}

	private function normalize_commission_split_policy( string $policy ): string {
		$policy = sanitize_key( $policy );

		return in_array(
			$policy,
			array( 'proportional', 'equal' ),
			true
		) ? $policy : 'proportional';
	}

	private function normalize_commission_cap_rate( $rate ): float {
		$rate = (float) $rate;

		if ( $rate < 0 ) {
			$rate = 0.0;
		}

		if ( $rate > 100 ) {
			$rate = 100.0;
		}

		return $rate;
	}
}
