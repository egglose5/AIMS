<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Job_Repository {
	public const STATUS_QUEUED         = 'queued';
	public const STATUS_ASSIGNED       = 'assigned';
	public const STATUS_IN_PROGRESS    = 'in_progress';
	public const STATUS_STITCHING      = 'stitching';
	public const STATUS_IN_TRANSIT_BACK = 'in_transit_back';
	public const STATUS_COMPLETED      = 'completed';
	public const STATUS_RETURNED       = 'returned';
	public const STATUS_CANCELLED      = 'cancelled';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_stitch_jobs';
	}

	public function all_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE assigned_user_id = %d ORDER BY due_at ASC, priority ASC, id ASC',
				$user_id
			),
			ARRAY_A
		);
	}

	public function get_open_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$closed_statuses = array(
			self::STATUS_IN_TRANSIT_BACK,
			self::STATUS_COMPLETED,
			self::STATUS_RETURNED,
			self::STATUS_CANCELLED,
		);

		$query = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE assigned_user_id = %d AND status NOT IN (' . implode( ', ', array_fill( 0, count( $closed_statuses ), '%s' ) ) . ') ORDER BY due_at ASC, priority ASC, id ASC';

		return $wpdb->get_results(
			$wpdb->prepare(
				$query,
				array_merge( array( $user_id ), $closed_statuses )
			),
			ARRAY_A
		);
	}

	public function find( int $job_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$job_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function save( array $data, int $job_id = 0 ): int {
		global $wpdb;

		$record = array(
			'job_code'         => sanitize_text_field( (string) ( $data['job_code'] ?? '' ) ),
			'vendor_id'        => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'         => (int) ( $data['event_id'] ?? 0 ),
			'assigned_user_id'  => (int) ( $data['assigned_user_id'] ?? 0 ),
			'status'           => $this->normalize_status( (string) ( $data['status'] ?? self::STATUS_QUEUED ) ),
			'priority'         => $this->normalize_priority( (string) ( $data['priority'] ?? 'normal' ) ),
			'due_at'           => $this->normalize_datetime( $data['due_at'] ?? null ),
			'notes'            => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'       => current_time( 'mysql' ),
		);

		if ( $job_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $job_id ) );
			return $job_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function mark_complete_and_in_transit_back( int $job_id, int $user_id = 0, string $notes = '' ): bool {
		global $wpdb;

		if ( $job_id <= 0 ) {
			return false;
		}

		$record = array(
			'status'      => self::STATUS_IN_TRANSIT_BACK,
			'notes'       => $this->merge_notes( $job_id, $notes ),
			'updated_at'  => current_time( 'mysql' ),
		);

		if ( $user_id > 0 ) {
			$record['assigned_user_id'] = $user_id;
		}

		return false !== $wpdb->update(
			$this->get_table_name(),
			$record,
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	private function normalize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array(
			self::STATUS_QUEUED,
			self::STATUS_ASSIGNED,
			self::STATUS_IN_PROGRESS,
			self::STATUS_STITCHING,
			self::STATUS_IN_TRANSIT_BACK,
			self::STATUS_COMPLETED,
			self::STATUS_RETURNED,
			self::STATUS_CANCELLED,
		);

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_QUEUED;
	}

	private function normalize_priority( string $priority ): string {
		$priority = sanitize_key( $priority );
		$allowed  = array( 'low', 'normal', 'high', 'rush' );

		return in_array( $priority, $allowed, true ) ? $priority : 'normal';
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}

	private function merge_notes( int $job_id, string $notes ): string {
		$notes = trim( $notes );
		if ( '' === $notes ) {
			return '';
		}

		$current = $this->find( $job_id );
		$existing = trim( (string) ( $current['notes'] ?? '' ) );

		if ( '' === $existing ) {
			return $notes;
		}

		return $existing . "\n\n" . $notes;
	}
}
