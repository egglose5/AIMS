<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Projection_Parquet_Export_Service {
	private $export_root;
	private $writer_callback;

	public function __construct( string $export_root = '', callable $writer_callback = null ) {
		$this->export_root     = '' !== $export_root ? $export_root : $this->default_export_root();
		$this->writer_callback = $writer_callback;
	}

	public function export_run( int $run_id, array $projection_rows, array $hot_rows = array() ): array {
		$run_id = max( 0, $run_id );
		if ( $run_id <= 0 ) {
			throw new RuntimeException( 'A valid run id is required for parquet export.' );
		}

		if ( empty( $projection_rows ) && empty( $hot_rows ) ) {
			throw new RuntimeException( 'No projection or hot-list rows are available for parquet export.' );
		}

		$target_dir = $this->export_root;
		if ( ! is_dir( $target_dir ) && ! @mkdir( $target_dir, 0775, true ) && ! is_dir( $target_dir ) ) {
			throw new RuntimeException( 'Unable to create parquet export directory.' );
		}

		$filename = sprintf( 'aims-square-projection-run-%d-%s.parquet', $run_id, gmdate( 'Ymd-His' ) );
		$path     = rtrim( $target_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $filename;

		$normalized = $this->normalize_rows( $run_id, $projection_rows, $hot_rows );
		$this->write_parquet( $normalized, $path );

		return array(
			'run_id'    => $run_id,
			'row_count' => count( $normalized ),
			'projection_row_count' => count( $projection_rows ),
			'hot_row_count'        => count( $hot_rows ),
			'path'      => $path,
			'filename'  => $filename,
		);
	}

	private function write_parquet( array $rows, string $target_path ): void {
		if ( is_callable( $this->writer_callback ) ) {
			call_user_func( $this->writer_callback, $rows, $target_path );
			return;
		}

		if ( ! class_exists( '\\Flow\\Parquet\\Writer' ) ) {
			throw new RuntimeException( 'flow-php/parquet is required to export projection effects.' );
		}

		$writerClass = '\\Flow\\Parquet\\Writer';
		$schemaClass = '\\Flow\\Parquet\\ParquetFile\\Schema';
		$columnClass = '\\Flow\\Parquet\\ParquetFile\\Schema\\FlatColumn';

		$schema = $schemaClass::with(
			$columnClass::string( 'snapshot_kind' ),
			$columnClass::int64( 'run_id' ),
			$columnClass::string( 'point_state' ),
			$columnClass::int64( 'effect_id' ),
			$columnClass::string( 'effect_type' ),
			$columnClass::string( 'target_table' ),
			$columnClass::int64( 'target_id' ),
			$columnClass::string( 'created_at' ),
			$columnClass::string( 'status' ),
			$columnClass::string( 'reason' ),
			$columnClass::string( 'projection_mode' ),
			$columnClass::int64( 'woo_order_id' ),
			$columnClass::string( 'square_order_id' ),
			$columnClass::int64( 'sale_id' ),
			$columnClass::string( 'line_item_uid' ),
			$columnClass::int64( 'bucket_id' ),
			$columnClass::int64( 'vendor_id' ),
			$columnClass::int64( 'product_id' ),
			$columnClass::float( 'quantity' ),
			$columnClass::float( 'reserved_quantity' ),
			$columnClass::int64( 'last_bucket_movement_id' ),
			$columnClass::string( 'last_counted_at' ),
			$columnClass::string( 'snapshot_recorded_at' )
		);

		$writer = method_exists( $writerClass, 'php' ) ? $writerClass::php() : new $writerClass();
		$writer->write( $target_path, $schema, $rows );
	}

	private function normalize_rows( int $run_id, array $projection_rows, array $hot_rows ): array {
		$normalized = array();
		$snapshot_recorded_at = gmdate( 'Y-m-d H:i:s' );

		foreach ( $projection_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$normalized[] = array(
				'snapshot_kind'  => 'projection_effect',
				'run_id'         => $run_id,
				'point_state'    => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'effect_id'      => (int) ( $row['effect_id'] ?? 0 ),
				'effect_type'    => sanitize_key( (string) ( $row['effect_type'] ?? '' ) ),
				'target_table'   => sanitize_text_field( (string) ( $row['target_table'] ?? '' ) ),
				'target_id'      => (int) ( $row['target_id'] ?? 0 ),
				'created_at'     => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'status'         => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'reason'         => sanitize_key( (string) ( $row['reason'] ?? '' ) ),
				'projection_mode'=> sanitize_key( (string) ( $row['projection_mode'] ?? 'draft' ) ),
				'woo_order_id'   => (int) ( $row['woo_order_id'] ?? 0 ),
				'square_order_id'=> sanitize_text_field( (string) ( $row['square_order_id'] ?? '' ) ),
				'sale_id'        => (int) ( $row['sale_id'] ?? 0 ),
				'line_item_uid'  => sanitize_text_field( (string) ( $row['line_item_uid'] ?? '' ) ),
				'bucket_id'      => 0,
				'vendor_id'      => 0,
				'product_id'     => 0,
				'quantity'       => 0.0,
				'reserved_quantity' => 0.0,
				'last_bucket_movement_id' => 0,
				'last_counted_at' => '',
				'snapshot_recorded_at' => $snapshot_recorded_at,
			);
		}

		foreach ( $hot_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$normalized[] = array(
				'snapshot_kind'  => 'hot_position',
				'run_id'         => $run_id,
				'point_state'    => sanitize_key( (string) ( $row['position_status'] ?? $row['point_state'] ?? 'active' ) ),
				'effect_id'      => 0,
				'effect_type'    => 'hot_position_snapshot',
				'target_table'   => 'aims_bucket_inventory_positions',
				'target_id'      => (int) ( $row['id'] ?? 0 ),
				'created_at'     => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'status'         => 'hot',
				'reason'         => 'point_state_snapshot',
				'projection_mode'=> 'snapshot',
				'woo_order_id'   => 0,
				'square_order_id'=> '',
				'sale_id'        => 0,
				'line_item_uid'  => '',
				'bucket_id'      => (int) ( $row['bucket_id'] ?? 0 ),
				'vendor_id'      => (int) ( $row['vendor_id'] ?? 0 ),
				'product_id'     => (int) ( $row['product_id'] ?? 0 ),
				'quantity'       => (float) ( $row['quantity'] ?? 0 ),
				'reserved_quantity' => (float) ( $row['reserved_quantity'] ?? 0 ),
				'last_bucket_movement_id' => (int) ( $row['last_bucket_movement_id'] ?? 0 ),
				'last_counted_at' => sanitize_text_field( (string) ( $row['last_counted_at'] ?? '' ) ),
				'snapshot_recorded_at' => $snapshot_recorded_at,
			);
		}

		return $normalized;
	}

	/**
	 * Normalizes rows, writes a Parquet file to a temp path, streams the bytes
	 * into $resource (e.g. fopen('php://output','wb')), then removes the temp file.
	 * No vault file is left on disk.
	 *
	 * Returns export metadata (same shape as export_run except no 'path' key).
	 *
	 * @param resource $resource  A writable stream resource.
	 * @param string   $filename  Optional override for the temp filename (and the
	 *                            'filename' key in the return value). When empty a
	 *                            timestamped name is generated.
	 */
	public function stream_to_resource( int $run_id, array $projection_rows, array $hot_rows, $resource, string $filename = '' ): array {
		$run_id = max( 0, $run_id );
		if ( $run_id <= 0 ) {
			throw new RuntimeException( 'A valid run id is required for parquet stream export.' );
		}

		if ( empty( $projection_rows ) && empty( $hot_rows ) ) {
			throw new RuntimeException( 'No projection or hot-list rows are available for parquet stream export.' );
		}

		if ( '' === $filename ) {
			$filename = sprintf( 'aims-square-projection-run-%d-%s.parquet', $run_id, gmdate( 'Ymd-His' ) );
		}

		$tmp = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $filename;

		$normalized = $this->normalize_rows( $run_id, $projection_rows, $hot_rows );
		$this->write_parquet( $normalized, $tmp );

		try {
			$fh = fopen( $tmp, 'rb' );
			if ( false !== $fh ) {
				stream_copy_to_stream( $fh, $resource );
				fclose( $fh );
			}
		} finally {
			@unlink( $tmp );
		}

		return array(
			'run_id'               => $run_id,
			'row_count'            => count( $normalized ),
			'projection_row_count' => count( $projection_rows ),
			'hot_row_count'        => count( $hot_rows ),
			'filename'             => $filename,
		);
	}

	private function default_export_root(): string {
		return rtrim( AIMS_PLUGIN_PATH, DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR . 'ames-core'
			. DIRECTORY_SEPARATOR . 'vault'
			. DIRECTORY_SEPARATOR . 'exports'
			. DIRECTORY_SEPARATOR . 'square-sync';
	}
}
