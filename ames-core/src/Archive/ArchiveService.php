<?php

declare( strict_types=1 );

namespace AmesCore\Archive;

final class ArchiveService {
	private ArchiveSinkInterface $sink;
	private ParquetWriterInterface $writer;
	private string $vaultRoot;
	/** @var array<string, mixed> */
	private array $options;

	/**
	 * @param array<string, mixed> $options
	 */
	public function __construct( ArchiveSinkInterface $sink, ParquetWriterInterface $writer, string $vaultRoot, array $options = array() ) {
		$this->sink      = $sink;
		$this->writer    = $writer;
		$this->vaultRoot = rtrim( $vaultRoot, DIRECTORY_SEPARATOR );
		$this->options   = array_merge(
			array(
				'max_rows_per_file' => 25000,
			),
			$options
		);
	}

	/**
	 * Archive the current hot rows for a show into one or more year/month buckets.
	 *
	 * @return array<string, mixed>
	 */
	public function archiveShow( string $showId, int $year ): array {
		$showId = $this->normalizeSegment( $showId );
		$rows   = $this->sortRowsByTimestamp( $this->sink->fetchHotRows( $showId ) );

		if ( array() === $rows ) {
			return array(
				'show_id'       => $showId,
				'year'          => $year,
				'target_path'   => '',
				'row_count'     => 0,
				'segment_count' => 0,
				'segments'      => array(),
				'manifest_path' => '',
			);
		}

		$chunks        = $this->chunkRows( $rows );
		$totalSegments = count( $chunks );
		$segments      = array();

		foreach ( $chunks as $index => $chunk ) {
			$target = $this->buildTargetPath( $year, $showId, $chunk, $index + 1, $totalSegments );
			$this->ensureDirectory( dirname( $target ) );
			$this->writer->write( $chunk, $target );

			$range      = $this->resolveDateRange( $chunk );
			$segments[] = array(
				'segment_index'  => $index + 1,
				'target_path'    => $target,
				'row_count'      => count( $chunk ),
				'from_timestamp' => $range['from'],
				'to_timestamp'   => $range['to'],
				'segment_month'  => $this->resolveSegmentMonth( $chunk, $year ),
			);
		}

		$manifestPath = $this->writeManifest( $showId, $year, $rows, $segments );
		$this->sink->truncateHotRows( $showId );

		return array(
			'show_id'       => $showId,
			'year'          => $year,
			'target_path'   => (string) ( $segments[0]['target_path'] ?? '' ),
			'row_count'     => count( $rows ),
			'segment_count' => count( $segments ),
			'segments'      => $segments,
			'active_from'   => (string) ( $segments[0]['from_timestamp'] ?? '' ),
			'active_to'     => (string) ( $segments[ count( $segments ) - 1 ]['to_timestamp'] ?? '' ),
			'manifest_path' => $manifestPath,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function chunkRows( array $rows ): array {
		$maxRows = max( 1, (int) ( $this->options['max_rows_per_file'] ?? 25000 ) );
		$grouped = array();

		foreach ( $rows as $row ) {
			$month = $this->resolveSegmentMonth( array( $row ), (int) gmdate( 'Y' ) );
			if ( ! isset( $grouped[ $month ] ) ) {
				$grouped[ $month ] = array();
			}

			$grouped[ $month ][] = $row;
		}

		$chunks = array();
		foreach ( $grouped as $monthRows ) {
			foreach ( array_chunk( $monthRows, $maxRows ) as $chunk ) {
				$chunks[] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function buildTargetPath( int $year, string $showId, array $rows, int $segmentIndex, int $totalSegments ): string {
		if ( $totalSegments <= 1 ) {
			return $this->vaultRoot . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $showId . '.parquet';
		}

		$range    = $this->resolveDateRange( $rows );
		$month    = $this->resolveSegmentMonth( $rows, $year );
		$fromSlug = $this->timestampSlug( $range['from'] );
		$toSlug   = $this->timestampSlug( $range['to'] );

		return $this->vaultRoot
			. DIRECTORY_SEPARATOR . $year
			. DIRECTORY_SEPARATOR . $month
			. DIRECTORY_SEPARATOR . $showId . '-' . $fromSlug . '-to-' . $toSlug . '-part' . str_pad( (string) $segmentIndex, 3, '0', STR_PAD_LEFT ) . '.parquet';
	}

	private function ensureDirectory( string $path ): void {
		if ( is_dir( $path ) ) {
			return;
		}

		if ( ! @mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
			throw new \RuntimeException( 'Unable to create archive directory: ' . $path );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<string, string>
	 */
	private function resolveDateRange( array $rows ): array {
		$timestamps = array();

		foreach ( $rows as $row ) {
			$value = trim( (string) ( $row['timestamp'] ?? $row['created_at'] ?? $row['updated_at'] ?? '' ) );
			if ( '' !== $value ) {
				$timestamps[] = $value;
			}
		}

		if ( array() === $timestamps ) {
			return array( 'from' => '', 'to' => '' );
		}

		usort(
			$timestamps,
			fn( string $left, string $right ): int => ( $this->timestampValue( $left ) <=> $this->timestampValue( $right ) )
		);

		return array(
			'from' => (string) $timestamps[0],
			'to'   => (string) $timestamps[ count( $timestamps ) - 1 ],
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function resolveSegmentMonth( array $rows, int $fallbackYear ): string {
		$range = $this->resolveDateRange( $rows );
		if ( '' === $range['from'] ) {
			return 'unknown-' . $fallbackYear;
		}

		$timestamp = $this->timestampValue( $range['from'] );
		if ( $timestamp <= 0 ) {
			return substr( preg_replace( '/[^0-9]/', '', $range['from'] ), 0, 6 ) ?: 'unknown-' . $fallbackYear;
		}

		return gmdate( 'm', $timestamp );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, array<string, mixed>> $segments
	 */
	private function writeManifest( string $showId, int $year, array $rows, array $segments ): string {
		$range        = $this->resolveDateRange( $rows );
		$manifestPath = $this->vaultRoot . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $showId . '-archive-manifest.json';
		$this->ensureDirectory( dirname( $manifestPath ) );

		file_put_contents(
			$manifestPath,
			(string) json_encode(
				array(
					'show_id'           => $showId,
					'year'              => $year,
					'row_count'         => count( $rows ),
					'segment_count'     => count( $segments ),
					'active_from'       => $range['from'],
					'active_to'         => $range['to'],
					'max_rows_per_file' => max( 1, (int) ( $this->options['max_rows_per_file'] ?? 25000 ) ),
					'exported_at'       => gmdate( 'c' ),
					'segments'          => $segments,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);

		return $manifestPath;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function sortRowsByTimestamp( array $rows ): array {
		usort(
			$rows,
			fn( array $left, array $right ): int => ( $this->timestampValue( (string) ( $left['timestamp'] ?? $left['created_at'] ?? $left['updated_at'] ?? '' ) ) <=> $this->timestampValue( (string) ( $right['timestamp'] ?? $right['created_at'] ?? $right['updated_at'] ?? '' ) ) )
		);

		return $rows;
	}

	private function timestampSlug( string $value ): string {
		if ( '' === $value ) {
			return 'undated';
		}

		$timestamp = $this->timestampValue( $value );
		if ( $timestamp <= 0 ) {
			$slug = preg_replace( '/[^0-9]/', '', $value );
			return '' !== (string) $slug ? (string) $slug : 'undated';
		}

		return gmdate( 'Ymd\THis\Z', $timestamp );
	}

	private function timestampValue( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? 0 : (int) $timestamp;
	}

	private function normalizeSegment( string $value ): string {
		$value = trim( $value );
		$value = preg_replace( '/[^A-Za-z0-9_\-]/', '', $value );

		if ( '' === $value ) {
			throw new \InvalidArgumentException( 'Archive show identifier must not be empty.' );
		}

		return $value;
	}
}
