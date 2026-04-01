<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Storage;

final class FlowParquetHistoryReader {
	/**
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function readVault( string $vaultRoot, string $showId = '' ): \Generator {
		if ( ! is_dir( $vaultRoot ) ) {
			return;
		}

		if ( ! class_exists( '\Flow\Parquet\Reader' ) ) {
			throw new \RuntimeException( 'flow-php/parquet is required to read archived AIMS history.' );
		}

		$readerClass = '\Flow\Parquet\Reader';
		$reader      = method_exists( $readerClass, 'php' ) ? $readerClass::php() : new $readerClass();
		$iterator    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $vaultRoot, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'parquet' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			if ( '' !== $showId && $file->getBasename( '.parquet' ) !== $showId ) {
				continue;
			}

			$parquetFile = $reader->read( $file->getPathname() );

			foreach ( $parquetFile->values() as $row ) {
				if ( is_array( $row ) ) {
					yield $row;
				}
			}
		}
	}
}
