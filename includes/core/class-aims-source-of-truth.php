<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Source_Of_Truth {
	public const WP_WOO = 'wp_woo';
	public const WOO = 'wp_woo';
	public const LEGACY_WOO = 'woo';
	public const AIMS = 'aims';
	public const SQUARE = 'square';

	/**
	 * Returns a list of all canonical source names.
	 */
	public static function all(): array {
		return \AmesCore\Policy\TruthHierarchy::all();
	}

	/**
	 * Validates and normalizes known source values.
	 */
	public static function normalize( string $source ): string {
		return \AmesCore\Policy\TruthHierarchy::normalize( $source );
	}
}
