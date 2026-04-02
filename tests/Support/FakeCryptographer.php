<?php

declare( strict_types=1 );

namespace AIMS\Tests\Support;

use AmesCore\Core\Security\CryptographerInterface;

final class FakeCryptographer implements CryptographerInterface {
	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	public function encrypt( array $payload ): array {
		return array(
			'alg'        => 'fake',
			'nonce'      => 'fake-nonce',
			'tag'        => 'fake-tag',
			'ciphertext' => base64_encode( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	/**
	 * @param array<string, string> $payload
	 * @return array<string, mixed>
	 */
	public function decrypt( array $payload ): array {
		$decoded = json_decode( base64_decode( (string) ( $payload['ciphertext'] ?? '' ) ) ?: '', true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
