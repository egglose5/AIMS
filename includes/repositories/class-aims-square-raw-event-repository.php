<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Raw_Event_Repository {
	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_PROCESSED  = 'processed';
	public const STATUS_ERROR      = 'error';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_square_raw_events';
	}

	public function save( array $data, int $event_id = 0 ): int {
		global $wpdb;

		$record = array(
			'source_type'         => sanitize_key( $data['source_type'] ?? 'webhook' ),
			'square_event_type'   => sanitize_text_field( $data['square_event_type'] ?? '' ),
			'square_object_type'  => sanitize_text_field( $data['square_object_type'] ?? '' ),
			'square_object_id'    => sanitize_text_field( $data['square_object_id'] ?? '' ),
			'dedupe_key'          => sanitize_text_field( $data['dedupe_key'] ?? '' ),
			'event_version'       => sanitize_text_field( $data['event_version'] ?? '' ),
			'payload_json'        => $this->encode_payload( $data['payload_json'] ?? ( $data['payload'] ?? null ) ),
			'received_at'         => $this->normalize_datetime( $data['received_at'] ?? current_time( 'mysql' ) ),
			'processing_status'   => $this->normalize_status( $data['processing_status'] ?? self::STATUS_PENDING ),
			'processing_attempts' => (int) ( $data['processing_attempts'] ?? 0 ),
			'sync_run_id'         => (int) ( $data['sync_run_id'] ?? 0 ),
			'error_message'       => sanitize_textarea_field( $data['error_message'] ?? '' ),
			'updated_at'          => current_time( 'mysql' ),
		);

		if ( $event_id <= 0 && '' !== $record['dedupe_key'] ) {
			$existing = $this->find_by_dedupe_key( $record['dedupe_key'] );
			if ( ! empty( $existing['id'] ) ) {
				$event_id = (int) $existing['id'];
			}
		}

		if ( $event_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $event_id ) );
			return $event_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find_by_dedupe_key( string $dedupe_key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE dedupe_key = %s',
				sanitize_text_field( $dedupe_key )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$event_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_object( string $square_object_type, string $square_object_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_object_type = %s AND square_object_id = %s ORDER BY id DESC LIMIT 1',
				sanitize_text_field( $square_object_type ),
				sanitize_text_field( $square_object_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_pending( int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE processing_status IN (%s, %s) ORDER BY received_at ASC, id ASC LIMIT %d',
				self::STATUS_PENDING,
				self::STATUS_ERROR,
				$limit
			),
			ARRAY_A
		);
	}

	public function get_for_sync_run( int $sync_run_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE sync_run_id = %d ORDER BY received_at ASC, id ASC',
				$sync_run_id
			),
			ARRAY_A
		);
	}

	public function update_status( int $event_id, string $status, ?string $error_message = null, ?int $attempts = null ): bool {
		global $wpdb;

		$payload = array(
			'processing_status' => $this->normalize_status( $status ),
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( null !== $error_message ) {
			$payload['error_message'] = sanitize_textarea_field( $error_message );
		}

		if ( null !== $attempts ) {
			$payload['processing_attempts'] = (int) $attempts;
		}

		$updated = $wpdb->update(
			$this->get_table_name(),
			$payload,
			array( 'id' => $event_id )
		);

		return false !== $updated;
	}

	public function mark_processing( int $event_id ): bool {
		return $this->update_status( $event_id, self::STATUS_PROCESSING, null, null );
	}

	public function mark_processed( int $event_id ): bool {
		return $this->update_status( $event_id, self::STATUS_PROCESSED, null, null );
	}

	public function mark_error( int $event_id, string $message = '', ?int $attempts = null ): bool {
		return $this->update_status( $event_id, self::STATUS_ERROR, $message, $attempts );
	}

	public function increment_attempts( int $event_id ): bool {
		$existing = $this->find( $event_id );

		if ( empty( $existing['id'] ) ) {
			return false;
		}

		return $this->update_status(
			$event_id,
			(string) ( $existing['processing_status'] ?? self::STATUS_PENDING ),
			(string) ( $existing['error_message'] ?? '' ),
			(int) ( $existing['processing_attempts'] ?? 0 ) + 1
		);
	}

	private function encode_payload( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		return wp_json_encode( $value );
	}

	private function normalize_status( $status ): string {
		$status = sanitize_key( (string) $status );

		$allowed = array(
			self::STATUS_PENDING,
			self::STATUS_PROCESSING,
			self::STATUS_PROCESSED,
			self::STATUS_ERROR,
		);

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_PENDING;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
