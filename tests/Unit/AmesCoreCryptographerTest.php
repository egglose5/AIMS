<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Core\Security\Cryptographer;

final class AmesCoreCryptographerTest extends \AIMS\Tests\TestCase {
	public function testCryptographerRequiresOpenSslExtension(): void {
		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) ) {
			$this->markTestSkipped( 'This environment exposes OpenSSL, so the extension guard is not relevant here.' );
		}

		$cryptographer = new Cryptographer( 'encryption-key' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'openssl extension' );

		$cryptographer->encrypt( array( 'secret' => 'value' ) );
	}
}
