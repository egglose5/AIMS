<?php

declare( strict_types=1 );

namespace AmesCore\Core\OAuth;

use AIMS\Core\Clients\HttpTransportInterface;
use AIMS\Core\Clients\NativeHttpTransport;
use AmesCore\Core\Security\SecretStore;

final class OAuthService {
	private SecretStore $secretStore;
	private HttpTransportInterface $transport;

	public function __construct( SecretStore $secretStore, ?HttpTransportInterface $transport = null ) {
		$this->secretStore = $secretStore;
		$this->transport   = $transport ?: new NativeHttpTransport();
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function beginAuthorization( string $provider, array $payload ): array {
		$provider         = $this->normalizeProvider( $provider );
		$clientId         = trim( (string) ( $payload['client_id'] ?? '' ) );
		$clientSecret     = trim( (string) ( $payload['client_secret'] ?? '' ) );
		$redirectUri      = trim( (string) ( $payload['redirect_uri'] ?? '' ) );
		$authorizationUrl = trim( (string) ( $payload['authorization_url'] ?? $this->defaultAuthorizationUrl( $provider ) ) );
		$tokenUrl         = trim( (string) ( $payload['token_url'] ?? $this->defaultTokenUrl( $provider ) ) );
		$scope            = $this->normalizeScope( $payload['scope'] ?? $payload['scopes'] ?? array() );
		$state            = $this->uuid();

		if ( '' === $provider || '' === $clientId || '' === $clientSecret || '' === $redirectUri || '' === $authorizationUrl || '' === $tokenUrl ) {
			throw new \InvalidArgumentException( 'OAuth authorization requires provider, client_id, client_secret, redirect_uri, authorization_url, and token_url.' );
		}

		$this->secretStore->putProvider(
			$provider,
			array(
				'provider'           => $provider,
				'client_id'          => $clientId,
				'client_secret'      => $clientSecret,
				'authorization_url'  => $authorizationUrl,
				'token_url'          => $tokenUrl,
				'redirect_uri'       => $redirectUri,
				'scope'              => $scope,
				'state'              => $state,
				'state_issued_at'    => gmdate( 'c' ),
			)
		);

		$query = array(
			'response_type' => 'code',
			'client_id'     => $clientId,
			'redirect_uri'  => $redirectUri,
			'state'         => $state,
		);

		if ( '' !== $scope ) {
			$query['scope'] = $scope;
		}

		if ( isset( $payload['extra_authorize_params'] ) && is_array( $payload['extra_authorize_params'] ) ) {
			$query = array_merge( $query, $payload['extra_authorize_params'] );
		}

		return array(
			'provider'      => $provider,
			'authorize_url' => $authorizationUrl . ( str_contains( $authorizationUrl, '?' ) ? '&' : '?' ) . http_build_query( $query ),
			'state'         => $state,
			'redirect_uri'  => $redirectUri,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function exchangeAuthorizationCode( string $provider, array $payload ): array {
		$provider = $this->normalizeProvider( $provider );
		$code     = trim( (string) ( $payload['code'] ?? '' ) );
		$state    = trim( (string) ( $payload['state'] ?? '' ) );
		$current  = $this->secretStore->getProvider( $provider );

		if ( '' === $provider || '' === $code || '' === $state ) {
			throw new \InvalidArgumentException( 'OAuth callback requires provider, code, and state.' );
		}

		if ( empty( $current ) ) {
			throw new \RuntimeException( 'No OAuth bootstrap data found for provider.' );
		}

		if ( ! hash_equals( (string) ( $current['state'] ?? '' ), $state ) ) {
			throw new \InvalidArgumentException( 'OAuth state validation failed.' );
		}

		$response = $this->transport->send(
			'POST',
			(string) $current['token_url'],
			array(
				'headers' => array(
					'accept'       => 'application/json',
					'content-type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'authorization_code',
						'client_id'     => (string) $current['client_id'],
						'client_secret' => (string) $current['client_secret'],
						'code'          => $code,
						'redirect_uri'  => (string) $current['redirect_uri'],
					)
				),
			)
		);

		if ( empty( $response['success'] ) || ! is_array( $response['json'] ?? null ) ) {
			throw new \RuntimeException( 'OAuth token exchange failed.' );
		}

		$tokenPayload = (array) $response['json'];
		$merged       = $current;
		$merged['access_token']  = (string) ( $tokenPayload['access_token'] ?? '' );
		$merged['refresh_token'] = (string) ( $tokenPayload['refresh_token'] ?? '' );
		$merged['token_type']    = (string) ( $tokenPayload['token_type'] ?? '' );
		$merged['scope']         = trim( (string) ( $tokenPayload['scope'] ?? (string) ( $current['scope'] ?? '' ) ) );
		$merged['token_acquired_at'] = gmdate( 'c' );

		if ( isset( $tokenPayload['expires_in'] ) && is_numeric( $tokenPayload['expires_in'] ) ) {
			$merged['expires_at'] = gmdate( 'c', time() + (int) $tokenPayload['expires_in'] );
		}

		if ( isset( $tokenPayload['merchant_id'] ) ) {
			$merged['merchant_id'] = (string) $tokenPayload['merchant_id'];
		}

		unset( $merged['state'], $merged['state_issued_at'] );
		$this->secretStore->putProvider( $provider, $merged );

		return $this->status( $provider );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status( string $provider ): array {
		$current = $this->secretStore->getProvider( $provider );

		return array(
			'provider'             => $this->normalizeProvider( $provider ),
			'configured'           => ! empty( $current['client_id'] ),
			'has_access_token'     => ! empty( $current['access_token'] ),
			'has_refresh_token'    => ! empty( $current['refresh_token'] ),
			'expires_at'           => $current['expires_at'] ?? null,
			'redirect_uri'         => $current['redirect_uri'] ?? null,
			'token_acquired_at'    => $current['token_acquired_at'] ?? null,
		);
	}

	private function normalizeProvider( string $provider ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', trim( $provider ) ) );
	}

	private function defaultAuthorizationUrl( string $provider ): string {
		return 'square' === $provider ? 'https://connect.squareup.com/oauth2/authorize' : '';
	}

	private function defaultTokenUrl( string $provider ): string {
		return 'square' === $provider ? 'https://connect.squareup.com/oauth2/token' : '';
	}

	private function normalizeScope( mixed $scope ): string {
		if ( is_array( $scope ) ) {
			return trim( implode( ' ', array_filter( array_map( static fn( mixed $value ): string => trim( (string) $value ), $scope ) ) ) );
		}

		return trim( (string) $scope );
	}

	private function uuid(): string {
		$bytes = random_bytes( 16 );
		$hex   = bin2hex( $bytes );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
