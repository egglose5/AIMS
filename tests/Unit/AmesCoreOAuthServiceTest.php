<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Core\OAuth\OAuthService;
use AmesCore\Core\Security\SecretStore;
use AIMS\Tests\Support\FakeCryptographer;
use AIMS\Tests\Support\FakeHttpTransport;

final class AmesCoreOAuthServiceTest extends \AIMS\Tests\TestCase {
	public function testOAuthFlowStoresTokensInEncryptedSecretStore(): void {
		$storePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-oauth-store-' . uniqid( '', true ) . '.json';
		$store     = new SecretStore( $storePath, new FakeCryptographer() );
		$transport = new FakeHttpTransport(
			array(
				'/oauth2/token' => array(
					'success' => true,
					'status'  => 200,
					'json'    => array(
						'access_token'  => 'stored-access-token',
						'refresh_token' => 'stored-refresh-token',
						'token_type'    => 'bearer',
						'expires_in'    => 3600,
						'merchant_id'   => 'merchant-1',
					),
				),
			)
		);

		$service = new OAuthService( $store, $transport );
		$begin   = $service->beginAuthorization(
			'square',
			array(
				'client_id'         => 'square-client',
				'client_secret'     => 'square-secret',
				'redirect_uri'      => 'https://example.test/oauth/square/callback',
				'authorization_url' => 'https://connect.squareup.com/oauth2/authorize',
				'token_url'         => 'https://connect.squareup.com/oauth2/token',
				'scope'             => array( 'ITEMS_READ', 'INVENTORY_WRITE' ),
			)
		);

		$this->assertStringContainsString( 'state=', $begin['authorize_url'] );
		$this->assertSame( 'square-secret', $store->getProvider( 'square' )['client_secret'] );

		$status = $service->exchangeAuthorizationCode(
			'square',
			array(
				'code'  => 'auth-code-123',
				'state' => $begin['state'],
			)
		);

		$this->assertTrue( $status['configured'] );
		$this->assertTrue( $status['has_access_token'] );
		$this->assertTrue( $status['has_refresh_token'] );

		$stored = $store->getProvider( 'square' );
		$this->assertSame( 'stored-access-token', $stored['access_token'] );
		$this->assertSame( 'stored-refresh-token', $stored['refresh_token'] );
		$this->assertSame( 'square-secret', $stored['client_secret'] );
	}
}
