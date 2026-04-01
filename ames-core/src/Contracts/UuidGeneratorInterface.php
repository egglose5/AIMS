<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface UuidGeneratorInterface {
	public function generate(): string;
}
