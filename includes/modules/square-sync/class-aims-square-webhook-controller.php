<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Webhook_Controller {
	const REST_NAMESPACE = 'aims/v1';
	const REST_ROUTE      = '/square/webhook';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		return rest_ensure_response(
			array(
				'status'        => 'accepted',
				'controller'    => __CLASS__,
				'route'         => self::REST_NAMESPACE . self::REST_ROUTE,
				'received_keys'  => array_keys( $payload ),
				'message'       => 'Webhook controller shell is active. Business logic will be handled by services.',
			)
		);
	}
}
