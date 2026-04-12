<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Cycle_Count_Controller {
	public const SHORTCODE     = 'aims_cycle_count_portal';
	public const REST_NAMESPACE = 'aims/v1';
	public const REST_ROUTE_BUCKET = '/cycle-count/bucket';
	public const REST_ROUTE_SUBMIT = '/cycle-count/submit';

	private $service;

	public function __construct( AIMS_Cycle_Count_Service $service = null ) {
		$this->service = $service ?: new AIMS_Cycle_Count_Service();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_shortcode(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_BUCKET,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_resolve_bucket' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'barcode' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_SUBMIT,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_submit_count' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);
	}

	public function permission_callback(): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE );
	}

	public function handle_resolve_bucket( WP_REST_Request $request ): WP_REST_Response {
		$barcode = sanitize_text_field( (string) $request->get_param( 'barcode' ) );
		$result  = $this->service->resolve_bucket( $barcode );

		$status_code = ! empty( $result['found'] ) ? 200 : 404;

		return new WP_REST_Response( $result, $status_code );
	}

	public function handle_submit_count( WP_REST_Request $request ): WP_REST_Response {
		$params    = $request->get_json_params();
		$bucket_id = (int) ( $params['bucket_id'] ?? 0 );
		$lines     = is_array( $params['lines'] ?? null ) ? $params['lines'] : array();
		$notes     = sanitize_textarea_field( (string) ( $params['notes'] ?? '' ) );

		if ( $bucket_id <= 0 ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'errors'  => array( 'bucket_id is required.' ),
				),
				400
			);
		}

		// Sanitize incoming lines — each must be an array with 'sku' and 'quantity'.
		$sanitized_lines = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$sanitized_lines[] = array(
				'sku'      => sanitize_text_field( (string) ( $line['sku'] ?? '' ) ),
				'quantity' => max( 0.0, (float) ( $line['quantity'] ?? 0 ) ),
			);
		}

		$applied_by = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$result     = $this->service->submit_count( $bucket_id, $sanitized_lines, $notes, $applied_by );

		$status_code = ! empty( $result['success'] ) ? 200 : 422;

		return new WP_REST_Response( $result, $status_code );
	}

	public function render_shortcode( array $atts = array() ): string {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			$login_url = function_exists( 'wp_login_url' ) ? esc_url( wp_login_url( get_permalink() ) ) : '';
			return '<div class="aims-cycle-count-portal"><p>Please <a href="' . $login_url . '">log in</a> to access cycle count.</p></div>';
		}

		if ( ! $this->permission_callback() ) {
			return '<div class="aims-cycle-count-portal"><p>You do not have permission to perform cycle counts.</p></div>';
		}

		$atts = shortcode_atts(
			array(
				'title'       => 'Cycle Count / Inventory Deployment',
				'description' => 'Scan a bucket barcode, then scan all SKUs in or going into that bucket.',
			),
			$atts,
			self::SHORTCODE
		);

		$rest_base  = function_exists( 'rest_url' ) ? rest_url( self::REST_NAMESPACE . '/' ) : '';
		$rest_nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';

		ob_start();

		$template_path = AIMS_PLUGIN_PATH . 'templates/cycle-count-portal.php';
		if ( file_exists( $template_path ) ) {
			$cc_title        = esc_html( (string) $atts['title'] );
			$cc_description  = esc_html( (string) $atts['description'] );
			$cc_rest_base    = esc_url( $rest_base );
			$cc_rest_nonce   = esc_attr( $rest_nonce );
			$cc_bucket_route = esc_js( 'cycle-count/bucket' );
			$cc_submit_route = esc_js( 'cycle-count/submit' );
			include $template_path;
		} else {
			echo '<div class="aims-cycle-count-portal"><p>Cycle count template not found.</p></div>';
		}

		return (string) ob_get_clean();
	}
}
