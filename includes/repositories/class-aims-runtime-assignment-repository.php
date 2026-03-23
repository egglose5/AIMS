<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Runtime_Assignment_Repository {
	public const STATUS_DRAFT     = 'draft';
	public const STATUS_APPROVED  = 'approved';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_INACTIVE  = 'inactive';
	public const STATUS_MANUAL    = 'manual';
	public const STATUS_CANCELLED = 'cancelled';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_runtime_assignments';
	}

	public function save( array $data, int $assignment_id = 0 ): int {
		global $wpdb;

		$record = array(
			'vendor_id'            => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'             => (int) ( $data['event_id'] ?? 0 ),
			'request_id'           => (int) ( $data['request_id'] ?? 0 ),
			'source_assignment_id'  => (int) ( $data['source_assignment_id'] ?? 0 ),
			'square_team_member_id' => sanitize_text_field( $data['square_team_member_id'] ?? '' ),
			'square_location_id'    => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'starts_at'             => $this->normalize_datetime( $data['starts_at'] ?? null ),
			'ends_at'               => $this->normalize_datetime( $data['ends_at'] ?? null ),
			'status'                => $this->normalize_status( $data['status'] ?? self::STATUS_DRAFT ),
			'assignment_mode'       => sanitize_key( $data['assignment_mode'] ?? 'fcfs' ),
			'manual_override_flag'  => ! empty( $data['manual_override_flag'] ) ? 1 : 0,
			'priority'              => (int) ( $data['priority'] ?? 0 ),
			'commission_rate'       => number_format( (float) ( $data['commission_rate'] ?? 0 ), 4, '.', '' ),
			'active_for_import'     => ! empty( $data['active_for_import'] ) ? 1 : 0,
			'notes'                 => sanitize_textarea_field( $data['notes'] ?? '' ),
			'updated_at'            => current_time( 'mysql' ),
		);

		if ( $assignment_id <= 0 && '' !== $record['square_location_id'] ) {
			$existing = $this->find_by_runtime_key(
				$record['vendor_id'],
				$record['event_id'],
				$record['square_location_id'],
				$record['starts_at'],
				$record['ends_at']
			);

			if ( ! empty( $existing['id'] ) ) {
				$assignment_id = (int) $existing['id'];
			}
		}

		if ( $assignment_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $assignment_id ) );
			return $assignment_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY priority ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_for_vendor( int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d ORDER BY priority ASC, id ASC',
				$vendor_id
			),
			ARRAY_A
		);
	}

	public function get_active_for_location_and_date( string $square_location_id, string $sold_at ): array {
		global $wpdb;

		$sold_at = $this->normalize_datetime( $sold_at );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_location_id = %s AND active_for_import = 1 AND status IN (%s, %s, %s) AND (starts_at IS NULL OR starts_at = "" OR starts_at <= %s) AND (ends_at IS NULL OR ends_at = "" OR ends_at >= %s) ORDER BY priority ASC, starts_at ASC, id ASC',
				sanitize_text_field( $square_location_id ),
				self::STATUS_APPROVED,
				self::STATUS_ACTIVE,
				self::STATUS_MANUAL,
				$sold_at,
				$sold_at
			),
			ARRAY_A
		);
	}

	public function find_matching_assignment( string $square_location_id, string $sold_at, ?string $square_team_member_id = null ): ?array {
		$assignments = $this->get_active_for_location_and_date( $square_location_id, $sold_at );

		if ( empty( $assignments ) ) {
			return null;
		}

		if ( null !== $square_team_member_id && '' !== $square_team_member_id ) {
			$normalized_team_member_id = sanitize_text_field( $square_team_member_id );

			foreach ( $assignments as $assignment ) {
				if ( $normalized_team_member_id === (string) ( $assignment['square_team_member_id'] ?? '' ) ) {
					return $assignment;
				}
			}
		}

		return $assignments[0];
	}

	public function get_primary_for_event( int $event_id ): ?array {
		$assignments = $this->get_for_event( $event_id );

		return ! empty( $assignments ) ? $assignments[0] : null;
	}

	public function find_by_runtime_key( int $vendor_id, int $event_id, string $square_location_id, ?string $starts_at = null, ?string $ends_at = null ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d AND event_id = %d AND square_location_id = %s AND COALESCE(starts_at, "") = %s AND COALESCE(ends_at, "") = %s LIMIT 1',
				$vendor_id,
				$event_id,
				sanitize_text_field( $square_location_id ),
				$this->normalize_datetime( $starts_at ),
				$this->normalize_datetime( $ends_at )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function normalize_status( $status ): string {
		$status = sanitize_key( (string) $status );

		$allowed = array(
			self::STATUS_DRAFT,
			self::STATUS_APPROVED,
			self::STATUS_ACTIVE,
			self::STATUS_INACTIVE,
			self::STATUS_MANUAL,
			self::STATUS_CANCELLED,
		);

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_DRAFT;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
