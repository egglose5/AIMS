<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface MovementLifecycleInterface {
	public function ensureHotBatch( array $data ): array;

	public function captureHotLine( int $batchId, int $movementId, array $data ): bool;
}
