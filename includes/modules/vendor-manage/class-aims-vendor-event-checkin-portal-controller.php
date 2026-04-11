<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Event_Checkin_Portal_Controller {
	private const SHORTCODE       = 'aims_vendor_event_checkin_portal';
	private const ACTION          = 'aims_vendor_event_checkin_submit';
	private const ACTION_EXPENSE  = 'aims_vendor_event_expense_submit';

	private $service;

	public function __construct( AIMS_Vendor_Event_Checkin_Portal_Service $service = null ) {
		$this->service = $service ?: new AIMS_Vendor_Event_Checkin_Portal_Service();
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_' . self::ACTION_EXPENSE, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_EXPENSE, array( $this, 'handle_submission' ) );
	}

	public function render_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'                => 'Vendor Check-In',
				'description'          => 'Mobile field ops for assigned vendors. This login-required portal supports check-in, expense logging, and receipt capture beginning seven days before the event start date.',
				'button_label'         => 'Submit Check-In',
				'expense_button_label' => 'Log Expense',
			),
			$atts,
			self::SHORTCODE
		);

		$model = $this->service->get_page_model( wp_unslash( $_GET ) );

		ob_start();

		$template_path = AIMS_PLUGIN_PATH . 'templates/vendor-event-checkin-portal.php';
		if ( file_exists( $template_path ) ) {
			$portal_title                = (string) $atts['title'];
			$portal_description          = (string) $atts['description'];
			$portal_button_label         = (string) $atts['button_label'];
			$portal_expense_button_label = (string) $atts['expense_button_label'];
			$portal_model                = $model;
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

		$action       = sanitize_key( wp_unslash( $_POST['action'] ?? '' ) );
		$is_expense   = self::ACTION_EXPENSE === $action;
		$nonce_field  = $is_expense ? '_aims_vendor_expense_nonce' : '_aims_vendor_checkin_nonce';
		$nonce_action = $is_expense ? self::ACTION_EXPENSE : self::ACTION;
		$nonce        = sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_with_status( $return_url, 'error', $is_expense ? 'Expense verification failed.' : 'Check-in verification failed.' );
		}

		if ( $is_expense && method_exists( $this->service, 'submit_expense' ) ) {
			$result = $this->service->submit_expense( wp_unslash( $_POST ), $_FILES );
		} else {
			$result = method_exists( $this->service, 'process_check_in' )
				? $this->service->process_check_in( wp_unslash( $_POST ), $_FILES )
				: $this->service->submit_checkin( wp_unslash( $_POST ), $_FILES );
		}
		$this->redirect_with_status(
			$return_url,
			! empty( $result['success'] ) ? 'success' : 'error',
			(string) ( $result['message'] ?? 'Vendor portal request could not be completed.' )
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
