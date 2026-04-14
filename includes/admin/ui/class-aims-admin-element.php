<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for basic UI elements in AIMS Admin.
 */
abstract class AIMS_Admin_Element {
	protected $props;

	public function __construct( array $props = array() ) {
		$this->props = $props;
	}

	abstract public function render(): string;

	protected function get_prop( string $key, $default = '' ) {
		return $this->props[ $key ] ?? $default;
	}

	protected function render_attributes(): string {
		$attributes = $this->get_prop( 'attributes', array() );
		$output     = '';
		foreach ( $attributes as $name => $value ) {
			$output .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		return $output;
	}
}
