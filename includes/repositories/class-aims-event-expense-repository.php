<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Expense_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_expenses';
	}

	public function all_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY incurred_at ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function save( array $data, int $expense_id = 0 ): int {
		global $wpdb;

		$record = array(
			'event_id'     => (int) ( $data['event_id'] ?? 0 ),
			'vendor_id'    => (int) ( $data['vendor_id'] ?? 0 ),
			'expense_type' => sanitize_key( $data['expense_type'] ?? 'other' ),
			'amount'       => number_format( (float) ( $data['amount'] ?? 0 ), 2, '.', '' ),
			'note'         => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'incurred_at'  => $data['incurred_at'] ?? null,
			'updated_at'   => current_time( 'mysql' ),
		);

		if ( $expense_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $expense_id ),
				array( '%d', '%d', '%s', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $expense_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function get_total_for_event( int $event_id ): float {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . $this->get_table_name() . ' WHERE event_id = %d',
				$event_id
			)
		);

		return (float) $total;
	}
}
