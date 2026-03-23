<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Square_Sync_Review_Page {
	private $data_provider;

	public function __construct( AIMS_Vendor_Square_Sync_Review_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();

		echo '<div class="wrap">';
		echo '<h1>Vendor Sync Review</h1>';
		echo '<p>Vendor-to-Square team-member reconciliation is search-first and create-if-missing. Ambiguities land here for review.</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No vendor sync reviews are currently pending.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Vendor</th>';
		echo '<th>Square Team Member</th>';
		echo '<th>State</th>';
		echo '<th>Search Basis</th>';
		echo '<th>Notes</th>';
		echo '<th>Updated</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['vendor_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['square_team_member'] ) . '</td>';
			echo '<td>' . esc_html( $row['state'] ) . '</td>';
			echo '<td>' . esc_html( $row['search_basis'] ) . '</td>';
			echo '<td>' . esc_html( $row['notes'] ) . '</td>';
			echo '<td>' . esc_html( $row['updated_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
