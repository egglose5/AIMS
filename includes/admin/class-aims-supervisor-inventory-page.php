<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Supervisor_Inventory_Page {
	private $data_provider;

	public function __construct( AIMS_Supervisor_Inventory_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows    = $this->data_provider->get_rows();
		$summary = $this->data_provider->get_summary();

		echo '<div class="wrap">';
		echo '<h1>Supervisor Inventory</h1>';
		echo '<p>Inventory buckets are bucket-scoped by vendor or event ownership so future supervisor screens can show only the inventory a user should see.</p>';

		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
		echo '<div class="notice notice-info inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['total'] ) . '</strong> buckets</div>';
		echo '<div class="notice notice-success inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['event'] ) . '</strong> event buckets</div>';
		echo '<div class="notice notice-warning inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['vendor'] ) . '</strong> vendor buckets</div>';
		echo '<div class="notice notice-alt inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['warehouse'] ) . '</strong> warehouse buckets</div>';
		echo '</div>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No accessible inventory buckets are available for this account yet.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Bucket</th>';
		echo '<th>Scope</th>';
		echo '<th>Type</th>';
		echo '<th>Access</th>';
		echo '<th>Qty</th>';
		echo '<th>Reserved</th>';
		echo '<th>Available</th>';
		echo '<th>Updated</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['bucket_label'] ) . '</td>';
			echo '<td>' . esc_html( $row['scope_label'] ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $row['bucket_type'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['access_label'] ) . '</td>';
			echo '<td>' . esc_html( $row['quantity'] ) . '</td>';
			echo '<td>' . esc_html( $row['reserved_quantity'] ) . '</td>';
			echo '<td>' . esc_html( $row['available_quantity'] ) . '</td>';
			echo '<td>' . esc_html( $row['updated_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
