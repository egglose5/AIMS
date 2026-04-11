<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareProjectionParquetExportServiceTest extends \AIMS\Tests\TestCase {
	public function testExportRunUsesWriterCallbackAndReturnsExportMetadata(): void {
		$captured_rows = array();
		$captured_path = '';
		$export_root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-square-parquet-test-' . uniqid();

		$service = new \AIMS_Square_Projection_Parquet_Export_Service(
			$export_root,
			static function ( array $rows, string $target_path ) use ( &$captured_rows, &$captured_path ): void {
				$captured_rows = $rows;
				$captured_path = $target_path;
				file_put_contents( $target_path, 'PAR1-test' );
			}
		);

		$result = $service->export_run(
			44,
			array(
				array(
					'effect_id'       => 2001,
					'effect_type'     => 'import_projection',
					'target_table'    => 'aims_square_sales',
					'target_id'       => 3301,
					'created_at'      => '2026-04-11 14:10:00',
					'status'          => 'projected',
					'reason'          => 'draft_projected',
					'projection_mode' => 'draft',
					'woo_order_id'    => 8801,
					'square_order_id' => 'SQ-ORDER-1',
					'sale_id'         => 3301,
					'line_item_uid'   => 'LINE-1',
				),
			),
			array(
				array(
					'id'                      => 77,
					'bucket_id'               => 120,
					'vendor_id'               => 31,
					'product_id'              => 901,
					'quantity'                => 12.25,
					'reserved_quantity'       => 1.50,
					'position_status'         => 'active',
					'last_bucket_movement_id' => 501,
					'last_counted_at'         => '2026-04-11 14:12:00',
				),
			)
		);

		$this->assertSame( 44, $result['run_id'] ?? 0 );
		$this->assertSame( 2, $result['row_count'] ?? 0 );
		$this->assertSame( 1, $result['projection_row_count'] ?? 0 );
		$this->assertSame( 1, $result['hot_row_count'] ?? 0 );
		$this->assertNotEmpty( $captured_rows );
		$this->assertSame( 'projection_effect', $captured_rows[0]['snapshot_kind'] ?? '' );
		$this->assertSame( 'hot_position', $captured_rows[1]['snapshot_kind'] ?? '' );
		$this->assertSame( 'active', $captured_rows[1]['point_state'] ?? '' );
		$this->assertSame( 120, $captured_rows[1]['bucket_id'] ?? 0 );
		$this->assertStringContainsString( '.parquet', (string) ( $result['filename'] ?? '' ) );
		$this->assertFileExists( (string) ( $result['path'] ?? '' ) );
		$this->assertSame( (string) ( $result['path'] ?? '' ), $captured_path );

		@unlink( (string) ( $result['path'] ?? '' ) );
		@rmdir( $export_root );
	}

	public function testStreamToResourceWritesParquetBytesToOutputStream(): void {
		$captured_rows = array();
		$export_root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-square-parquet-stream-test-' . uniqid();

		$service = new \AIMS_Square_Projection_Parquet_Export_Service(
			$export_root,
			static function ( array $rows, string $target_path ) use ( &$captured_rows ): void {
				$captured_rows = $rows;
				file_put_contents( $target_path, 'PAR1-stream-test' );
			}
		);

		$mem = fopen( 'php://memory', 'r+b' );
		$this->assertNotFalse( $mem, 'Should be able to open php://memory for writing.' );

		$result = $service->stream_to_resource(
			44,
			array(
				array(
					'effect_id'       => 2002,
					'effect_type'     => 'import_projection',
					'target_table'    => 'aims_square_sales',
					'target_id'       => 3302,
					'created_at'      => '2026-04-11 15:00:00',
					'status'          => 'projected',
					'reason'          => 'draft_projected',
					'projection_mode' => 'draft',
					'woo_order_id'    => 8802,
					'square_order_id' => 'SQ-ORDER-2',
					'sale_id'         => 3302,
					'line_item_uid'   => 'LINE-2',
				),
			),
			array(
				array(
					'id'                      => 78,
					'bucket_id'               => 121,
					'vendor_id'               => 32,
					'product_id'              => 902,
					'quantity'                => 5.0,
					'reserved_quantity'       => 0.0,
					'position_status'         => 'active',
					'last_bucket_movement_id' => 502,
					'last_counted_at'         => '2026-04-11 15:05:00',
				),
			),
			$mem
		);

		rewind( $mem );
		$bytes = stream_get_contents( $mem );
		fclose( $mem );

		$this->assertSame( 44, $result['run_id'] ?? 0 );
		$this->assertSame( 2, $result['row_count'] ?? 0 );
		$this->assertSame( 1, $result['projection_row_count'] ?? 0 );
		$this->assertSame( 1, $result['hot_row_count'] ?? 0 );
		$this->assertStringContainsString( '.parquet', (string) ( $result['filename'] ?? '' ) );
		$this->assertArrayNotHasKey( 'path', $result, 'stream_to_resource should not return a persisted path.' );
		$this->assertNotEmpty( $bytes, 'Parquet bytes should have been written to the output resource.' );
		$this->assertSame( 2, count( $captured_rows ), 'Writer callback should receive all normalized rows.' );
		$this->assertSame( 'hot_position', $captured_rows[1]['snapshot_kind'] ?? '' );
		$this->assertSame( 121, $captured_rows[1]['bucket_id'] ?? 0 );
	}
}
