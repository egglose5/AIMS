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
		echo '<p>Orders in this queue were marked by the AIMS shipping marker and require customer fulfillment from warehouse stock.</p>';

		if ( empty( $queue_rows ) ) {
			echo '<div class="notice notice-info inline"><p>No orders are currently waiting to be shipped.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Order</th>';
		echo '<th>Customer</th>';
		echo '<th>Event</th>';
		echo '<th>Shipping</th>';
		echo '<th>Status</th>';
		echo '<th>Created</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $queue_rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['order_ref'] ) . '</td>';
			echo '<td>' . esc_html( $row['customer_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['event_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['shipping_label'] ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
