<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Portal_Controller {
	private const SHORTCODE = 'aims_stitch_portal';
	private const ACTION    = 'aims_stitch_complete_item';

	private $service;

	public function __construct( AIMS_Stitch_Portal_Service $service = null ) {
		$this->service = $service ?: new AIMS_Stitch_Portal_Service();
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_completion' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_completion' ) );
	}

	public function render_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'       => 'Stitcher Portal',
				'description' => 'Simple stitcher workspace for current custody buckets and open stitch work. Sign in, complete a line, and mark it in transit back when finished.',
				'button_label' => 'Complete 1',
			),
			$atts,
			self::SHORTCODE
		);

		$model = $this->service->get_page_model( wp_unslash( $_GET ) );

		ob_start();

		$template_path = AIMS_PLUGIN_PATH . 'templates/stitch-portal.php';
		if ( file_exists( $template_path ) ) {
			$portal_title        = (string) $atts['title'];
			$portal_description  = (string) $atts['description'];
			$portal_button_label = (string) $atts['button_label'];
			$portal_model        = $model;
			include $template_path;
		} else {
			echo '<div class="aims-stitch-portal"><p>' . esc_html__( 'Stitcher portal template is unavailable.', 'ai-man-sys' ) . '</p></div>';
		}

		return (string) ob_get_clean();
	}

	public function handle_completion(): void {
		$return_url = esc_url_raw( (string) ( wp_unslash( $_POST['_aims_return_url'] ?? '' ) ) );
		$return_url = '' !== $return_url ? $return_url : home_url( '/' );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( function_exists( 'wp_login_url' ) ? wp_login_url( $return_url ) : $return_url );
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_aims_stitch_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'aims_stitch_complete_item' ) ) {
			$this->redirect_with_status( $return_url, 'error', 'Stitch completion verification failed.' );
		}

		$result = $this->service->complete_job( wp_unslash( $_POST ) );
		$this->redirect_with_status(
			$return_url,
			! empty( $result['success'] ) ? 'success' : 'error',
			(string) ( $result['message'] ?? 'Stitch work item could not be completed.' )
		);
	}

	private function redirect_with_status( string $return_url, string $status, string $message ): void {
		$redirect = add_query_arg(
			array(
				'stitch_status'  => sanitize_key( $status ),
				'stitch_message' => $message,
			),
			$return_url
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
