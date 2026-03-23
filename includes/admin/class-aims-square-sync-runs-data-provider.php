<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Runs_Data_Provider {
	public function get_rows(): array {
		return array(
			array(
				'run_id'           => 1,
				'source_system'    => 'square',
				'sync_watermark'   => '2026-03-23T00:00:00Z',
				'status'           => 'pending',
				'processed_records' => 0,
				'error_count'      => 0,
				'completed_at'     => '',
			),
		);
	}
}
