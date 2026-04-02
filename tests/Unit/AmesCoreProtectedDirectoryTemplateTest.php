<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class AmesCoreProtectedDirectoryTemplateTest extends \AIMS\Tests\TestCase {
	public function testVaultAndConfigDirectoriesAreBlackHoledForApacheAndIis(): void {
		$root = dirname( __DIR__, 2 );

		foreach ( array( 'ames-core\\vault', 'ames-core\\config' ) as $relativePath ) {
			$htaccess = $root . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relativePath ) . DIRECTORY_SEPARATOR . '.htaccess';
			$webConfig = $root . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relativePath ) . DIRECTORY_SEPARATOR . 'web.config';

			$this->assertFileExists( $htaccess );
			$this->assertFileExists( $webConfig );
			$this->assertStringContainsString( '404', (string) file_get_contents( $htaccess ) );
			$this->assertStringContainsString( '404', (string) file_get_contents( $webConfig ) );
		}
	}
}
