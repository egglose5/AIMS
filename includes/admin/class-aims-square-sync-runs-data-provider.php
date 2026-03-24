<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Runs_Data_Provider {
	public function get_rows(): array {
		$runs = ( new AIMS_Sync_Run_Repository() )->get_for_source( 'square', 50 );

		if ( empty( $runs ) ) {
			return array();
		}

		$rows = array();
		foreach ( $runs as $run ) {
			$rows[] = array(
				'run_id'            => (string) ( $run['id'] ?? '' ),
				'source_system'     => (string) ( $run['source_system'] ?? '' ),
				'sync_watermark'    => (string) ( $run['sync_watermark'] ?? '' ),
				'status'            => ! empty( $run['completed_at'] ) ? ( ! empty( $run['success'] ) ? 'success' : 'failed' ) : 'running',
				'processed_records' => (string) ( $run['processed_records'] ?? '0' ),
				'error_count'       => (string) ( $run['error_count'] ?? '0' ),
				'completed_at'      => (string) ( $run['completed_at'] ?? '' ),
			);
		}

		return $rows;
	}
}
