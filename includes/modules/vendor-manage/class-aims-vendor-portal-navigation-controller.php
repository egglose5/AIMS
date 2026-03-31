<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Portal_Navigation_Controller {
	private const SHORTCODE = 'aims_vendor_portal_nav';

	private $service;

	public function __construct( AIMS_Vendor_Portal_Navigation_Service $service = null ) {
		$this->service = $service ?: new AIMS_Vendor_Portal_Navigation_Service();
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	public function render_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Vendor Portal', 'ai-man-sys' ),
			),
			$atts,
			self::SHORTCODE
		);

		$model = $this->service->get_nav_model( wp_unslash( $_GET ) );

		ob_start();

		$template_path = AIMS_PLUGIN_PATH . 'templates/vendor-portal-navigation.php';
		if ( file_exists( $template_path ) ) {
			$nav_title = (string) $atts['title'];
			$nav_model = $model;
			include $template_path;
		} else {
			echo '<div class="aims-vendor-portal-nav"><p>' . esc_html__( 'Vendor portal navigation template is unavailable.', 'ai-man-sys' ) . '</p></div>';
		}

		return (string) ob_get_clean();
	}
}
