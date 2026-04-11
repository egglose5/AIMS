<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Portal_Navigation_Controller {
	private const SHORTCODE = 'aims_vendor_portal_nav';
	private const ACTION    = 'aims_vendor_join_show';

	private $service;

	public function __construct( AIMS_Vendor_Portal_Navigation_Service $service = null ) {
		$this->service = $service ?: new AIMS_Vendor_Portal_Navigation_Service();
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submission' ) );
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

	public function handle_submission(): void {
		$return_url = esc_url_raw( (string) ( wp_unslash( $_POST['_aims_return_url'] ?? '' ) ) );
		$return_url = '' !== $return_url ? $return_url : home_url( '/' );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( function_exists( 'wp_login_url' ) ? wp_login_url( $return_url ) : $return_url );
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_aims_vendor_portal_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			$this->redirect_with_status( $return_url, 'error', 'Show join verification failed.' );
		}

		$result = method_exists( $this->service, 'join_show' )
			? $this->service->join_show( wp_unslash( $_POST ) )
			: array(
				'success' => false,
				'message' => 'Show joining is unavailable right now.',
			);

		$this->redirect_with_status(
			$return_url,
			! empty( $result['success'] ) ? 'success' : 'error',
			(string) ( $result['message'] ?? 'The show could not be joined right now.' )
		);
	}

	private function redirect_with_status( string $return_url, string $status, string $message ): void {
		$redirect = add_query_arg(
			array(
				'aims_vendor_portal_status'  => sanitize_key( $status ),
				'aims_vendor_portal_message' => $message,
			),
			$return_url
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
