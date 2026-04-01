<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Security;

final class TokenAuthenticator {
	private string $sharedSecret;
	private string $archiveSecret;

	public function __construct( string $sharedSecret, string $archiveSecret ) {
		$this->sharedSecret  = trim( $sharedSecret );
		$this->archiveSecret = trim( $archiveSecret );
	}

	/**
	 * @param array<string, mixed> $server
	 * @param array<string, mixed> $query
	 */
	public function assertAuthorized( array $server, array $query, bool $allowArchiveQuerySecret ): void {
		if ( '' === $this->sharedSecret ) {
			throw new \RuntimeException( 'AIMS_SHARED_SECRET is not configured.' );
		}

		$providedToken = $this->headerValue( $server, 'X-Ames-Token' );
		if ( '' !== $providedToken && hash_equals( $this->sharedSecret, $providedToken ) ) {
			return;
		}

		if ( $allowArchiveQuerySecret ) {
			$providedSecret = trim( (string) ( $query['secret'] ?? '' ) );
			$expectedSecret = '' !== $this->archiveSecret ? $this->archiveSecret : $this->sharedSecret;

			if ( '' !== $providedSecret && hash_equals( $expectedSecret, $providedSecret ) ) {
				return;
			}
		}

		throw new \InvalidArgumentException( 'Unauthorized request. Missing or invalid X-Ames-Token.' );
	}

	/**
	 * @param array<string, mixed> $server
	 */
	private function headerValue( array $server, string $name ): string {
		$keys = array(
			'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ),
			strtoupper( str_replace( '-', '_', $name ) ),
			$name,
		);

		foreach ( $keys as $key ) {
			if ( isset( $server[ $key ] ) && ! is_array( $server[ $key ] ) ) {
				return trim( (string) $server[ $key ] );
			}
		}

		return '';
	}
}
