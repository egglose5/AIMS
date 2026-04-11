<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Movement_Lifecycle_Service {
	private $batches;
	private $archives;

	public function __construct( AIMS_Movement_Batch_Repository $batches = null, AIMS_Movement_Archive_Manifest_Repository $archives = null ) {
		$this->batches  = $batches ?: new AIMS_Movement_Batch_Repository();
		$this->archives = $archives ?: new AIMS_Movement_Archive_Manifest_Repository();
	}

	public function ensure_hot_batch( array $data ): array {
		return $this->batches->upsert_for_reference(
			array(
				'reference_type'      => (string) ( $data['reference_type'] ?? '' ),
				'reference_id'        => (string) ( $data['reference_id'] ?? '' ),
				'movement_type'       => (string) ( $data['movement_type'] ?? '' ),
				'batch_type'          => 'bucket_line_meta',
				'lifecycle_status'    => 'hot',
				'vendor_id'           => (int) ( $data['vendor_id'] ?? 0 ),
				'event_id'            => (int) ( $data['event_id'] ?? 0 ),
				'stitch_job_id'       => (int) ( $data['stitch_job_id'] ?? 0 ),
				'bucket_id'           => (int) ( $data['bucket_id'] ?? 0 ),
				'created_by'          => (int) ( $data['applied_by'] ?? get_current_user_id() ),
				'archive_compression' => 'gzip',
				'batch_summary_json'  => $this->build_batch_summary( $data ),
			)
		);
	}

	public function capture_hot_line( int $batch_id, int $movement_id, array $data ): bool {
		if ( $batch_id <= 0 || $movement_id <= 0 ) {
			return false;
		}

		return $this->batches->append_line_meta(
			$batch_id,
			$this->build_line_meta( $movement_id, $data ),
			(float) ( $data['quantity_delta'] ?? 0 )
		);
	}

	public function prepare_archive_manifest( int $batch_id ): ?array {
		$batch = $this->batches->find( $batch_id );
		if ( ! is_array( $batch ) ) {
			return null;
		}

		$lines = json_decode( (string) ( $batch['line_meta_json'] ?? '[]' ), true );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$active_window = $this->calculate_active_window( $lines );
		$payload = array(
			'batch_uuid'      => (string) ( $batch['batch_uuid'] ?? '' ),
			'batch_type'      => (string) ( $batch['batch_type'] ?? '' ),
			'reference_type'  => (string) ( $batch['reference_type'] ?? '' ),
			'reference_id'    => (string) ( $batch['reference_id'] ?? '' ),
			'movement_type'   => (string) ( $batch['movement_type'] ?? '' ),
			'lifecycle_status'=> 'archivable',
			'line_count'      => count( $lines ),
			'active_from'     => $active_window['from'],
			'active_to'       => $active_window['to'],
			'segment_month'   => $active_window['month'],
			'lines'           => $lines,
		);

		$payload_json = wp_json_encode( $payload );
		$archive_key  = sanitize_key( (string) ( $batch['movement_type'] ?? 'movement' ) ) . '-' . (int) $batch_id . '-' . preg_replace( '/[^0-9]/', '', (string) $active_window['month'] ) . '-' . gmdate( 'YmdHis' );

		$archive_id = $this->archives->create(
			array(
				'archive_key'       => $archive_key,
				'movement_batch_id' => $batch_id,
				'archive_status'    => 'prepared',
				'storage_backend'   => 'local_wp',
				'archive_format'    => 'json',
				'compression_codec' => 'gzip',
				'payload_checksum'  => md5( $payload_json ),
				'line_count'        => count( $lines ),
				'payload_bytes'     => strlen( $payload_json ),
				'manifest_json'     => array(
					'reference_type' => $batch['reference_type'] ?? '',
					'reference_id'   => $batch['reference_id'] ?? '',
					'movement_type'  => $batch['movement_type'] ?? '',
					'active_from'    => $active_window['from'],
					'active_to'      => $active_window['to'],
					'segment_month'  => $active_window['month'],
				),
				'payload_json'      => $payload,
				'exported_at'       => current_time( 'mysql' ),
			)
		);

		if ( $archive_id <= 0 ) {
			return null;
		}

		$this->batches->bind_archive_manifest( $batch_id, $archive_id, 'archivable' );

		return $this->archives->find_for_batch( $batch_id );
	}

	private function build_batch_summary( array $data ): array {
		return array(
			'vendor_id'     => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'      => (int) ( $data['event_id'] ?? 0 ),
			'stitch_job_id' => (int) ( $data['stitch_job_id'] ?? 0 ),
			'bucket_id'     => (int) ( $data['bucket_id'] ?? 0 ),
			'bucket_code'   => sanitize_text_field( (string) ( $data['bucket_code'] ?? '' ) ),
		);
	}

	private function calculate_active_window( array $lines ): array {
		$timestamps = array();

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			foreach ( array( 'created_at', 'recorded_at' ) as $key ) {
				$value = trim( (string) ( $line[ $key ] ?? '' ) );
				if ( '' !== $value ) {
					$timestamps[] = $value;
					break;
				}
			}
		}

		if ( empty( $timestamps ) ) {
			$now = current_time( 'mysql' );
			return array(
				'from'  => $now,
				'to'    => $now,
				'month' => substr( $now, 0, 7 ),
			);
		}

		sort( $timestamps, SORT_STRING );
		$from = (string) $timestamps[0];
		$to   = (string) $timestamps[ count( $timestamps ) - 1 ];

		return array(
			'from'  => $from,
			'to'    => $to,
			'month' => substr( $from, 0, 7 ),
		);
	}

	private function build_line_meta( int $movement_id, array $data ): array {
		return array(
			'movement_id'       => $movement_id,
			'movement_uuid'     => sanitize_text_field( (string) ( $data['movement_uuid'] ?? '' ) ),
			'product_id'        => (int) ( $data['product_id'] ?? 0 ),
			'sku'               => sanitize_text_field( (string) ( $data['sku'] ?? '' ) ),
			'bucket_id'         => (int) ( $data['bucket_id'] ?? 0 ),
			'bucket_code'       => sanitize_text_field( (string) ( $data['bucket_code'] ?? '' ) ),
			'vendor_id'         => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'          => (int) ( $data['event_id'] ?? 0 ),
			'source_bucket_id'  => (int) ( $data['source_bucket_id'] ?? 0 ),
			'target_bucket_id'  => (int) ( $data['target_bucket_id'] ?? 0 ),
			'source_storage_location_id' => (int) ( $data['source_storage_location_id'] ?? 0 ),
			'target_storage_location_id' => (int) ( $data['target_storage_location_id'] ?? 0 ),
			'quantity_delta'    => number_format( (float) ( $data['quantity_delta'] ?? 0 ), 4, '.', '' ),
			'applied_by'        => (int) ( $data['applied_by'] ?? 0 ),
			'line_meta'         => $data['metadata_json'] ?? array(),
			'created_at'        => current_time( 'mysql' ),
		);
	}
}
