<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Square_Location_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_square_locations';
	}

	public function save( array $data, int $mapping_id = 0 ): int {
		global $wpdb;

		$record = array(
			'event_id'           => (int) ( $data['event_id'] ?? 0 ),
			'square_location_id' => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'is_primary'         => ! empty( $data['is_primary'] ) ? 1 : 0,
			'active_from'        => $this->normalize_datetime( $data['active_from'] ?? null ),
			'active_to'          => $this->normalize_datetime( $data['active_to'] ?? null ),
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $mapping_id <= 0 ) {
			$existing = $this->find_by_event_and_location( $record['event_id'], $record['square_location_id'] );
			if ( ! empty( $existing['id'] ) ) {
				$mapping_id = (int) $existing['id'];
			}
		}

		if ( $mapping_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $mapping_id ) );
			return $mapping_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY is_primary DESC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_primary_for_event( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1',
				$event_id
			),
			ARRAY_A
		);

		if ( is_array( $row ) ) {
			return $row;
		}

		$rows = $this->get_for_event( $event_id );

		return ! empty( $rows ) ? $rows[0] : null;
	}

	public function find_by_event_and_location( int $event_id, string $square_location_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND square_location_id = %s',
				$event_id,
				sanitize_text_field( $square_location_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_active_by_location_and_date( string $square_location_id, string $sold_at ): ?array {
		global $wpdb;

		$sold_at = $this->normalize_datetime( $sold_at );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_location_id = %s AND (active_from IS NULL OR active_from = "" OR active_from <= %s) AND (active_to IS NULL OR active_to = "" OR active_to >= %s) ORDER BY is_primary DESC, id ASC LIMIT 1',
				sanitize_text_field( $square_location_id ),
				$sold_at,
				$sold_at
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_matching_event( string $square_location_id, string $sold_at ): ?array {
		global $wpdb;

		$events_table = $wpdb->prefix . 'aims_events';
		$sold_date    = $this->normalize_date( $sold_at );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT events.* FROM ' . $this->get_table_name() . ' mappings INNER JOIN ' . $events_table . ' events ON events.id = mappings.event_id WHERE mappings.square_location_id = %s AND (mappings.active_from IS NULL OR DATE(mappings.active_from) <= %s) AND (mappings.active_to IS NULL OR DATE(mappings.active_to) >= %s) AND events.start_date <= %s AND events.end_date >= %s ORDER BY mappings.is_primary DESC, events.start_date DESC, events.id DESC LIMIT 1',
				sanitize_text_field( $square_location_id ),
				$sold_date,
				$sold_date,
				$sold_date,
				$sold_date
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}

	private function normalize_date( string $value ): string {
		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d', $time ) : sanitize_text_field( $value );
	}
}
