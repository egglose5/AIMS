<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Reconciliation_Discrepancy_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_reconciliation_discrepancies';
	}

	public function save( array $data, int $discrepancy_id = 0 ): int {
		global $wpdb;

		$expected_value = (float) ( $data['expected_value'] ?? 0 );
		$actual_value   = (float) ( $data['actual_value'] ?? 0 );
		$variance       = array_key_exists( 'variance_amount', $data ) ? (float) $data['variance_amount'] : ( $actual_value - $expected_value );
		$percent        = array_key_exists( 'variance_percent', $data ) ? (float) $data['variance_percent'] : $this->calculate_percent( $expected_value, $variance );

		$record = array(
			'reconciliation_id' => (int) ( $data['reconciliation_id'] ?? 0 ),
			'event_id'          => (int) ( $data['event_id'] ?? 0 ),
			'discrepancy_type'  => AIMS_Event_Reconciliation_Types::normalize_discrepancy_type( (string) ( $data['discrepancy_type'] ?? AIMS_Event_Reconciliation_Types::DISCREPANCY_INVENTORY ) ),
			'reference_type'    => sanitize_key( (string) ( $data['reference_type'] ?? 'manual' ) ),
			'reference_id'      => sanitize_text_field( (string) ( $data['reference_id'] ?? '' ) ),
			'expected_value'    => number_format( $expected_value, 4, '.', '' ),
			'actual_value'      => number_format( $actual_value, 4, '.', '' ),
			'variance_amount'   => number_format( $variance, 4, '.', '' ),
			'severity'          => AIMS_Event_Reconciliation_Types::normalize_severity( (string) ( $data['severity'] ?? AIMS_Event_Reconciliation_Types::SEVERITY_INFO ) ),
			'variance_percent'  => number_format( $percent, 4, '.', '' ),
			'resolution_notes'  => isset( $data['resolution_notes'] ) ? wp_kses_post( $data['resolution_notes'] ) : '',
			'resolved_by'       => empty( $data['resolved_by'] ) ? null : (int) $data['resolved_by'],
			'resolved_at'       => empty( $data['resolved_at'] ) ? null : $this->normalize_datetime( $data['resolved_at'] ),
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( $discrepancy_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $discrepancy_id ) );

			return $discrepancy_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $discrepancy_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $discrepancy_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_reconciliation( int $reconciliation_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE reconciliation_id = %d ORDER BY id DESC',
				$reconciliation_id
			),
			ARRAY_A
		);
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY id DESC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_pending_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND resolved_at IS NULL ORDER BY severity DESC, id DESC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function resolve( int $discrepancy_id, int $resolved_by, string $notes = '' ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'resolved_by'      => max( 0, $resolved_by ),
				'resolved_at'      => current_time( 'mysql' ),
				'resolution_notes' => wp_kses_post( $notes ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $discrepancy_id )
		);

		return false !== $updated;
	}

	private function calculate_percent( float $expected, float $variance ): float {
		if ( 0.0 === $expected ) {
			return 0.0;
		}

		return ( $variance / $expected ) * 100;
	}

	private function normalize_datetime( $value ): string {
		if ( empty( $value ) ) {
			return current_time( 'mysql' );
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
