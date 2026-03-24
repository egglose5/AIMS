<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Event_Checkin_Portal_Controller {
	private const SHORTCODE = 'aims_vendor_event_checkin_portal';
	private const ACTION    = 'aims_vendor_event_checkin_submit';

	private $service;

	public function __construct( AIMS_Vendor_Event_Checkin_Portal_Service $service = null ) {
		$this->service = $service ?: new AIMS_Vendor_Event_Checkin_Portal_Service();
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submission' ) );
	}

	public function render_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'       => 'Vendor Check-In',
				'description' => 'Mobile check-in for assigned vendors. This flow is login-required, limited to assigned events, and opens three days before the event start date.',
				'button_label' => 'Submit Check-In',
			),
			$atts,
			self::SHORTCODE
		);

		$model = $this->service->get_page_model( wp_unslash( $_GET ) );

		ob_start();

		$template_path = AIMS_PLUGIN_PATH . 'templates/vendor-event-checkin-portal.php';
		if ( file_exists( $template_path ) ) {
			$portal_title       = (string) $atts['title'];
			$portal_description = (string) $atts['description'];
			$portal_button_label = (string) $atts['button_label'];
			$portal_model       = $model;
			include $template_path;
		} else {
			echo '<div class="aims-vendor-checkin-portal"><p>' . esc_html__( 'Vendor check-in portal template is unavailable.', 'ai-man-sys' ) . '</p></div>';
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

		$nonce = sanitize_text_field( wp_unslash( $_POST['_aims_vendor_checkin_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'aims_vendor_event_checkin_submit' ) ) {
			$this->redirect_with_status( $return_url, 'error', 'Check-in verification failed.' );
		}

		$result = method_exists( $this->service, 'process_check_in' )
			? $this->service->process_check_in( wp_unslash( $_POST ), $_FILES )
			: $this->service->submit_checkin( wp_unslash( $_POST ), $_FILES );
		$this->redirect_with_status(
			$return_url,
			! empty( $result['success'] ) ? 'success' : 'error',
			(string) ( $result['message'] ?? 'Vendor check-in could not be completed.' )
		);
	}

	private function redirect_with_status( string $return_url, string $status, string $message ): void {
		$redirect = add_query_arg(
			array(
				'aims_vendor_checkin_status'  => sanitize_key( $status ),
				'aims_vendor_checkin_message' => $message,
			),
			$return_url
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
