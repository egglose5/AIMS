<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Barcode_Scanner {
	public function __construct() {
		add_shortcode( 'aims_barcode_scan', array( $this, 'render_shortcode' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ): void {
		// Only enqueue on AIMS pages or if needed elsewhere
		if ( strpos( $hook, 'aims' ) === false && strpos( $hook, 'toplevel_page_aims' ) === false ) {
			// return;
		}

		wp_enqueue_script( 'html5-qrcode', 'https://unpkg.com/html5-qrcode', array(), '2.3.8', true );
		wp_enqueue_script( 'aims-barcode-scanner', AIMS_PLUGIN_URL . 'assets/js/aims-barcode-scanner.js', array( 'jquery', 'html5-qrcode' ), AIMS_VERSION, true );
		wp_enqueue_style( 'aims-admin-css', AIMS_PLUGIN_URL . 'assets/css/aims-admin.css', array(), AIMS_VERSION );
	}

	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array(
			'target' => '',
			'label'  => __( 'Scan', 'ai-man-sys' ),
		), $atts, 'aims_barcode_scan' );

		if ( empty( $atts['target'] ) ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="button aims-scan-trigger" data-target="%s" aria-label="%s"><span class="dashicons dashicons-camera" style="margin-top:4px;"></span> %s</button>',
			esc_attr( $atts['target'] ),
			esc_attr( $atts['label'] ),
			esc_html( $atts['label'] )
		);
	}
}
