<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Support;

final class EnvLoader {
	/**
	 * @return array<string, string>
	 */
	public function load( string $path ): array {
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return array();
		}

		$loaded = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
				continue;
			}

			[ $name, $value ] = explode( '=', $line, 2 );
			$name  = trim( $name );
			$value = $this->normalizeValue( $value );

			if ( '' === $name ) {
				continue;
			}

			putenv( $name . '=' . $value );
			$_ENV[ $name ]    = $value;
			$_SERVER[ $name ] = $value;
			$loaded[ $name ]  = $value;
		}

		return $loaded;
	}

	private function normalizeValue( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) || ( str_starts_with( $value, '\'' ) && str_ends_with( $value, '\'' ) ) ) {
			$value = substr( $value, 1, -1 );
		}

		return str_replace( array( '\n', '\r' ), array( "\n", "\r" ), $value );
	}
}
