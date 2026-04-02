<?php

declare( strict_types=1 );

namespace AmesCore\Core\Security;

interface CryptographerInterface {
	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	public function encrypt( array $payload ): array;

	/**
	 * @param array<string, string> $payload
	 * @return array<string, mixed>
	 */
	public function decrypt( array $payload ): array;
}
