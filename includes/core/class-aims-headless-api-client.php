<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Headless_Api_Client {
	private string $base_url;
	private string $token;

	public function __construct( string $base_url = '', string $token = '' ) {
		$this->base_url = untrailingslashit( trim( $base_url ) );
		$this->token    = trim( $token );
	}

	public static function from_plugin_options(): self {
		return new self(
			AIMS_Plugin::get_api_url(),
			AIMS_Plugin::get_api_token()
		);
	}

	public function is_configured(): bool {
		return '' !== $this->base_url && '' !== $this->token;
	}

	public function get_manifest( array $query = array() ): array {
		return $this->request( 'GET', '/manifest', array(
			'query' => $query,
		) );
	}

	public function post_move( array $payload ): array {
		return $this->request( 'POST', '/move', array(
			'body' => $payload,
		) );
	}

	public function trigger_archive( array $query = array() ): array {
		return $this->request( 'GET', '/internal/archive', array(
			'query' => $query,
		) );
	}

	public function push_manifest( array $payload ): array {
		return $this->request( 'POST', '/manifest/push', array(
			'body' => $payload,
		) );
	}

	private function request( string $method, string $path, array $args = array() ): array {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'code'    => 0,
				'message' => 'AIMS API URL and token are required.',
				'json'    => null,
				'body'    => '',
			);
		}

		$url = $this->base_url . '/' . ltrim( $path, '/' );
		$headers = array(
			'Accept'       => 'application/json',
			'X-Ames-Token' => $this->token,
		);

		$options = array(
			'timeout' => 15,
			'headers' => $headers,
		);

		if ( 'GET' === strtoupper( $method ) && ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		if ( 'POST' === strtoupper( $method ) ) {
			$options['headers']['Content-Type'] = 'application/json';
			$options['body'] = wp_json_encode( (array) ( $args['body'] ?? array() ) );
		}

		$response = 'GET' === strtoupper( $method )
			? wp_remote_get( $url, $options )
			: wp_remote_post( $url, $options );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
				'json'    => null,
				'body'    => '',
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		return array(
			'success' => (int) wp_remote_retrieve_response_code( $response ) >= 200 && (int) wp_remote_retrieve_response_code( $response ) < 300,
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'message' => (string) wp_remote_retrieve_response_message( $response ),
			'headers' => wp_remote_retrieve_headers( $response ),
			'body'    => $body,
			'json'    => JSON_ERROR_NONE === json_last_error() ? $json : null,
			'url'     => $url,
			'method'  => strtoupper( $method ),
		);
	}
}
