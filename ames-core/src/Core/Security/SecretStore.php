<?php

declare( strict_types=1 );

namespace AmesCore\Core\Security;

final class SecretStore {
	private string $storePath;
	private CryptographerInterface $cryptographer;

	public function __construct( string $storePath, CryptographerInterface $cryptographer ) {
		$this->storePath      = $storePath;
		$this->cryptographer  = $cryptographer;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getProvider( string $provider ): array {
		$provider = $this->normalizeProvider( $provider );
		if ( '' === $provider ) {
			return array();
		}

		$store = $this->readStore();
		if ( empty( $store['providers'][ $provider ] ) || ! is_array( $store['providers'][ $provider ] ) ) {
			return array();
		}

		return $this->cryptographer->decrypt( $store['providers'][ $provider ] );
	}

	/**
	 * @param array<string, mixed> $secrets
	 */
	public function putProvider( string $provider, array $secrets ): void {
		$provider = $this->normalizeProvider( $provider );
		if ( '' === $provider ) {
			throw new \InvalidArgumentException( 'Secret provider must not be empty.' );
		}

		$store                     = $this->readStore();
		$store['providers']        = is_array( $store['providers'] ?? null ) ? $store['providers'] : array();
		$store['providers'][ $provider ] = $this->cryptographer->encrypt( $secrets );
		$store['updated_at']       = gmdate( 'c' );

		$this->writeStore( $store );
	}

	/**
	 * @param array<string, mixed> $secrets
	 */
	public function mergeProvider( string $provider, array $secrets ): array {
		$current = $this->getProvider( $provider );
		$merged  = array_merge( $current, $secrets );
		$this->putProvider( $provider, $merged );

		return $merged;
	}

	public function forgetProvider( string $provider ): void {
		$provider = $this->normalizeProvider( $provider );
		if ( '' === $provider ) {
			return;
		}

		$store = $this->readStore();
		unset( $store['providers'][ $provider ] );
		$store['updated_at'] = gmdate( 'c' );
		$this->writeStore( $store );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readStore(): array {
		if ( ! file_exists( $this->storePath ) ) {
			return array(
				'version'    => 1,
				'updated_at' => null,
				'providers'  => array(),
			);
		}

		$raw = file_get_contents( $this->storePath );
		if ( false === $raw || '' === trim( $raw ) ) {
			return array(
				'version'    => 1,
				'updated_at' => null,
				'providers'  => array(),
			);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Secret store file is not valid JSON.' );
		}

		$decoded['providers'] = is_array( $decoded['providers'] ?? null ) ? $decoded['providers'] : array();

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $store
	 */
	private function writeStore( array $store ): void {
		$directory = dirname( $this->storePath );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( 'Unable to create secret store directory.' );
		}

		$encoded = json_encode( $store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		file_put_contents( $this->storePath, $encoded, LOCK_EX );
	}

	private function normalizeProvider( string $provider ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', trim( $provider ) ) );
	}
}
