<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Runs_Page {
	private $data_provider;

	public function __construct( AIMS_Square_Sync_Runs_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();

		echo '<div class="wrap">';
		echo '<h1>Sync Runs / Replay</h1>';
		echo '<p>Sync runs and replay controls will hang off the raw-event ledger and sync-effect records once the runtime pipeline is connected.</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No sync runs are currently available for replay.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Run</th>';
		echo '<th>Source</th>';
		echo '<th>Watermark</th>';
		echo '<th>Status</th>';
		echo '<th>Processed</th>';
		echo '<th>Errors</th>';
		echo '<th>Completed</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['run_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['source_system'] ) . '</td>';
			echo '<td>' . esc_html( $row['sync_watermark'] ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['processed_records'] ) . '</td>';
			echo '<td>' . esc_html( $row['error_count'] ) . '</td>';
			echo '<td>' . esc_html( $row['completed_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
