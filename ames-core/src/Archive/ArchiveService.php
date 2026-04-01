<?php

declare( strict_types=1 );

namespace AmesCore\Archive;

final class ArchiveService {
	private ArchiveSinkInterface $sink;
	private ParquetWriterInterface $writer;
	private string $vaultRoot;

	public function __construct( ArchiveSinkInterface $sink, ParquetWriterInterface $writer, string $vaultRoot ) {
		$this->sink      = $sink;
		$this->writer    = $writer;
		$this->vaultRoot = rtrim( $vaultRoot, DIRECTORY_SEPARATOR );
	}

	/**
	 * Archive the current hot rows for a show into a year bucket.
	 *
	 * @return array<string, mixed>
	 */
	public function archiveShow( string $showId, int $year ): array {
		$showId = $this->normalizeSegment( $showId );
		$rows   = $this->sink->fetchHotRows( $showId );
		$target = $this->buildTargetPath( $year, $showId );

		$this->ensureDirectory( dirname( $target ) );
		$this->writer->write( $rows, $target );
		$this->sink->truncateHotRows( $showId );

		return array(
			'show_id'     => $showId,
			'year'        => $year,
			'target_path' => $target,
			'row_count'   => count( $rows ),
		);
	}

	private function buildTargetPath( int $year, string $showId ): string {
		return $this->vaultRoot . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $showId . '.parquet';
	}

	private function ensureDirectory( string $path ): void {
		if ( is_dir( $path ) ) {
			return;
		}

		if ( ! @mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
			throw new \RuntimeException( 'Unable to create archive directory: ' . $path );
		}
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
