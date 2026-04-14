<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Input_Element extends AIMS_Admin_Element {
	public function render(): string {
		$type  = $this->get_prop( 'type', 'text' );
		$id    = $this->get_prop( 'id', '' );
		$name  = $this->get_prop( 'name', $id );
		$value = $this->get_prop( 'value', '' );
		$class = $this->get_prop( 'class', 'regular-text' );
		$placeholder = $this->get_prop( 'placeholder', '' );
		$required = $this->get_prop( 'required', false ) ? ' required' : '';

		$output = sprintf(
			'<input type="%s" id="%s" name="%s" value="%s" class="%s" placeholder="%s"%s%s />',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $class ),
			esc_attr( $placeholder ),
			$required,
			$this->render_attributes()
		);

		if ( $this->get_prop( 'scan', false ) && ! empty( $id ) ) {
			if ( function_exists( 'do_shortcode' ) ) {
				$output .= do_shortcode( '[aims_barcode_scan target="' . $id . '"]' );
			} else {
				// Fallback if do_shortcode is not available (e.g. in some unit test contexts)
				$output .= sprintf(
					'<button type="button" class="button aims-scan-trigger" data-target="%s" aria-label="Scan"><span class="dashicons dashicons-camera" style="margin-top:4px;"></span> Scan</button>',
					esc_attr( $id )
				);
			}
		}

		return $output;
	}
}
