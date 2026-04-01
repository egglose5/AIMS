<?php

declare( strict_types=1 );

namespace AmesCore\Policy;

final class TruthHierarchy {
	public const WP_WOO = 'wp_woo';
	public const AIMS = 'aims';
	public const SQUARE = 'square';

	public static function all(): array {
		return array(
			self::WP_WOO,
			self::AIMS,
			self::SQUARE,
		);
	}

	public static function normalize( string $source ): string {
		$source = self::sanitizeKey( $source );

		if ( in_array( $source, array( 'wp', 'wordpress', 'woo', 'woocommerce', 'wpwoo' ), true ) ) {
			return self::WP_WOO;
		}

		return in_array( $source, self::all(), true ) ? $source : self::AIMS;
	}

	private static function sanitizeKey( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', trim( $value ) ) );
	}
}
