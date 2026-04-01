<?php

declare( strict_types=1 );

namespace AmesCore\Archive;

interface ArchiveSinkInterface {
	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchHotRows( string $showId ): array;

	public function truncateHotRows( string $showId ): void;
}
