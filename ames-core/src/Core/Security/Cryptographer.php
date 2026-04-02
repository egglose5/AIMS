<?php

declare( strict_types=1 );

namespace AmesCore\Core\Security;

final class Cryptographer implements CryptographerInterface {
	private const CIPHER = 'aes-256-gcm';

	private string $keyMaterial;

	public function __construct( string $keyMaterial ) {
		$this->keyMaterial = trim( $keyMaterial );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	public function encrypt( array $payload ): array {
		$this->assertAvailable();

		$plaintext = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		$nonce     = random_bytes( 12 );
		$tag       = '';
		$cipher    = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->normalizedKey(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag
		);

		if ( false === $cipher || '' === $tag ) {
			throw new \RuntimeException( 'Failed to encrypt adapter secrets.' );
		}

		return array(
			'alg'        => self::CIPHER,
			'nonce'      => base64_encode( $nonce ),
			'tag'        => base64_encode( $tag ),
			'ciphertext' => base64_encode( $cipher ),
		);
	}

	/**
	 * @param array<string, string> $payload
	 * @return array<string, mixed>
	 */
	public function decrypt( array $payload ): array {
		$this->assertAvailable();

		$ciphertext = base64_decode( (string) ( $payload['ciphertext'] ?? '' ), true );
		$nonce      = base64_decode( (string) ( $payload['nonce'] ?? '' ), true );
		$tag        = base64_decode( (string) ( $payload['tag'] ?? '' ), true );

		if ( false === $ciphertext || false === $nonce || false === $tag ) {
			throw new \InvalidArgumentException( 'Encrypted payload is malformed.' );
		}

		$plaintext = openssl_decrypt(
			$ciphertext,
			(string) ( $payload['alg'] ?? self::CIPHER ),
			$this->normalizedKey(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag
		);

		if ( false === $plaintext ) {
			throw new \RuntimeException( 'Failed to decrypt adapter secrets.' );
		}

		$decoded = json_decode( $plaintext, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Decrypted payload is not valid JSON.' );
		}

		return $decoded;
	}

	private function assertAvailable(): void {
		if ( '' === $this->keyMaterial ) {
			throw new \RuntimeException( 'AIMS_ENCRYPTION_KEY is required for encrypted adapter secrets.' );
		}

		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			throw new \RuntimeException( 'The openssl extension is required for AES-256-GCM secret encryption.' );
		}
	}

	private function normalizedKey(): string {
		return hash( 'sha256', $this->keyMaterial, true );
	}
}
