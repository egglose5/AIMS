<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Sync_Effect_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_sync_effects';
	}

	public function save( array $data, int $effect_id = 0 ): int {
		global $wpdb;

		$record = array(
			'sync_run_id'            => (int) ( $data['sync_run_id'] ?? 0 ),
			'sync_action_id'         => (int) ( $data['sync_action_id'] ?? 0 ),
			'effect_type'            => sanitize_key( $data['effect_type'] ?? '' ),
			'target_table'           => sanitize_text_field( $data['target_table'] ?? '' ),
			'target_id'              => (int) ( $data['target_id'] ?? 0 ),
			'reversal_status'        => sanitize_key( $data['reversal_status'] ?? 'pending' ),
			'reversed_at'            => $this->normalize_datetime( $data['reversed_at'] ?? null ),
			'reversal_sync_action_id'=> (int) ( $data['reversal_sync_action_id'] ?? 0 ),
			'metadata_json'          => $this->encode_payload( $data['metadata_json'] ?? ( $data['metadata'] ?? null ) ),
			'updated_at'             => current_time( 'mysql' ),
		);

		if ( $effect_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $effect_id ) );
			return $effect_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function get_for_run( int $sync_run_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE sync_run_id = %d ORDER BY id ASC',
				$sync_run_id
			),
			ARRAY_A
		);
	}

	public function get_for_action( int $sync_action_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE sync_action_id = %d ORDER BY id ASC',
				$sync_action_id
			),
			ARRAY_A
		);
	}

	public function find_by_target( string $target_table, int $target_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE target_table = %s AND target_id = %d ORDER BY id DESC LIMIT 1',
				sanitize_text_field( $target_table ),
				$target_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns true if any sync effect already exists for the given sync_run_id
	 * with a metadata_json entry whose raw_event_id matches $raw_event_id.
	 * Used as a fast dedup guard before re-processing a raw event.
	 */
	public function has_effect_for_raw_event( int $sync_run_id, int $raw_event_id ): bool {
		global $wpdb;

		if ( $sync_run_id <= 0 || $raw_event_id <= 0 ) {
			return false;
		}

		// wp_json_encode always writes numeric values without quotes so the
		// pattern "raw_event_id":<int> is safe and stable.
		$pattern = '%"raw_event_id":' . (int) $raw_event_id . '%';
		$count   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->get_table_name() . ' WHERE sync_run_id = %d AND metadata_json LIKE %s',
				$sync_run_id,
				$pattern
			)
		);

		return (int) $count > 0;
	}

	public function mark_reversed( int $effect_id, ?int $reversal_sync_action_id = null, ?string $reversed_at = null ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'reversal_status'        => 'reversed',
				'reversal_sync_action_id'=> (int) ( $reversal_sync_action_id ?? 0 ),
				'reversed_at'            => $this->normalize_datetime( $reversed_at ?? current_time( 'mysql' ) ),
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'id' => $effect_id )
		);

		return false !== $updated;
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

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
