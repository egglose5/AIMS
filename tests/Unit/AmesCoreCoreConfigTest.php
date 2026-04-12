<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Headless\CoreConfig;

final class AmesCoreCoreConfigTest extends \AIMS\Tests\TestCase {
	public function testPrimaryModeDemotesToShadowWhenApprovalFlagIsFalseString(): void {
		$this->withEnv(
			array(
				'AIMS_BINARY_STREAM_MODE' => 'primary',
				'AIMS_BINARY_PRIMARY_APPROVED' => 'false',
				'AIMS_SHARED_SECRET' => 'test-shared',
				'AIMS_ARCHIVE_SECRET' => 'test-archive',
				'AIMS_ENCRYPTION_KEY' => 'test-encryption',
			),
			function (): void {
				$config = CoreConfig::fromRoot( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-core-config-' . uniqid( '', true ) );

				$this->assertSame( 'shadow', $config->binaryStreamMode() );
				$this->assertFalse( $config->binaryPrimaryApproved() );
			}
		);
	}

	public function testPrimaryModeRemainsPrimaryWhenApprovalFlagIsTruthy(): void {
		$this->withEnv(
			array(
				'AIMS_BINARY_STREAM_MODE' => 'primary',
				'AIMS_BINARY_PRIMARY_APPROVED' => 'yes',
				'AIMS_SHARED_SECRET' => 'test-shared',
				'AIMS_ARCHIVE_SECRET' => 'test-archive',
				'AIMS_ENCRYPTION_KEY' => 'test-encryption',
			),
			function (): void {
				$config = CoreConfig::fromRoot( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-core-config-' . uniqid( '', true ) );

				$this->assertSame( 'primary', $config->binaryStreamMode() );
				$this->assertTrue( $config->binaryPrimaryApproved() );
			}
		);
	}

	public function testValidateRequiredSecretsThrowsWhenPlaceholdersAreUsed(): void {
		$this->withEnv(
			array(
				'AIMS_SHARED_SECRET' => 'change-me',
				'AIMS_ARCHIVE_SECRET' => '',
				'AIMS_ENCRYPTION_KEY' => 'replace-me',
			),
			function (): void {
				$config = CoreConfig::fromRoot( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-core-config-' . uniqid( '', true ) );

				$this->expectException( \RuntimeException::class );
				$this->expectExceptionMessage( 'AIMS_SHARED_SECRET' );
				$config->validateRequiredSecrets();
			}
		);
	}

	public function testValidateRequiredSecretsPassesWithRealValues(): void {
		$this->withEnv(
			array(
				'AIMS_SHARED_SECRET' => 'my-shared-secret',
				'AIMS_ARCHIVE_SECRET' => 'my-archive-secret',
				'AIMS_ENCRYPTION_KEY' => 'my-encryption-key',
			),
			function (): void {
				$config = CoreConfig::fromRoot( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-core-config-' . uniqid( '', true ) );

				$config->validateRequiredSecrets();
				$this->assertTrue( true );
			}
		);
	}

	/**
	 * @param array<string, string> $values
	 */
	private function withEnv( array $values, callable $callback ): void {
		$previous = array();

		foreach ( $values as $key => $value ) {
			$previous[ $key ] = getenv( $key );
			putenv( $key . '=' . $value );
		}

		try {
			$callback();
		} finally {
			foreach ( $previous as $key => $value ) {
				if ( false === $value ) {
					putenv( $key );
					continue;
				}

				putenv( $key . '=' . $value );
			}
		}
	}
}
