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
			),
			function (): void {
				$config = CoreConfig::fromRoot( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-core-config-' . uniqid( '', true ) );

				$this->assertSame( 'primary', $config->binaryStreamMode() );
				$this->assertTrue( $config->binaryPrimaryApproved() );
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
