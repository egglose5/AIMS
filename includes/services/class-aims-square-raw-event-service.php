<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Raw_Event_Service {
	private $raw_events;

	public function __construct( AIMS_Square_Raw_Event_Repository $raw_events = null ) {
		$this->raw_events = $raw_events;
	}

	public function save_raw_event( array $payload, array $context = array() ): array {
		$record   = $this->normalize_raw_event_record( $payload, $context );
		$existing = $this->find_existing_raw_event( $record['dedupe_key'] );

		if ( ! empty( $existing['id'] ) ) {
			return array(
				'raw_event_id' => (int) $existing['id'],
				'raw_event'    => $existing,
				'created'      => false,
				'dedupe_key'   => $record['dedupe_key'],
			);
		}

		if ( null === $this->raw_events ) {
			return array(
				'raw_event_id' => 0,
				'raw_event'    => $record,
				'created'      => false,
				'dedupe_key'   => $record['dedupe_key'],
			);
		}

		$raw_event_id = $this->raw_events->save( $record );

		return array(
			'raw_event_id' => (int) $raw_event_id,
			'raw_event'    => $record,
			'created'      => true,
			'dedupe_key'   => $record['dedupe_key'],
		);
	}

	public function build_dedupe_key( array $payload, array $context = array() ): string {
		$source_type   = sanitize_key( (string) ( $context['source_type'] ?? 'webhook' ) );
		$event_type    = sanitize_key( (string) ( $context['square_event_type'] ?? $payload['type'] ?? $payload['event_type'] ?? 'order' ) );
		$object_type   = sanitize_key( (string) ( $context['square_object_type'] ?? $payload['object_type'] ?? 'order' ) );
		$object_id     = sanitize_text_field( (string) ( $context['square_object_id'] ?? $payload['id'] ?? $payload['object_id'] ?? '' ) );
		$event_version = sanitize_text_field( (string) ( $context['event_version'] ?? $payload['version'] ?? '' ) );

		return implode(
			':',
			array(
				$source_type,
				$event_type,
				$object_type,
				$object_id,
				$event_version,
				sha1( wp_json_encode( $payload ) ),
			)
		);
	}

	public function normalize_raw_event_record( array $payload, array $context = array() ): array {
		$created_at = current_time( 'mysql' );

		return array(
			'source_type'        => sanitize_key( (string) ( $context['source_type'] ?? 'webhook' ) ),
			'square_event_type'   => sanitize_key( (string) ( $context['square_event_type'] ?? $payload['type'] ?? $payload['event_type'] ?? 'order' ) ),
			'square_object_type'  => sanitize_key( (string) ( $context['square_object_type'] ?? $payload['object_type'] ?? 'order' ) ),
			'square_object_id'    => sanitize_text_field( (string) ( $context['square_object_id'] ?? $payload['id'] ?? $payload['object_id'] ?? '' ) ),
			'dedupe_key'          => $this->build_dedupe_key( $payload, $context ),
			'event_version'       => sanitize_text_field( (string) ( $context['event_version'] ?? $payload['version'] ?? '' ) ),
			'payload'             => $payload,
			'received_at'         => $context['received_at'] ?? $created_at,
			'processing_status'   => sanitize_key( (string) ( $context['processing_status'] ?? 'pending' ) ),
			'processing_attempts' => (int) ( $context['processing_attempts'] ?? 0 ),
			'sync_run_id'         => (int) ( $context['sync_run_id'] ?? 0 ),
			'error_message'       => (string) ( $context['error_message'] ?? '' ),
		);
	}

	public function mark_processed( int $raw_event_id, string $status = 'processed', string $error_message = '' ): bool {
		if ( null === $this->raw_events || ! method_exists( $this->raw_events, 'update_status' ) ) {
			return false;
		}

		return (bool) $this->raw_events->update_status( $raw_event_id, $status, $error_message );
	}

	public function mark_error( int $raw_event_id, string $error_message = '' ): bool {
		return $this->mark_processed( $raw_event_id, 'error', $error_message );
	}

	public function increment_attempts( int $raw_event_id ): bool {
		if ( null === $this->raw_events || ! method_exists( $this->raw_events, 'increment_attempts' ) ) {
			return false;
		}

		return (bool) $this->raw_events->increment_attempts( $raw_event_id );
	}

	private function find_existing_raw_event( string $dedupe_key ): array {
		if ( null === $this->raw_events || ! method_exists( $this->raw_events, 'find_by_dedupe_key' ) ) {
			return array();
		}

		$existing = $this->raw_events->find_by_dedupe_key( $dedupe_key );

		return is_array( $existing ) ? $existing : array();
	}
}
