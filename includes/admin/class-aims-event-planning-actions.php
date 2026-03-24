<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Actions {
	private const ACTION_ASSIGN = 'aims_event_planning_assign_bucket';
	private const ACTION_RELEASE = 'aims_event_planning_release_bucket';
	private const NONCE_ASSIGN   = '_aims_event_planning_assign_nonce';
	private const NONCE_RELEASE  = '_aims_event_planning_release_nonce';

	private $service;

	public function __construct( AIMS_Event_Planning_Action_Service $service = null ) {
		$this->service = $service ?: new AIMS_Event_Planning_Action_Service();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_ASSIGN, array( $this, 'handle_assign_bucket' ) );
		add_action( 'admin_post_' . self::ACTION_RELEASE, array( $this, 'handle_release_bucket' ) );
	}

	public function handle_assign_bucket(): void {
		$this->handle_action( self::ACTION_ASSIGN, self::NONCE_ASSIGN, 'assign' );
	}

	public function handle_release_bucket(): void {
		$this->handle_action( self::ACTION_RELEASE, self::NONCE_RELEASE, 'release' );
	}

	private function handle_action( string $nonce_action, string $nonce_field, string $mode ): void {
		if ( ! $this->can_manage_planning_buckets() ) {
			wp_die( esc_html__( 'You do not have permission to manage event planning buckets.', 'ai-man-sys' ) );
		}

		check_admin_referer( $nonce_action, $nonce_field );

		$request = wp_unslash( $_POST );
		$result  = 'assign' === $mode
			? $this->service->assign_bucket( $request )
			: $this->service->release_bucket( $request );

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
