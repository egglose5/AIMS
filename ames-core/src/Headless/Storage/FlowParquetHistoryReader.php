<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Storage;

final class FlowParquetHistoryReader {
	/**
	 * @param array<string, mixed> $filters
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function readVault( string $vaultRoot, string $showId = '', array $filters = array() ): \Generator {
		if ( ! is_dir( $vaultRoot ) ) {
			return;
		}

		if ( ! class_exists( '\Flow\Parquet\Reader' ) ) {
			throw new \RuntimeException( 'flow-php/parquet is required to read archived AIMS history.' );
		}

		$readerClass = '\Flow\Parquet\Reader';
		$reader      = method_exists( $readerClass, 'php' ) ? $readerClass::php() : new $readerClass();
		$limit       = $this->resolveLimit( $filters );
		$yielded     = 0;

		foreach ( $this->collectFilesByExtension( $vaultRoot, 'parquet' ) as $file ) {
			if ( '' !== $showId && ! $this->matchesShowId( $file, $showId ) ) {
				continue;
			}

			$parquetFile = $reader->read( $file->getPathname() );

			foreach ( $parquetFile->values() as $row ) {
				if ( ! is_array( $row ) || ! $this->rowMatchesFilters( $row, $showId, $filters ) ) {
					continue;
				}

				yield $row;
				++$yielded;

				if ( $limit > 0 && $yielded >= $limit ) {
					return;
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function listManifests( string $vaultRoot, string $showId = '', array $filters = array() ): array {
		if ( ! is_dir( $vaultRoot ) ) {
			return array();
		}

		$manifests = array();
		$limit     = $this->resolveLimit( $filters );

		foreach ( $this->collectFilesByExtension( $vaultRoot, 'json' ) as $file ) {
			if ( ! str_ends_with( $file->getBasename(), '-archive-manifest.json' ) ) {
				continue;
			}

			$manifest = json_decode( (string) file_get_contents( $file->getPathname() ), true );
			if ( ! is_array( $manifest ) || ! $this->manifestMatchesFilters( $manifest, $showId, $filters ) ) {
				continue;
			}

			$manifest['manifest_path'] = $file->getPathname();
			$manifests[]              = $manifest;
		}

		usort(
			$manifests,
			fn( array $left, array $right ): int => ( $this->timestampValue( (string) ( $left['active_from'] ?? $left['exported_at'] ?? '' ) ) <=> $this->timestampValue( (string) ( $right['active_from'] ?? $right['exported_at'] ?? '' ) ) )
		);

		if ( $limit > 0 ) {
			return array_slice( $manifests, 0, $limit );
		}

		return $manifests;
	}

	private function matchesShowId( \SplFileInfo $file, string $showId ): bool {
		$showId   = trim( $showId );
		$basename = preg_replace( '/\.parquet$|\.json$/i', '', $file->getBasename() );
		if ( $basename === $showId || str_starts_with( (string) $basename, $showId . '-' ) ) {
			return true;
		}

		return str_contains( str_replace( '\\', '/', $file->getPathname() ), '/' . $showId . '/' );
	}

	/**
	 * @return array<int, \SplFileInfo>
	 */
	private function collectFilesByExtension( string $root, string $extension ): array {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$files    = array();

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && strtolower( $file->getExtension() ) === strtolower( $extension ) ) {
				$files[] = $file;
			}
		}

		usort(
			$files,
			fn( \SplFileInfo $left, \SplFileInfo $right ): int => strcmp( $left->getPathname(), $right->getPathname() )
		);

		return $files;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $filters
	 */
	private function rowMatchesFilters( array $row, string $showId, array $filters ): bool {
		$rowShowId = trim( (string) ( $row['show_id'] ?? '' ) );
		if ( '' !== $showId && '' !== $rowShowId && $rowShowId !== $showId ) {
			return false;
		}

		return $this->timestampWithinWindow(
			(string) ( $row['timestamp'] ?? $row['created_at'] ?? $row['updated_at'] ?? '' ),
			$filters
		);
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @param array<string, mixed> $filters
	 */
	private function manifestMatchesFilters( array $manifest, string $showId, array $filters ): bool {
		$manifestShowId = trim( (string) ( $manifest['show_id'] ?? '' ) );
		if ( '' !== $showId && $manifestShowId !== $showId ) {
			return false;
		}

		$from = $this->resolveFilterTimestamp( $filters, array( 'from', 'from_timestamp' ) );
		$to   = $this->resolveFilterTimestamp( $filters, array( 'to', 'to_timestamp' ) );

		$activeFrom = $this->timestampValue( (string) ( $manifest['active_from'] ?? '' ) );
		$activeTo   = $this->timestampValue( (string) ( $manifest['active_to'] ?? '' ) );

		if ( $from > 0 && $activeTo > 0 && $activeTo < $from ) {
			return false;
		}

		if ( $to > 0 && $activeFrom > 0 && $activeFrom > $to ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function timestampWithinWindow( string $value, array $filters ): bool {
		$timestamp = $this->timestampValue( $value );
		if ( $timestamp <= 0 ) {
			return true;
		}

		$from = $this->resolveFilterTimestamp( $filters, array( 'from', 'from_timestamp' ) );
		$to   = $this->resolveFilterTimestamp( $filters, array( 'to', 'to_timestamp' ) );

		if ( $from > 0 && $timestamp < $from ) {
			return false;
		}

		if ( $to > 0 && $timestamp > $to ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @param array<int, string> $keys
	 */
	private function resolveFilterTimestamp( array $filters, array $keys ): int {
		foreach ( $keys as $key ) {
			$value = trim( (string) ( $filters[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $this->timestampValue( $value );
			}
		}

		return 0;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function resolveLimit( array $filters ): int {
		$limit = isset( $filters['limit'] ) ? (int) $filters['limit'] : 0;
		return $limit > 0 ? $limit : 0;
	}

	private function timestampValue( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? 0 : (int) $timestamp;
	}
}
