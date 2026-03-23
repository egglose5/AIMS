<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Run_Controller {
	public function register(): void {
		add_action( 'admin_post_aims_square_replay', array( $this, 'handle_replay' ) );
		add_action( 'admin_post_aims_square_undo', array( $this, 'handle_undo' ) );
	}

	public function handle_replay(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_RUN_REPLAY ) ) {
			wp_die( esc_html__( 'You are not allowed to replay sync runs.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_square_sync_action', '_aims_nonce' );

		$run_id = absint( $_REQUEST['run_id'] ?? 0 );
		do_action( 'aims_square_replay_requested', $run_id );
		$this->redirect_to_sync_runs( 'replay_requested', $run_id );
	}

	public function handle_undo(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_RUN_UNDO ) ) {
			wp_die( esc_html__( 'You are not allowed to undo sync runs.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_square_sync_action', '_aims_nonce' );

		$run_id = absint( $_REQUEST['run_id'] ?? 0 );
		do_action( 'aims_square_undo_requested', $run_id );
		$this->redirect_to_sync_runs( 'undo_requested', $run_id );
	}

	private function redirect_to_sync_runs( string $notice, int $run_id ): void {
		$url = add_query_arg(
			array(
				'page'    => 'aims-square-sync-runs',
				'run_id'  => $run_id,
				$notice   => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
