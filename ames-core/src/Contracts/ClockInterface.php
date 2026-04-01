<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface ClockInterface {
	public function now(): string;
}
