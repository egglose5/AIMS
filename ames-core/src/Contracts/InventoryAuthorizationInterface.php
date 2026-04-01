<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface InventoryAuthorizationInterface {
	public function canManageVendorInventory( int $actorUserId, int $vendorId ): bool;
}
