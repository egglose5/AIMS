<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Exceptions_Page {
	private $data_provider;

	public function __construct( AIMS_Square_Exceptions_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();

		echo '<div class="wrap">';
		echo '<h1>Square Exceptions</h1>';
		echo '<p>Normalize, assign, and attribute first. Exceptions are the review queue for anything that still needs operator attention.</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No exceptions are currently open.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Sale</th>';
		echo '<th>Type</th>';
		echo '<th>Severity</th>';
		echo '<th>Status</th>';
		echo '<th>Message</th>';
		echo '<th>Created</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['sale_ref'] ) . '</td>';
			echo '<td>' . esc_html( $row['exception_type'] ) . '</td>';
			echo '<td>' . esc_html( $row['severity'] ) . '</td>';
			echo '<td>' . esc_html( $row['resolution_status'] ) . '</td>';
			echo '<td>' . esc_html( $row['message'] ) . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
