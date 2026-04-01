<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface MovementRepositoryInterface {
	public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool;

	public function create( array $data ): int;

	public function getBalanceForBucketProduct( int $bucketId, int $vendorId, int $productId ): float;
}
