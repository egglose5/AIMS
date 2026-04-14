<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for dashboard widgets/modules in AIMS Admin.
 */
abstract class AIMS_Admin_Widget {
	protected $meta_object;

	public function __construct( AIMS_Admin_Meta_Object $meta_object = null ) {
		$this->meta_object = $meta_object;
	}

	abstract public function render(): void;

	protected function get_data( string $key, $default = null ) {
		return $this->meta_object ? $this->meta_object->get( $key, $default ) : $default;
	}
}
