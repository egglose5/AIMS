<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Runs_Data_Provider {
	private $responsibility_auth;

	public function __construct( AIMS_Responsibility_Authorization_Service $responsibility_auth = null ) {
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function get_summary(): array {
		$runs = ( new AIMS_Sync_Run_Repository() )->get_for_source( 'square', 50 );

		$summary = array(
			'total_runs'             => 0,
			'total_processed_records' => 0,
			'total_error_count'      => 0,
			'last_sync_completed_at' => '',
			'last_sync_status'       => 'never',
		);

		if ( empty( $runs ) ) {
			return $summary;
		}

		$summary['total_runs'] = count( $runs );

		foreach ( $runs as $run ) {
			$summary['total_processed_records'] += (int) ( $run['processed_records'] ?? 0 );
			$summary['total_error_count'] += (int) ( $run['error_count'] ?? 0 );
		}

		$latest = is_array( $runs[0] ?? null ) ? $runs[0] : array();
		$summary['last_sync_completed_at'] = (string) ( $latest['completed_at'] ?? '' );
		$summary['last_sync_status'] = ! empty( $latest['completed_at'] )
			? ( ! empty( $latest['success'] ) ? 'success' : 'failed' )
			: 'running';

		return $summary;
	}

	public function get_rows(): array {
		$runs = ( new AIMS_Sync_Run_Repository() )->get_for_source( 'square', 50 );

		if ( empty( $runs ) ) {
			return array();
		}

		$rows = array();
		$can_replay = $this->can_replay();
		$can_undo   = $this->can_undo();

		foreach ( $runs as $run ) {
			$run_id = (int) ( $run['id'] ?? 0 );

			$rows[] = array(
				'run_id'            => $run_id,
				'source_system'     => (string) ( $run['source_system'] ?? '' ),
				'sync_watermark'    => (string) ( $run['sync_watermark'] ?? '' ),
				'status'            => ! empty( $run['completed_at'] ) ? ( ! empty( $run['success'] ) ? 'success' : 'failed' ) : 'running',
				'processed_records' => (string) ( $run['processed_records'] ?? '0' ),
				'error_count'       => (string) ( $run['error_count'] ?? '0' ),
				'completed_at'      => (string) ( $run['completed_at'] ?? '' ),
				'can_replay'        => $can_replay,
				'can_undo'          => $can_undo,
			);
		}

		return $rows;
	}

	private function can_replay(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id > 0 && is_object( $this->responsibility_auth ) && method_exists( $this->responsibility_auth, 'can_run_square_sync_replay' ) ) {
			if ( $this->responsibility_auth->can_run_square_sync_replay( $user_id ) ) {
				return true;
			}
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_SQUARE_SYNC ) && current_user_can( AIMS_Capabilities::CAP_RUN_REPLAY );
	}

	private function can_undo(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id > 0 && is_object( $this->responsibility_auth ) && method_exists( $this->responsibility_auth, 'can_run_square_sync_undo' ) ) {
			if ( $this->responsibility_auth->can_run_square_sync_undo( $user_id ) ) {
				return true;
			}
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_SQUARE_SYNC ) && current_user_can( AIMS_Capabilities::CAP_RUN_UNDO );
	}
}
