<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface PositionRepositoryInterface {
	public function supportsSynchronization(): bool;

	public function synchronizeFromMovements( int $bucketId, int $vendorId, int $productId ): void;

	public function upsertPosition( array $data ): void;
}
