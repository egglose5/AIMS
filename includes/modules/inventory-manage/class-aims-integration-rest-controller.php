<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Integration_Rest_Controller {
	public const REST_NAMESPACE = 'aims/v1';
	public const REST_ROUTE_INGEST = '/integrations/inventory';
	public const REST_ROUTE_FEED   = '/integrations/updates';
	public const REST_ROUTE_ROLES  = '/integrations/roles';
	public const REST_ROUTE_ACCOUNT = '/integrations/accounts';
	public const REST_ROUTE_ROLE_ACCOUNTS = '/integrations/role-accounts';

	private $service;
	private $account_service;

	public function __construct( AIMS_Integration_Update_Service $service = null, AIMS_Integration_Account_Service $account_service = null ) {
		$this->service         = $service ?: new AIMS_Integration_Update_Service();
		$this->account_service = $account_service ?: new AIMS_Integration_Account_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_INGEST,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ingest_inventory_updates' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_FEED,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_updates_feed' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_ROLES,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_roles_catalog' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_ACCOUNT,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_account_snapshot' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_ROLE_ACCOUNTS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_role_accounts' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function ingest_inventory_updates( WP_REST_Request $request ) {
		if ( ! $this->is_authorized( $request ) ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'forbidden',
						'message' => 'AIMS token is missing or invalid.',
					),
					401
				)
			);
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$summary = is_object( $this->service ) && method_exists( $this->service, 'ingest_updates' )
			? (array) $this->service->ingest_updates( $payload, array( 'source' => 'integration' ) )
			: array();

		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'status'         => 'accepted',
					'controller'     => __CLASS__,
					'route'          => self::REST_NAMESPACE . self::REST_ROUTE_INGEST,
					'total_received' => (int) ( $summary['total_received'] ?? 0 ),
					'accepted'       => (int) ( $summary['accepted'] ?? 0 ),
					'skipped'        => (int) ( $summary['skipped'] ?? 0 ),
					'latest_cursor'  => (string) ( $summary['latest_cursor'] ?? '' ),
					'message'        => 'Inventory updates were ingested for AIMS automation.',
				),
				202
			)
		);
	}

	public function get_updates_feed( WP_REST_Request $request ) {
		if ( ! $this->is_authorized( $request ) ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'forbidden',
						'message' => 'AIMS token is missing or invalid.',
					),
					401
				)
			);
		}

		$since = sanitize_text_field( (string) ( $request->get_param( 'since' ) ?? '' ) );
		$limit = max( 1, min( 500, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );

		$snapshot = is_object( $this->service ) && method_exists( $this->service, 'get_feed_snapshot' )
			? (array) $this->service->get_feed_snapshot( $since, $limit )
			: array();

		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'status'           => 'ready',
					'controller'       => __CLASS__,
					'route'            => self::REST_NAMESPACE . self::REST_ROUTE_FEED,
					'generated_at'     => (string) ( $snapshot['generated_at'] ?? '' ),
					'latest_cursor'    => (string) ( $snapshot['latest_cursor'] ?? '' ),
					'updates_count'    => (int) ( $snapshot['updates_count'] ?? 0 ),
					'updates'          => (array) ( $snapshot['updates'] ?? array() ),
					'low_stock_summary'=> (array) ( $snapshot['low_stock_summary'] ?? array() ),
					'low_stock_alerts' => (array) ( $snapshot['low_stock_alerts'] ?? array() ),
				),
				200
			)
		);
	}

	public function get_roles_catalog( WP_REST_Request $request ) {
		if ( ! $this->is_authorized( $request ) ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'forbidden',
						'message' => 'AIMS token is missing or invalid.',
					),
					401
				)
			);
		}

		$snapshot = is_object( $this->account_service ) && method_exists( $this->account_service, 'get_roles_snapshot' )
			? (array) $this->account_service->get_roles_snapshot()
			: array();

		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'status'            => 'ready',
					'controller'        => __CLASS__,
					'route'             => self::REST_NAMESPACE . self::REST_ROUTE_ROLES,
					'generated_at'      => (string) ( $snapshot['generated_at'] ?? '' ),
					'templates'         => (array) ( $snapshot['templates'] ?? array() ),
					'runtime_roles'     => (array) ( $snapshot['runtime_roles'] ?? array() ),
					'supported_surfaces'=> (array) ( $snapshot['supported_surfaces'] ?? array() ),
					'capability_groups' => (array) ( $snapshot['capability_groups'] ?? array() ),
				),
				200
			)
		);
	}

	public function get_account_snapshot( WP_REST_Request $request ) {
		if ( ! $this->is_authorized( $request ) ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'forbidden',
						'message' => 'AIMS token is missing or invalid.',
					),
					401
				)
			);
		}

		$lookup = array(
			'user_id'  => absint( $request->get_param( 'user_id' ) ?? 0 ),
			'username' => sanitize_text_field( (string) ( $request->get_param( 'username' ) ?? '' ) ),
			'email'    => sanitize_email( (string) ( $request->get_param( 'email' ) ?? '' ) ),
		);

		if ( $lookup['user_id'] <= 0 && '' === $lookup['username'] && '' === $lookup['email'] ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'invalid_request',
						'message' => 'Provide user_id, username, or email.',
					),
					400
				)
			);
		}

		$account = is_object( $this->account_service ) && method_exists( $this->account_service, 'get_account_snapshot' )
			? (array) $this->account_service->get_account_snapshot( $lookup )
			: array();

		if ( empty( $account ) ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'not_found',
						'message' => 'No matching account was found.',
						'lookup'  => $lookup,
					),
					404
				)
			);
		}

		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'status'     => 'ready',
					'controller' => __CLASS__,
					'route'      => self::REST_NAMESPACE . self::REST_ROUTE_ACCOUNT,
					'lookup'     => $lookup,
					'account'    => $account,
				),
				200
			)
		);
	}

	public function get_role_accounts( WP_REST_Request $request ) {
		if ( ! $this->is_authorized( $request ) ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'forbidden',
						'message' => 'AIMS token is missing or invalid.',
					),
					401
				)
			);
		}

		$roles_param = sanitize_text_field( (string) ( $request->get_param( 'roles' ) ?? '' ) );
		$roles       = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					array_map( 'trim', explode( ',', $roles_param ) )
				)
			)
		);

		if ( empty( $roles ) ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status'  => 'invalid_request',
						'message' => 'Provide one or more AIMS role slugs in the roles parameter.',
					),
					400
				)
			);
		}

		$directory = is_object( $this->account_service ) && method_exists( $this->account_service, 'get_role_account_directory' )
			? (array) $this->account_service->get_role_account_directory( $roles )
			: array();

		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'status'          => 'ready',
					'controller'      => __CLASS__,
					'route'           => self::REST_NAMESPACE . self::REST_ROUTE_ROLE_ACCOUNTS,
					'generated_at'    => (string) ( $directory['generated_at'] ?? '' ),
					'requested_roles' => (array) ( $directory['requested_roles'] ?? array() ),
					'accounts'        => (array) ( $directory['accounts'] ?? array() ),
				),
				200
			)
		);
	}

	private function is_authorized( WP_REST_Request $request ): bool {
		$configured = AIMS_Plugin::get_api_token();
		if ( '' === $configured ) {
			return false;
		}

		$provided = $this->resolve_request_token( $request );
		if ( '' === $provided ) {
			return false;
		}

		return function_exists( 'hash_equals' )
			? hash_equals( $configured, $provided )
			: $configured === $provided;
	}

	private function resolve_request_token( WP_REST_Request $request ): string {
		$token = trim( sanitize_text_field( (string) ( $request->get_param( 'token' ) ?? '' ) ) );
		if ( '' !== $token ) {
			return $token;
		}

		$server_headers = array(
			'HTTP_X_AMES_TOKEN',
			'HTTP_X_AIMS_TOKEN',
			'HTTP_AUTHORIZATION',
		);

		foreach ( $server_headers as $server_key ) {
			$raw = isset( $_SERVER[ $server_key ] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER[ $server_key ] ) ) : '';
			if ( '' === $raw ) {
				continue;
			}

			if ( 'HTTP_AUTHORIZATION' === $server_key ) {
				$prefix = 'Bearer ';
				if ( 0 === stripos( $raw, $prefix ) ) {
					return trim( substr( $raw, strlen( $prefix ) ) );
				}
			}

			return trim( $raw );
		}

		return '';
	}
}
