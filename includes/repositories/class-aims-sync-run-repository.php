<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Sync_Run_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_sync_runs';
	}

	public function save( array $data, int $run_id = 0 ): int {
		global $wpdb;

		$record = array(
			'source_system'     => sanitize_key( $data['source_system'] ?? '' ),
			'started_at'        => $this->normalize_datetime( $data['started_at'] ?? current_time( 'mysql' ) ),
			'completed_at'      => $this->normalize_datetime( $data['completed_at'] ?? null ),
			'sync_watermark'    => sanitize_text_field( $data['sync_watermark'] ?? '' ),
			'success'           => ! empty( $data['success'] ) ? 1 : 0,
			'processed_records' => (int) ( $data['processed_records'] ?? 0 ),
			'skipped_records'   => (int) ( $data['skipped_records'] ?? 0 ),
			'error_count'       => (int) ( $data['error_count'] ?? 0 ),
			'message'           => sanitize_textarea_field( $data['message'] ?? '' ),
		);

		if ( $run_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $run_id ) );
			return $run_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function start_run( array $data = array() ): int {
		$data['started_at'] = $data['started_at'] ?? current_time( 'mysql' );
		$data['success']    = 0;

		return $this->save( $data );
	}

	public function finish_run( int $run_id, array $data = array() ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'completed_at'      => $this->normalize_datetime( $data['completed_at'] ?? current_time( 'mysql' ) ),
				'success'           => ! empty( $data['success'] ) ? 1 : 0,
				'processed_records' => (int) ( $data['processed_records'] ?? 0 ),
				'skipped_records'   => (int) ( $data['skipped_records'] ?? 0 ),
				'error_count'       => (int) ( $data['error_count'] ?? 0 ),
				'sync_watermark'    => sanitize_text_field( $data['sync_watermark'] ?? '' ),
				'message'           => sanitize_textarea_field( $data['message'] ?? '' ),
			),
			array( 'id' => $run_id )
		);

		return false !== $updated;
	}

	public function update_counters( int $run_id, array $data ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'processed_records' => (int) ( $data['processed_records'] ?? 0 ),
				'skipped_records'   => (int) ( $data['skipped_records'] ?? 0 ),
				'error_count'       => (int) ( $data['error_count'] ?? 0 ),
			),
			array( 'id' => $run_id )
		);

		return false !== $updated;
	}

	public function find( int $run_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$run_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_latest_for_source( string $source_system ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE source_system = %s ORDER BY started_at DESC, id DESC LIMIT 1',
				sanitize_key( $source_system )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_source( string $source_system, int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE source_system = %s ORDER BY started_at DESC, id DESC LIMIT %d',
				sanitize_key( $source_system ),
				$limit
			),
			ARRAY_A
		);
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_array( $value ) ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
