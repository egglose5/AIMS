<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Archive\ArchiveService;
use AmesCore\Archive\ArchiveSinkInterface;
use AmesCore\Archive\ParquetWriterInterface;
use AmesCore\Headless\Storage\FlowParquetArchiveWriter;
use AmesCore\Headless\Storage\FlowParquetHistoryReader;

final class AmesCoreArchiveServiceTest extends \AIMS\Tests\TestCase {
	public function testArchiveServiceWritesYearBucketAndTruncatesHotRows(): void {
		$vaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-vault-' . uniqid( '', true );

		$sink = new class() implements ArchiveSinkInterface {
			/** @var array<int, array<string, mixed>> */
			public array $rows = array();
			/** @var array<int, string> */
			public array $truncated = array();

			public function fetchHotRows( string $showId ): array {
				$this->rows = array(
					array(
						'id'       => 1,
						'show_id'  => $showId,
						'sku'      => 'SKU-101',
						'from_loc' => 'dock',
						'to_loc'   => 'stage',
						'quantity' => 2,
					),
					array(
						'id'       => 2,
						'show_id'  => $showId,
						'sku'      => 'SKU-202',
						'from_loc' => 'dock',
						'to_loc'   => 'greenroom',
						'quantity' => 1,
					),
				);

				return $this->rows;
			}

			public function truncateHotRows( string $showId ): void {
				$this->truncated[] = $showId;
				$this->rows = array();
			}
		};

		$writer = new class() implements ParquetWriterInterface {
			/** @var array<int, array<string, mixed>> */
			public array $lastRows = array();
			public string $lastTargetPath = '';

			public function write( array $rows, string $targetPath ): void {
				$this->lastRows = $rows;
				$this->lastTargetPath = $targetPath;
				$payload = wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				file_put_contents( $targetPath, (string) $payload );
			}
		};

		$service = new ArchiveService( $sink, $writer, $vaultRoot );
		$result = $service->archiveShow( 'SHOW-42', 2026 );

		$this->assertSame( 'SHOW-42', $result['show_id'] );
		$this->assertSame( 2026, $result['year'] );
		$this->assertSame( 2, $result['row_count'] );
		$this->assertStringEndsWith( DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . 'SHOW-42.parquet', $result['target_path'] );
		$this->assertFileExists( $result['target_path'] );
		$this->assertSame( $sink->truncated, array( 'SHOW-42' ) );
		$this->assertSame( array(), $sink->rows );
		$this->assertSame( 2, count( $writer->lastRows ) );
		$this->assertSame( $result['target_path'], $writer->lastTargetPath );
		$this->assertJsonStringEqualsJsonString(
			wp_json_encode( $writer->lastRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			(string) file_get_contents( $result['target_path'] )
		);
	}

	public function testArchiveServiceSplitsHotRowsIntoDateRangedSegmentsWhenCapIsExceeded(): void {
		$vaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-vault-' . uniqid( '', true );

		$sink = new class() implements ArchiveSinkInterface {
			public function fetchHotRows( string $showId ): array {
				return array(
					array(
						'id' => 1,
						'show_id' => $showId,
						'sku' => 'SKU-101',
						'timestamp' => '2026-04-01T09:15:00Z',
					),
					array(
						'id' => 2,
						'show_id' => $showId,
						'sku' => 'SKU-202',
						'timestamp' => '2026-04-02T11:30:00Z',
					),
					array(
						'id' => 3,
						'show_id' => $showId,
						'sku' => 'SKU-303',
						'timestamp' => '2026-04-10T16:45:00Z',
					),
				);
			}

			public function truncateHotRows( string $showId ): void {
				unset( $showId );
			}
		};

		$writer = new class() implements ParquetWriterInterface {
			public array $writes = array();

			public function write( array $rows, string $targetPath ): void {
				$this->writes[] = array(
					'rows' => $rows,
					'target' => $targetPath,
				);
				file_put_contents( $targetPath, (string) wp_json_encode( $rows ) );
			}
		};

		$service = new ArchiveService(
			$sink,
			$writer,
			$vaultRoot,
			array(
				'max_rows_per_file' => 2,
			)
		);

		$result = $service->archiveShow( 'SHOW-42', 2026 );

		$this->assertCount( 2, $result['segments'] );
		$this->assertSame( 2, $result['segments'][0]['row_count'] );
		$this->assertSame( 1, $result['segments'][1]['row_count'] );
		$this->assertSame( '2026-04-01T09:15:00Z', $result['segments'][0]['from_timestamp'] );
		$this->assertSame( '2026-04-02T11:30:00Z', $result['segments'][0]['to_timestamp'] );
		$this->assertSame( '2026-04-10T16:45:00Z', $result['segments'][1]['from_timestamp'] );
		$this->assertStringContainsString( DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '04' . DIRECTORY_SEPARATOR, $result['segments'][0]['target_path'] );
		$this->assertFileExists( $result['manifest_path'] );

		$manifest = json_decode( (string) file_get_contents( $result['manifest_path'] ), true );
		$this->assertSame( 'SHOW-42', $manifest['show_id'] );
		$this->assertSame( 2, $manifest['segment_count'] );
		$this->assertSame( '2026-04-01T09:15:00Z', $manifest['active_from'] );
		$this->assertSame( '2026-04-10T16:45:00Z', $manifest['active_to'] );
	}

	public function testHistoryReaderListsArchiveManifestsByShowAndDateWindow(): void {
		$vaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-vault-' . uniqid( '', true );

		$sink = new class() implements ArchiveSinkInterface {
			public function fetchHotRows( string $showId ): array {
				if ( 'SHOW-42' === $showId ) {
					return array(
						array(
							'id' => 1,
							'show_id' => $showId,
							'sku' => 'SKU-101',
							'timestamp' => '2026-04-01T09:15:00Z',
						),
					);
				}

				return array(
					array(
						'id' => 2,
						'show_id' => $showId,
						'sku' => 'SKU-202',
						'timestamp' => '2026-06-10T11:30:00Z',
					),
				);
			}

			public function truncateHotRows( string $showId ): void {
				unset( $showId );
			}
		};

		$writer = new class() implements ParquetWriterInterface {
			public function write( array $rows, string $targetPath ): void {
				file_put_contents( $targetPath, (string) wp_json_encode( $rows ) );
			}
		};

		$service = new ArchiveService( $sink, $writer, $vaultRoot );
		$service->archiveShow( 'SHOW-42', 2026 );
		$service->archiveShow( 'SHOW-77', 2026 );

		$reader = new FlowParquetHistoryReader();
		$manifests = $reader->listManifests(
			$vaultRoot,
			'SHOW-42',
			array(
				'to' => '2026-05-01T00:00:00Z',
			)
		);

		$this->assertCount( 1, $manifests );
		$this->assertSame( 'SHOW-42', $manifests[0]['show_id'] );
		$this->assertStringEndsWith( 'SHOW-42-archive-manifest.json', $manifests[0]['manifest_path'] );
	}

	public function testHistoryReaderCanFilterVaultRowsByDateRangeWhenParquetSupportIsAvailable(): void {
		if ( ! class_exists( '\\Flow\\Parquet\\Writer' ) || ! class_exists( '\\Flow\\Parquet\\Reader' ) ) {
			$this->markTestSkipped( 'flow-php/parquet is not available in this test environment.' );
		}

		$vaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-vault-' . uniqid( '', true );

		$sink = new class() implements ArchiveSinkInterface {
			public function fetchHotRows( string $showId ): array {
				return array(
					array(
						'id' => 1,
						'show_id' => $showId,
						'sku' => 'SKU-101',
						'timestamp' => '2026-04-01T09:15:00Z',
					),
					array(
						'id' => 2,
						'show_id' => $showId,
						'sku' => 'SKU-202',
						'timestamp' => '2026-05-15T11:30:00Z',
					),
				);
			}

			public function truncateHotRows( string $showId ): void {
				unset( $showId );
			}
		};

		$service = new ArchiveService( $sink, new FlowParquetArchiveWriter(), $vaultRoot );
		$service->archiveShow( 'SHOW-42', 2026 );

		$reader = new FlowParquetHistoryReader();
		$rows = iterator_to_array(
			$reader->readVault(
				$vaultRoot,
				'SHOW-42',
				array(
					'from' => '2026-05-01T00:00:00Z',
				)
			),
			false
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'SKU-202', $rows[0]['sku'] ?? '' );
	}

	public function testArchiveServiceManifestIncludesBinaryShadowMetadataWhenSinkProvidesIt(): void {
		$vaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-vault-' . uniqid( '', true );

		$sink = new class() implements ArchiveSinkInterface {
			public function fetchHotRows( string $showId ): array {
				return array(
					array(
						'id' => 1,
						'show_id' => $showId,
						'sku' => 'SKU-101',
						'timestamp' => '2026-04-01T09:15:00Z',
					),
				);
			}

			public function truncateHotRows( string $showId ): void {
				unset( $showId );
			}

			public function binaryShadowArchiveSummary( string $showId ): array {
				return array(
					'show_id' => $showId,
					'pointer_count' => 3,
					'exception_count' => 1,
					'segments' => array( 'sales-shadow-20260411.bin' ),
				);
			}
		};

		$writer = new class() implements ParquetWriterInterface {
			public function write( array $rows, string $targetPath ): void {
				file_put_contents( $targetPath, (string) wp_json_encode( $rows ) );
			}
		};

		$service = new ArchiveService( $sink, $writer, $vaultRoot );
		$result = $service->archiveShow( 'SHOW-42', 2026 );

		$this->assertSame( 3, $result['binary_shadow']['pointer_count'] );
		$this->assertSame( 1, $result['binary_shadow']['exception_count'] );

		$manifest = json_decode( (string) file_get_contents( $result['manifest_path'] ), true );
		$this->assertSame( 3, $manifest['binary_shadow']['pointer_count'] );
		$this->assertSame( array( 'sales-shadow-20260411.bin' ), $manifest['binary_shadow']['segments'] );
	}

	public function testArchiveServiceManifestIncludesRetentionMetadataFromOptions(): void {
		$vaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-vault-' . uniqid( '', true );

		$sink = new class() implements ArchiveSinkInterface {
			public function fetchHotRows( string $showId ): array {
				return array(
					array(
						'id' => 1,
						'show_id' => $showId,
						'sku' => 'SKU-101',
						'timestamp' => '2026-04-01T09:15:00Z',
					),
				);
			}

			public function truncateHotRows( string $showId ): void {
				unset( $showId );
			}
		};

		$writer = new class() implements ParquetWriterInterface {
			public function write( array $rows, string $targetPath ): void {
				file_put_contents( $targetPath, (string) wp_json_encode( $rows ) );
			}
		};

		$service = new ArchiveService(
			$sink,
			$writer,
			$vaultRoot,
			array(
				'hot_retention_days' => 30,
				'vault_retention_days' => 365,
			)
		);

		$result = $service->archiveShow( 'SHOW-42', 2026 );
		$manifest = json_decode( (string) file_get_contents( $result['manifest_path'] ), true );

		$this->assertSame( 30, $result['retention']['hot_retention_days'] );
		$this->assertSame( 365, $result['retention']['vault_retention_days'] );
		$this->assertSame( 30, $manifest['retention']['hot_retention_days'] );
		$this->assertSame( 365, $manifest['retention']['vault_retention_days'] );
	}
}
