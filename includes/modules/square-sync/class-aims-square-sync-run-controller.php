<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Run_Controller {
	private $runs;
	private $actions;
	private $responsibility_auth;

	public function __construct(
		AIMS_Sync_Run_Repository $runs = null,
		AIMS_Sync_Action_Repository $actions = null,
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null
	) {
		$this->runs    = $runs ?: new AIMS_Sync_Run_Repository();
		$this->actions = $actions ?: new AIMS_Sync_Action_Repository();
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function register(): void {
		add_action( 'admin_post_aims_square_replay', array( $this, 'handle_replay' ) );
		add_action( 'admin_post_aims_square_undo', array( $this, 'handle_undo' ) );
	}

	public function handle_replay(): void {
		$this->handle_request(
			'replay',
			AIMS_Capabilities::CAP_RUN_REPLAY,
			'You are not allowed to replay sync runs.',
			'aims_square_replay_requested',
			'replay_requested'
		);
	}

	public function handle_undo(): void {
		$this->handle_request(
			'undo',
			AIMS_Capabilities::CAP_RUN_UNDO,
			'You are not allowed to undo sync runs.',
			'aims_square_undo_requested',
			'undo_requested'
		);
	}

	private function handle_request( string $mode, string $capability, string $denied_message, string $action_hook, string $notice_key ): void {
		if ( ! $this->can_execute_action_mode( $mode, $capability ) ) {
			wp_die( esc_html__( $denied_message, 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_square_sync_action', '_aims_nonce' );

		$run_id = max( 0, (int) ( $_REQUEST['run_id'] ?? 0 ) );
		if ( $run_id <= 0 ) {
			$this->redirect_to_sync_runs( '', $run_id, 'error', 'A valid Square sync run is required.' );
		}

		$run = $this->runs->find( $run_id );
		if ( ! is_array( $run ) || 'square' !== sanitize_key( (string) ( $run['source_system'] ?? '' ) ) ) {
			$this->redirect_to_sync_runs( '', $run_id, 'error', 'Square sync run not found.' );
		}

		$action_type = $mode . '_request';
		$existing_request = $this->actions->find_latest_for_run_action_type( $run_id, $action_type );
		if ( is_array( $existing_request ) && 'success' === sanitize_key( (string) ( $existing_request['status'] ?? '' ) ) ) {
			$this->redirect_to_sync_runs( '', $run_id, 'error', ucfirst( $mode ) . ' has already been requested for this run.' );
		}

		$this->actions->save(
			array(
				'run_id'             => $run_id,
				'external_record_id' => 'square-run-' . $run_id . '-' . $mode,
				'action_type'        => $action_type,
				'entity_type'        => 'sync_run',
				'entity_id'          => $run_id,
				'status'             => 'success',
				'quantity_delta'     => 1,
				'message'            => ucfirst( $mode ) . ' requested from Sync Runs admin controls.',
				'occurred_at'        => current_time( 'mysql' ),
			)
		);

		do_action( $action_hook, $run_id );
		$this->redirect_to_sync_runs( $notice_key, $run_id, 'success', ucfirst( $mode ) . ' request recorded.' );
	}

	private function redirect_to_sync_runs( string $notice, int $run_id, string $status = '', string $message = '' ): void {
		$args = array(
			'page'   => 'aims-square-sync-runs',
			'run_id' => $run_id,
		);

		if ( '' !== $notice ) {
			$args[ $notice ] = 1;
		}

		if ( '' !== $status ) {
			$args['aims_square_sync_status'] = $status;
		}

		if ( '' !== $message ) {
			$args['aims_square_sync_message'] = $message;
		}

		$url = add_query_arg(
			$args,
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function can_execute_action_mode( string $mode, string $capability ): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id <= 0 || ! is_object( $this->responsibility_auth ) ) {
			return false;
		}

		if ( ! $this->responsibility_auth->can_manage_square_sync( $user_id ) ) {
			return false;
		}

		if ( 'replay' === $mode ) {
			return $this->responsibility_auth->can_run_square_sync_replay( $user_id );
		}

		if ( 'undo' === $mode ) {
			return $this->responsibility_auth->can_run_square_sync_undo( $user_id );
		}

		return true;
	}
}
