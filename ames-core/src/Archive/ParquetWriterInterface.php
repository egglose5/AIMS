<?php

declare( strict_types=1 );

namespace AmesCore\Archive;

interface ParquetWriterInterface {
	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	public function write( array $rows, string $targetPath ): void;
}
