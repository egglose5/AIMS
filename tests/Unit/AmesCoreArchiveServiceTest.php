<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Archive\ArchiveService;
use AmesCore\Archive\ArchiveSinkInterface;
use AmesCore\Archive\ParquetWriterInterface;

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
}
