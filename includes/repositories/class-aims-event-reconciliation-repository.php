<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Reconciliation_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_reconciliations';
	}

	public function save( array $data, int $reconciliation_id = 0 ): int {
		global $wpdb;

		$record = array(
			'event_id'               => (int) ( $data['event_id'] ?? 0 ),
			'reconciliation_type'    => AIMS_Event_Reconciliation_Types::normalize_snapshot_type( (string) ( $data['reconciliation_type'] ?? AIMS_Event_Reconciliation_Types::SNAPSHOT_PLANNED ) ),
			'snapshot_date'          => $this->normalize_datetime( $data['snapshot_date'] ?? current_time( 'mysql' ) ),
			'planned_inventory_qty'  => number_format( (float) ( $data['planned_inventory_qty'] ?? 0 ), 4, '.', '' ),
			'actual_inventory_qty'   => number_format( (float) ( $data['actual_inventory_qty'] ?? 0 ), 4, '.', '' ),
			'expected_sales_total'   => number_format( (float) ( $data['expected_sales_total'] ?? 0 ), 2, '.', '' ),
			'actual_sales_total'     => number_format( (float) ( $data['actual_sales_total'] ?? 0 ), 2, '.', '' ),
			'expected_expense_total' => number_format( (float) ( $data['expected_expense_total'] ?? 0 ), 2, '.', '' ),
			'actual_expense_total'   => number_format( (float) ( $data['actual_expense_total'] ?? 0 ), 2, '.', '' ),
			'discrepancy_status'     => AIMS_Event_Reconciliation_Types::normalize_status( (string) ( $data['discrepancy_status'] ?? AIMS_Event_Reconciliation_Types::STATUS_PENDING ) ),
			'discrepancy_count'      => max( 0, (int) ( $data['discrepancy_count'] ?? 0 ) ),
			'notes'                  => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'reconciled_by'          => empty( $data['reconciled_by'] ) ? null : (int) $data['reconciled_by'],
			'reconciled_at'          => empty( $data['reconciled_at'] ) ? null : $this->normalize_datetime( $data['reconciled_at'] ),
			'updated_at'             => current_time( 'mysql' ),
		);

		if ( $reconciliation_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $reconciliation_id ) );

			return $reconciliation_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $reconciliation_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $reconciliation_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY snapshot_date DESC, id DESC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_for_event_type( int $event_id, string $reconciliation_type ): array {
		global $wpdb;

		$reconciliation_type = AIMS_Event_Reconciliation_Types::normalize_snapshot_type( $reconciliation_type );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND reconciliation_type = %s ORDER BY snapshot_date DESC, id DESC',
				$event_id,
				$reconciliation_type
			),
			ARRAY_A
		);
	}

	public function get_latest_for_event_type( int $event_id, string $reconciliation_type ): ?array {
		$rows = $this->get_for_event_type( $event_id, $reconciliation_type );

		return ! empty( $rows ) ? $rows[0] : null;
	}

	public function mark_reconciled( int $reconciliation_id, int $user_id, string $notes = '' ): bool {
		global $wpdb;

		$current = $this->find( $reconciliation_id );
		if ( null === $current ) {
			return false;
		}

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'discrepancy_status' => AIMS_Event_Reconciliation_Types::STATUS_RECONCILED,
				'reconciled_by'      => max( 0, $user_id ),
				'reconciled_at'      => current_time( 'mysql' ),
				'notes'              => '' !== trim( $notes ) ? wp_kses_post( $notes ) : (string) ( $current['notes'] ?? '' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $reconciliation_id )
		);

		return false !== $updated;
	}

	private function normalize_datetime( $value ): string {
		if ( empty( $value ) ) {
			return current_time( 'mysql' );
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
