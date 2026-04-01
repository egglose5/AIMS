<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface MovementPolicyInterface {
	public function isAllowedMovement( string $movementType ): bool;

	public function isAllowedReferenceForMovement( string $movementType, string $referenceType ): bool;
}
