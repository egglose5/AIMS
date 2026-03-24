<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Source_Of_Truth {
	public const WOO = 'woo';
	public const AIMS = 'aims';
	public const SQUARE = 'square';

	/**
	 * Returns a list of all canonical source names.
	 */
	public static function all(): array {
		return array(
			self::WOO,
			self::AIMS,
			self::SQUARE,
		);
	}

	/**
	 * Validates and normalizes known source values.
	 */
	public static function normalize( string $source ): string {
		$source = sanitize_key( trim( $source ) );

		return in_array( $source, self::all(), true ) ? $source : self::AIMS;
	}
}
