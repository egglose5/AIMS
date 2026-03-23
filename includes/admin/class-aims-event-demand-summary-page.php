<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Demand_Summary_Page {
	private $data_provider;

	public function __construct( AIMS_Event_Demand_Summary_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();
		$event = $this->data_provider->get_event_context();

		echo '<div class="wrap">';
		echo '<h1>Events &rsaquo; Demand Summary</h1>';
		echo '<p>Operational summary grouped by <code>event_id + product_sku</code> with Woo-backed product metadata shown where available. This view is planning data, not a review queue.</p>';
		if ( ! empty( $event ) ) {
			echo '<p><strong>Selected Event:</strong> ' . esc_html( (string) ( $event['event_name'] ?? '' ) ) . ' #' . esc_html( (string) ( $event['id'] ?? '' ) ) . '</p>';
		}

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No demand summary rows are currently available.</p></div>';
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
		echo '<th>Demand</th>';
		echo '<th>Fulfilled</th>';
		echo '<th>Open</th>';
		echo '<th>Last Updated</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['event_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['account_display'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['product_sku'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['woo_product_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['product_name'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['demand_quantity'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['fulfilled_quantity'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['open_quantity'] ) . '</td>';
			echo '<td>' . esc_html( $row['last_updated'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
