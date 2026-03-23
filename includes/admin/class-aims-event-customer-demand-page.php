<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Customer_Demand_Page {
	private $data_provider;

	public function __construct( AIMS_Event_Customer_Demand_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows    = $this->data_provider->get_rows();
		$metrics = $this->data_provider->get_summary_metrics();
		$event   = $this->data_provider->get_event_context();

		echo '<div class="wrap">';
		echo '<h1>Events &rsaquo; Customer Demand</h1>';
		echo '<p>Auto-approved planning data for customer demand, grouped by <code>event_id + product_sku</code> and enriched with Woo-backed display metadata where available.</p>';
		if ( ! empty( $event ) ) {
			echo '<p><strong>Selected Event:</strong> ' . esc_html( (string) ( $event['event_name'] ?? '' ) ) . ' #' . esc_html( (string) ( $event['id'] ?? '' ) ) . '</p>';
		}
		echo '<p><strong>Summary:</strong> Events ' . esc_html( (string) $metrics['total_events'] ) . ' | SKUs ' . esc_html( (string) $metrics['total_skus'] ) . ' | Requested ' . esc_html( (string) $metrics['total_requested'] ) . ' | Open ' . esc_html( (string) $metrics['total_open'] ) . '</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No customer demand has been staged yet.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Event</th>';
		echo '<th>Account</th>';
		echo '<th>SKU</th>';
		echo '<th>Woo Product</th>';
		echo '<th>Product</th>';
		echo '<th>Requested</th>';
		echo '<th>Reserved</th>';
		echo '<th>Open</th>';
		echo '<th>Customer</th>';
		echo '<th>Created</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['event_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['account_display'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['product_sku'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['woo_product_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['product_name'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['quantity_requested'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['quantity_reserved'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['quantity_open'] ) . '</td>';
			echo '<td>' . esc_html( $row['customer_display'] ) . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
