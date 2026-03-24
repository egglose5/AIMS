<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Actions {
	private const ACTION_ASSIGN = 'aims_event_planning_assign_bucket';
	private const ACTION_BULK_ASSIGN = 'aims_event_planning_bulk_assign_buckets';
	private const ACTION_RELEASE = 'aims_event_planning_release_bucket';
	private const ACTION_BULK_RELEASE = 'aims_event_planning_bulk_release_buckets';
	private const ACTION_MARK_IN_TRANSIT = 'aims_event_planning_mark_in_transit';
	private const ACTION_VENDOR_EVENT_CHECK_IN = 'aims_event_planning_vendor_event_check_in';
	private const ACTION_MARK_RETURNED = 'aims_event_planning_mark_returned';
	private const ACTION_RELEASE_AFTER_RETURN = 'aims_event_planning_release_after_return';
	private const NONCE_ASSIGN   = '_aims_event_planning_assign_nonce';
	private const NONCE_BULK_ASSIGN = '_aims_event_planning_bulk_assign_nonce';
	private const NONCE_RELEASE  = '_aims_event_planning_release_nonce';
	private const NONCE_BULK_RELEASE = '_aims_event_planning_bulk_release_nonce';
	private const NONCE_MARK_IN_TRANSIT = '_aims_event_planning_mark_in_transit_nonce';
	private const NONCE_VENDOR_EVENT_CHECK_IN = '_aims_event_planning_vendor_event_check_in_nonce';
	private const NONCE_MARK_RETURNED = '_aims_event_planning_mark_returned_nonce';
	private const NONCE_RELEASE_AFTER_RETURN = '_aims_event_planning_release_after_return_nonce';

	private $service;

	public function __construct( AIMS_Event_Planning_Action_Service $service = null ) {
		$this->service = $service ?: new AIMS_Event_Planning_Action_Service();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_ASSIGN, array( $this, 'handle_assign_bucket' ) );
		add_action( 'admin_post_' . self::ACTION_BULK_ASSIGN, array( $this, 'handle_bulk_assign_buckets' ) );
		add_action( 'admin_post_' . self::ACTION_RELEASE, array( $this, 'handle_release_bucket' ) );
		add_action( 'admin_post_' . self::ACTION_BULK_RELEASE, array( $this, 'handle_bulk_release_buckets' ) );
		add_action( 'admin_post_' . self::ACTION_MARK_IN_TRANSIT, array( $this, 'handle_mark_in_transit' ) );
		add_action( 'admin_post_' . self::ACTION_VENDOR_EVENT_CHECK_IN, array( $this, 'handle_vendor_event_check_in' ) );
		add_action( 'admin_post_' . self::ACTION_MARK_RETURNED, array( $this, 'handle_mark_returned' ) );
		add_action( 'admin_post_' . self::ACTION_RELEASE_AFTER_RETURN, array( $this, 'handle_release_after_return' ) );
	}

	public function handle_assign_bucket(): void {
		$this->handle_action( self::ACTION_ASSIGN, self::NONCE_ASSIGN, 'assign' );
	}

	public function handle_bulk_assign_buckets(): void {
		$this->handle_action( self::ACTION_BULK_ASSIGN, self::NONCE_BULK_ASSIGN, 'bulk_assign' );
	}

	public function handle_release_bucket(): void {
		$this->handle_action( self::ACTION_RELEASE, self::NONCE_RELEASE, 'release' );
	}

	public function handle_bulk_release_buckets(): void {
		$this->handle_action( self::ACTION_BULK_RELEASE, self::NONCE_BULK_RELEASE, 'bulk_release' );
	}

	public function handle_mark_in_transit(): void {
		$this->handle_action( self::ACTION_MARK_IN_TRANSIT, self::NONCE_MARK_IN_TRANSIT, 'mark_in_transit' );
	}

	public function handle_vendor_event_check_in(): void {
		$this->handle_action( self::ACTION_VENDOR_EVENT_CHECK_IN, self::NONCE_VENDOR_EVENT_CHECK_IN, 'vendor_event_check_in' );
	}

	public function handle_mark_returned(): void {
		$this->handle_action( self::ACTION_MARK_RETURNED, self::NONCE_MARK_RETURNED, 'mark_returned' );
	}

	public function handle_release_after_return(): void {
		$this->handle_action( self::ACTION_RELEASE_AFTER_RETURN, self::NONCE_RELEASE_AFTER_RETURN, 'release_after_return' );
	}

	private function handle_action( string $nonce_action, string $nonce_field, string $mode ): void {
		if ( ! $this->can_manage_planning_buckets() ) {
			wp_die( esc_html__( 'You do not have permission to manage event planning buckets.', 'ai-man-sys' ) );
		}

		check_admin_referer( $nonce_action, $nonce_field );

		$request = wp_unslash( $_POST );
		$result  = $this->dispatch_action( $mode, $request );

		$redirect = $this->build_redirect_url( $request, $result );

		wp_safe_redirect( $redirect );
		exit;
	}

	private function can_manage_planning_buckets(): bool {
		if ( ! method_exists( $this->service, 'can_current_user_manage_planning' ) ) {
			return current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING )
				|| current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_BUCKETS );
		}

		return (bool) $this->service->can_current_user_manage_planning();
	}

	private function dispatch_action( string $mode, array $request ): array {
		$map = array(
			'assign'              => 'assign_bucket',
			'bulk_assign'         => 'assign_buckets_bulk',
			'release'             => 'release_bucket',
			'bulk_release'        => 'release_buckets_bulk',
			'release_after_return' => 'release_after_return',
			'mark_in_transit'     => 'mark_in_transit',
			'vendor_event_check_in' => 'vendor_event_check_in',
			'mark_returned'       => 'mark_returned',
		);

		$method = $map[ $mode ] ?? '';
		if ( '' === $method || ! method_exists( $this->service, $method ) ) {
			return array(
				'success' => false,
				'message' => 'Unsupported event planning action.',
			);
		}

		return (array) $this->service->{$method}( $request );
	}

	private function build_redirect_url( array $request, array $result ): string {
		$return_url = esc_url_raw( (string) ( $request['return_url'] ?? '' ) );
		$event_id   = (int) ( $result['event_id'] ?? $request['event_id'] ?? 0 );

		if ( '' === $return_url ) {
			$return_url = add_query_arg(
				array(
					'page'     => 'aims-event-planning-workspace',
					'event_id' => $event_id,
				),
				admin_url( 'admin.php' )
			);
		}

		return add_query_arg(
			array(
				'event_id'                       => $event_id,
				'aims_event_planning_status'     => ! empty( $result['success'] ) ? 'success' : 'error',
				'aims_event_planning_message'    => (string) ( $result['message'] ?? 'Unable to update event planning bucket assignment.' ),
			),
			$return_url
		);
	}
}
