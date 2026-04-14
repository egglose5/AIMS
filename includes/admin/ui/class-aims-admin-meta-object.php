<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data container for AIMS Admin components.
 */
class AIMS_Admin_Meta_Object {
	private $data;

	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	public function get( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;
	}

	public function all(): array {
		return $this->data;
	}
}
