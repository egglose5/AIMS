<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Event_Checkin_Repository {
	public const STATUS_RECORDED = 'recorded';
	public const STATUS_REVIEWED = 'reviewed';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_VOID = 'void';
	public const STATUS_ARCHIVED = 'archived';

	public const VISIBILITY_INTERNAL = 'internal';
	public const VISIBILITY_PUBLIC = 'public';
	public const VISIBILITY_PRIVATE = 'private';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendor_event_checkins';
	}

	public function save( array $data, int $checkin_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );
		$existing = array();

		if ( $checkin_id <= 0 && ! empty( $record['event_id'] ) && ! empty( $record['vendor_id'] ) ) {
			$existing = $this->find_by_event_vendor_bucket(
				(int) $record['event_id'],
				(int) $record['vendor_id'],
				(int) ( $record['physical_bucket_id'] ?? 0 )
			);
		}

		if ( ! empty( $existing['id'] ) ) {
			$checkin_id = (int) $existing['id'];
			$record['is_first_checkin'] = ! empty( $existing['is_first_checkin'] ) ? 1 : (int) $record['is_first_checkin'];
			$record['movement_applied'] = ! empty( $existing['movement_applied'] ) ? 1 : (int) $record['movement_applied'];
			$record['movement_applied_at'] = $existing['movement_applied_at'] ?? $record['movement_applied_at'];
			$record['movement_reference_type'] = (string) ( $existing['movement_reference_type'] ?? $record['movement_reference_type'] );
			$record['movement_reference_id'] = (string) ( $existing['movement_reference_id'] ?? $record['movement_reference_id'] );
		}

		if ( $checkin_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $checkin_id ) );
			return $checkin_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $checkin_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $checkin_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY checked_in_at DESC, id DESC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_for_vendor( int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d ORDER BY checked_in_at DESC, id DESC',
				$vendor_id
			),
			ARRAY_A
		);
	}

	public function get_for_event_vendor( int $event_id, int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND vendor_id = %d ORDER BY checked_in_at DESC, id DESC',
				$event_id,
				$vendor_id
			),
			ARRAY_A
		);
	}

	public function find_by_event_vendor_bucket( int $event_id, int $vendor_id, int $bucket_id = 0 ): ?array {
		global $wpdb;

		if ( $bucket_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND vendor_id = %d AND physical_bucket_id = %d LIMIT 1',
					$event_id,
					$vendor_id,
					$bucket_id
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND vendor_id = %d ORDER BY checked_in_at ASC, id ASC LIMIT 1',
					$event_id,
					$vendor_id
				),
				ARRAY_A
			);
		}

		return is_array( $row ) ? $row : null;
	}

	public function has_operational_checkin( int $event_id, int $vendor_id, int $bucket_id = 0 ): bool {
		return ! empty( $this->find_by_event_vendor_bucket( $event_id, $vendor_id, $bucket_id ) );
	}

	public function is_first_checkin( int $event_id, int $vendor_id, int $bucket_id = 0 ): bool {
		return ! $this->has_operational_checkin( $event_id, $vendor_id, $bucket_id );
	}

	public function get_first_checkin_for_event_vendor_bucket( int $event_id, int $vendor_id, int $bucket_id = 0 ): ?array {
		return $this->find_by_event_vendor_bucket( $event_id, $vendor_id, $bucket_id );
	}

	public function mark_movement_applied( int $checkin_id, array $data = array() ): bool {
		global $wpdb;

		if ( $checkin_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'movement_applied'      => 1,
				'movement_applied_at'   => $this->normalize_datetime( $data['movement_applied_at'] ?? current_time( 'mysql' ) ),
				'movement_reference_type' => sanitize_key( $data['movement_reference_type'] ?? '' ),
				'movement_reference_id'  => sanitize_text_field( $data['movement_reference_id'] ?? '' ),
				'checkin_status'        => $this->normalize_status( (string) ( $data['checkin_status'] ?? self::STATUS_COMPLETED ) ),
				'updated_at'            => current_time( 'mysql' ),
			),
			array( 'id' => $checkin_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_public_update_created( int $checkin_id, int $public_event_update_id ): bool {
		global $wpdb;

		if ( $checkin_id <= 0 || $public_event_update_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'public_event_update_id' => $public_event_update_id,
				'visibility_status' => self::VISIBILITY_PUBLIC,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $checkin_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	private function build_record( array $data ): array {
		$event_id      = (int) ( $data['event_id'] ?? 0 );
		$vendor_id     = (int) ( $data['vendor_id'] ?? 0 );
		$bucket_id     = (int) ( $data['physical_bucket_id'] ?? $data['bucket_id'] ?? 0 );
		$checked_in_at = $this->normalize_datetime( $data['checked_in_at'] ?? current_time( 'mysql' ) );

		return array(
			'event_id'                => $event_id,
			'vendor_id'               => $vendor_id,
			'vendor_event_assignment_id' => (int) ( $data['vendor_event_assignment_id'] ?? 0 ),
			'physical_bucket_id'      => $bucket_id,
			'public_event_update_id'  => (int) ( $data['public_event_update_id'] ?? 0 ),
			'checkin_source'          => sanitize_key( $data['checkin_source'] ?? 'vendor_portal' ),
			'checkin_status'          => $this->normalize_status( (string) ( $data['checkin_status'] ?? self::STATUS_RECORDED ) ),
			'visibility_status'       => $this->normalize_visibility( (string) ( $data['visibility_status'] ?? self::VISIBILITY_INTERNAL ) ),
			'is_first_checkin'        => array_key_exists( 'is_first_checkin', $data ) ? ( ! empty( $data['is_first_checkin'] ) ? 1 : 0 ) : 1,
			'movement_applied'        => array_key_exists( 'movement_applied', $data ) ? ( ! empty( $data['movement_applied'] ) ? 1 : 0 ) : 0,
			'movement_applied_at'     => $this->normalize_datetime( $data['movement_applied_at'] ?? null ),
			'movement_reference_type' => sanitize_key( $data['movement_reference_type'] ?? '' ),
			'movement_reference_id'   => sanitize_text_field( $data['movement_reference_id'] ?? '' ),
			'checkin_notes'           => isset( $data['checkin_notes'] ) ? wp_kses_post( $data['checkin_notes'] ) : '',
			'checkin_comment'         => isset( $data['checkin_comment'] ) ? wp_kses_post( $data['checkin_comment'] ) : '',
			'mobile_photo_reference'  => sanitize_text_field( $data['mobile_photo_reference'] ?? '' ),
			'checked_in_by'            => (int) ( $data['checked_in_by'] ?? get_current_user_id() ),
			'checked_in_at'            => $checked_in_at,
			'updated_at'               => current_time( 'mysql' ),
		);
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		$allowed = array(
			self::STATUS_RECORDED,
			self::STATUS_REVIEWED,
			self::STATUS_COMPLETED,
			self::STATUS_VOID,
			self::STATUS_ARCHIVED,
		);

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_RECORDED;
	}

	private function normalize_visibility( string $visibility ): string {
		$visibility = sanitize_key( $visibility );
		$allowed    = array(
			self::VISIBILITY_INTERNAL,
			self::VISIBILITY_PUBLIC,
			self::VISIBILITY_PRIVATE,
		);

		return in_array( $visibility, $allowed, true ) ? $visibility : self::VISIBILITY_INTERNAL;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
