<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface PersonIdentityInterface {
	public function isAimsPerson( int $actorUserId ): bool;
}
