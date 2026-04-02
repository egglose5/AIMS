<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Core\Security\SecretStore;
use AIMS\Tests\Support\FakeCryptographer;

final class AmesCoreSecretStoreTest extends \AIMS\Tests\TestCase {
	public function testSecretStorePersistsEncryptedProviderBundles(): void {
		$storePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-secret-store-' . uniqid( '', true ) . '.json';
		$store     = new SecretStore( $storePath, new FakeCryptographer() );

		$store->putProvider(
			'square',
			array(
				'client_id'     => 'sq-client',
				'client_secret' => 'sq-secret',
				'access_token'  => 'access-123',
			)
		);

		$this->assertSame(
			array(
				'client_id'     => 'sq-client',
				'client_secret' => 'sq-secret',
				'access_token'  => 'access-123',
			),
			$store->getProvider( 'square' )
		);

		$raw = json_decode( (string) file_get_contents( $storePath ), true );
		$this->assertIsArray( $raw );
		$this->assertSame( 'fake', $raw['providers']['square']['alg'] );
		$this->assertArrayNotHasKey( 'access_token', $raw['providers']['square'] );
	}
}
