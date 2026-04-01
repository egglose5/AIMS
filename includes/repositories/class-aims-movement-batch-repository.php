<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Movement_Batch_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_movement_batches';
	}

	public function find( int $batch_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $batch_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_reference( string $reference_type, string $reference_id, string $movement_type, string $batch_type = 'bucket_line_meta' ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE reference_type = %s AND reference_id = %s AND movement_type = %s AND batch_type = %s',
				sanitize_key( $reference_type ),
				sanitize_text_field( $reference_id ),
				sanitize_key( $movement_type ),
				sanitize_key( $batch_type )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function upsert_for_reference( array $data ): array {
		global $wpdb;

		$reference_type = sanitize_key( (string) ( $data['reference_type'] ?? '' ) );
		$reference_id   = sanitize_text_field( (string) ( $data['reference_id'] ?? '' ) );
		$movement_type  = sanitize_key( (string) ( $data['movement_type'] ?? '' ) );
		$batch_type     = sanitize_key( (string) ( $data['batch_type'] ?? 'bucket_line_meta' ) );

		$existing = $this->find_by_reference( $reference_type, $reference_id, $movement_type, $batch_type );
		if ( is_array( $existing ) ) {
			$wpdb->update(
				$this->get_table_name(),
				array(
					'vendor_id'           => (int) ( $data['vendor_id'] ?? (int) ( $existing['vendor_id'] ?? 0 ) ),
					'event_id'            => (int) ( $data['event_id'] ?? (int) ( $existing['event_id'] ?? 0 ) ),
					'stitch_job_id'       => (int) ( $data['stitch_job_id'] ?? (int) ( $existing['stitch_job_id'] ?? 0 ) ),
					'bucket_id'           => (int) ( $data['bucket_id'] ?? (int) ( $existing['bucket_id'] ?? 0 ) ),
					'created_by'          => (int) ( $data['created_by'] ?? (int) ( $existing['created_by'] ?? 0 ) ),
					'batch_summary_json'  => isset( $data['batch_summary_json'] ) ? wp_json_encode( $data['batch_summary_json'] ) : ( $existing['batch_summary_json'] ?? null ),
					'updated_at'          => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing['id'] )
			);

			return $this->find( (int) $existing['id'] ) ?? $existing;
		}

		$record = array(
			'batch_uuid'          => sanitize_text_field( (string) ( $data['batch_uuid'] ?? wp_generate_uuid4() ) ),
			'batch_type'          => $batch_type,
			'reference_type'      => $reference_type,
			'reference_id'        => $reference_id,
			'movement_type'       => $movement_type,
			'lifecycle_status'    => sanitize_key( (string) ( $data['lifecycle_status'] ?? 'hot' ) ),
			'line_count'          => 0,
			'total_quantity_delta'=> number_format( 0, 4, '.', '' ),
			'vendor_id'           => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'            => (int) ( $data['event_id'] ?? 0 ),
			'stitch_job_id'       => (int) ( $data['stitch_job_id'] ?? 0 ),
			'bucket_id'           => (int) ( $data['bucket_id'] ?? 0 ),
			'created_by'          => (int) ( $data['created_by'] ?? get_current_user_id() ),
			'archive_compression' => sanitize_key( (string) ( $data['archive_compression'] ?? 'none' ) ),
			'batch_summary_json'  => isset( $data['batch_summary_json'] ) ? wp_json_encode( $data['batch_summary_json'] ) : null,
			'line_meta_json'      => wp_json_encode( array() ),
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_name(), $record );

		return $this->find( (int) $wpdb->insert_id ) ?? array_merge( $record, array( 'id' => (int) $wpdb->insert_id ) );
	}

	public function append_line_meta( int $batch_id, array $line_meta, float $quantity_delta ): bool {
		global $wpdb;

		$batch = $this->find( $batch_id );
		if ( ! is_array( $batch ) ) {
			return false;
		}

		$lines = json_decode( (string) ( $batch['line_meta_json'] ?? '[]' ), true );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$lines[] = $line_meta;

		$result = $wpdb->update(
			$this->get_table_name(),
			array(
				'line_count'           => count( $lines ),
				'total_quantity_delta' => number_format( (float) ( $batch['total_quantity_delta'] ?? 0 ) + $quantity_delta, 4, '.', '' ),
				'last_line_at'         => current_time( 'mysql' ),
				'line_meta_json'       => wp_json_encode( $lines ),
				'updated_at'           => current_time( 'mysql' ),
			),
			array( 'id' => $batch_id )
		);

		return false !== $result;
	}

	public function bind_archive_manifest( int $batch_id, int $archive_manifest_id, string $lifecycle_status = 'archived' ): bool {
		global $wpdb;

		$result = $wpdb->update(
			$this->get_table_name(),
			array(
				'archive_manifest_id' => $archive_manifest_id,
				'lifecycle_status'    => sanitize_key( $lifecycle_status ),
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $batch_id )
		);

		return false !== $result;
	}
}
