<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Shipping_Queue_Page {
	private $data_provider;

	public function __construct( AIMS_Shipping_Queue_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$queue_rows = $this->data_provider->get_rows();

		echo '<div class="wrap">';
		echo '<h1>Needs Shipping</h1>';
		echo '<p>Orders in this queue were marked by the AIMS shipping marker and require customer fulfillment from warehouse stock. Use the <strong>Pick Location</strong> link on each row to look up the FIFO bin location in the AIMS warehouse dashboard.</p>';

		if ( empty( $queue_rows ) ) {
			echo '<div class="notice notice-info inline"><p>No orders are currently waiting to be shipped.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Order</th>';
		echo '<th>SKU</th>';
		echo '<th>Qty</th>';
		echo '<th>Shipping</th>';
		echo '<th>Status</th>';
		echo '<th>Sold At</th>';
		echo '<th>Pick Location</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $queue_rows as $row ) {
			$fifo_url = (string) ( $row['fifo_location_url'] ?? '' );
			$sku      = (string) ( $row['sku'] ?? '' );

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['order_ref'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $sku ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['quantity'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['shipping_label'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['created_at'] ?? '' ) ) . '</td>';
			echo '<td>';
			if ( '' !== $fifo_url && '' !== $sku ) {
				echo '<a href="' . esc_url( $fifo_url ) . '" title="Find FIFO bin location for ' . esc_attr( $sku ) . ' in AIMS warehouse dashboard">&#128269; Find in Warehouse</a>';
			} else {
				echo '&mdash;';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
