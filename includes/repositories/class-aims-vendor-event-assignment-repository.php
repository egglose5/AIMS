<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Event_Assignment_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendor_event_assignments';
	}

	public function save( array $data, int $assignment_id = 0 ): int {
		global $wpdb;

		$record = array(
			'event_id'           => (int) ( $data['event_id'] ?? 0 ),
			'vendor_id'          => (int) ( $data['vendor_id'] ?? 0 ),
			'assignment_status'  => sanitize_key( $data['assignment_status'] ?? 'assigned' ),
			'commission_rate'    => number_format( (float) ( $data['commission_rate'] ?? 0 ), 4, '.', '' ),
			'fulfillment_status' => sanitize_key( $data['fulfillment_status'] ?? 'pending' ),
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $assignment_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $assignment_id ),
				array( '%d', '%d', '%s', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $assignment_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY vendor_id ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_primary_for_event( int $event_id ): ?array {
		$assignments = $this->get_for_event( $event_id );

		return ! empty( $assignments ) ? $assignments[0] : null;
	}

	public function get_vendor_id_for_event( int $event_id ): int {
		$assignment = $this->get_primary_for_event( $event_id );

		return ! empty( $assignment['vendor_id'] ) ? (int) $assignment['vendor_id'] : 0;
	}
}
