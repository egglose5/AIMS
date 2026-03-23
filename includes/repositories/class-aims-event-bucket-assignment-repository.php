<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Bucket_Assignment_Repository {
	public const STATUS_ASSIGNED = 'assigned';
	public const STATUS_STAGED = 'staged';
	public const STATUS_IN_TRANSIT = 'in_transit';
	public const STATUS_AT_EVENT = 'at_event';
	public const STATUS_RETURNED = 'returned';
	public const STATUS_RELEASED = 'released';
	public const STATUS_CANCELLED = 'cancelled';

	public const TYPE_EVENT_STOCK = 'event_stock';
	public const TYPE_BACKSTOCK = 'backstock';
	public const TYPE_DISPLAY = 'display';
	public const TYPE_PICKUP_HOLD = 'pickup_hold';
	public const TYPE_RETURNS = 'returns';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_bucket_assignments';
	}

	public function save( array $data, int $assignment_id = 0 ): int {
		global $wpdb;

		$record = array(
			'event_id'           => (int) ( $data['event_id'] ?? 0 ),
			'physical_bucket_id' => (int) ( $data['physical_bucket_id'] ?? $data['bucket_id'] ?? 0 ),
			'assignment_status'  => $this->normalize_status( (string) ( $data['assignment_status'] ?? self::STATUS_ASSIGNED ) ),
			'assignment_type'    => $this->normalize_type( (string) ( $data['assignment_type'] ?? self::TYPE_EVENT_STOCK ) ),
			'assigned_at'        => $this->normalize_datetime( $data['assigned_at'] ?? null ),
			'released_at'        => $this->normalize_datetime( $data['released_at'] ?? null ),
			'assigned_by'        => (int) ( $data['assigned_by'] ?? 0 ),
			'released_by'        => (int) ( $data['released_by'] ?? 0 ),
			'display_order'      => (int) ( $data['display_order'] ?? 0 ),
			'is_active'          => array_key_exists( 'is_active', $data ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $assignment_id <= 0 && ! empty( $record['event_id'] ) && ! empty( $record['physical_bucket_id'] ) && ! empty( $record['is_active'] ) ) {
			$existing = $this->find_active_by_event_and_bucket( $record['event_id'], $record['physical_bucket_id'] );
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

	public function find( int $assignment_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $assignment_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY is_active DESC, display_order ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_active_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND is_active = 1 ORDER BY display_order ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_active_for_bucket( int $bucket_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE physical_bucket_id = %d AND is_active = 1 ORDER BY id DESC LIMIT 1',
				$bucket_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_active_by_event_and_bucket( int $event_id, int $bucket_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND physical_bucket_id = %d AND is_active = 1 LIMIT 1',
				$event_id,
				$bucket_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function release( int $assignment_id, array $data = array() ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'assignment_status' => $this->normalize_status( (string) ( $data['assignment_status'] ?? self::STATUS_RELEASED ) ),
				'released_at'       => $this->normalize_datetime( $data['released_at'] ?? current_time( 'mysql' ) ),
				'released_by'       => (int) ( $data['released_by'] ?? 0 ),
				'is_active'         => 0,
				'notes'             => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $assignment_id )
		);
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		$allowed = array(
			self::STATUS_ASSIGNED,
			self::STATUS_STAGED,
			self::STATUS_IN_TRANSIT,
			self::STATUS_AT_EVENT,
			self::STATUS_RETURNED,
			self::STATUS_RELEASED,
			self::STATUS_CANCELLED,
		);

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_ASSIGNED;
	}

	private function normalize_type( string $type ): string {
		$type = sanitize_key( $type );
		$allowed = array(
			self::TYPE_EVENT_STOCK,
			self::TYPE_BACKSTOCK,
			self::TYPE_DISPLAY,
			self::TYPE_PICKUP_HOLD,
			self::TYPE_RETURNS,
		);

		return in_array( $type, $allowed, true ) ? $type : self::TYPE_EVENT_STOCK;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
