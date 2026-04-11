<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Laser_Batch_Rest_Controller {
	public const REST_NAMESPACE = 'wc/v3';
	public const REST_ROUTE     = '/aims/laser-batches';

	private $client;

	public function __construct( AIMS_Headless_Api_Client $client = null ) {
		$this->client = $client ?: AIMS_Headless_Api_Client::from_plugin_options();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_batches' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'push_batch' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);
	}

	public function permission_callback(): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( 'manage_woocommerce' )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_PRODUCTION )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_STITCH );
	}

	public function get_batches( WP_REST_Request $request ) {
		$limit    = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?? 20 ) ) );
		$response = is_object( $this->client ) && method_exists( $this->client, 'get_laser_batches' )
			? $this->client->get_laser_batches( array( 'limit' => $limit ) )
			: array(
				'success' => false,
				'message' => 'AIMS headless client is unavailable.',
				'json'    => array(),
			);

		$status_code = ! empty( $response['success'] ) ? 200 : 502;
		$payload     = array(
			'status'     => ! empty( $response['success'] ) ? 'ready' : 'error',
			'controller' => __CLASS__,
			'route'      => self::REST_NAMESPACE . self::REST_ROUTE,
			'target_url' => self::REST_NAMESPACE . self::REST_ROUTE,
			'batches'    => (array) ( $response['json']['batches'] ?? $response['json']['result']['batches'] ?? array() ),
			'message'    => (string) ( $response['message'] ?? 'Laser batch route lookup completed.' ),
		);

		return rest_ensure_response( new WP_REST_Response( $payload, $status_code ) );
	}

	public function push_batch( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$response = is_object( $this->client ) && method_exists( $this->client, 'push_laser_batch' )
			? $this->client->push_laser_batch( $payload )
			: array(
				'success' => false,
				'message' => 'AIMS headless client is unavailable.',
				'json'    => array(),
			);

		$status_code = ! empty( $response['success'] ) ? 202 : 502;
		$batch       = (array) ( $response['json']['batch'] ?? $response['json']['result']['batch'] ?? $response['json']['batch_record'] ?? array() );
		$reply       = array(
			'status'        => ! empty( $response['success'] ) ? 'accepted' : 'error',
			'controller'    => __CLASS__,
			'route'         => self::REST_NAMESPACE . self::REST_ROUTE,
			'batch'         => $batch,
			'batch_id'      => (string) ( $batch['batch_id'] ?? $payload['batch_id'] ?? '' ),
			'received_keys' => array_keys( $payload ),
			'message'       => (string) ( $response['message'] ?? 'Laser batch submission completed.' ),
		);

		return rest_ensure_response( new WP_REST_Response( $reply, $status_code ) );
	}
}
