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
		if ( ! current_user_can( AIMS_Capabilities::CAP_RUN_SYNC ) && ! current_user_can( AIMS_Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to view the shipping queue.', 'ai-man-sys' ) );
		}

		$queue_rows = $this->data_provider->get_rows();
		$summary    = $this->data_provider->get_summary();
		$access_mode = $this->data_provider->get_access_mode_label();

		echo '<div class="wrap">';
		echo '<h1>Needs Shipping</h1>';
		echo '<p>Orders in this queue were marked by the AIMS shipping marker and require customer fulfillment from warehouse stock.</p>';
		echo '<p>Use this queue to separate orders that are ready to ship from orders that still need customer or address details.</p>';
		echo '<p><strong>Access:</strong> ' . esc_html( $access_mode ) . '</p>';

		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
		echo '<div class="notice notice-info inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['needs_shipping'] ) . '</strong> needs shipping</div>';
		echo '<div class="notice notice-warning inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['needs_shipping_info'] ) . '</strong> needs shipping info</div>';
		echo '<div class="notice notice-alt inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['backordered'] ) . '</strong> backordered</div>';
		echo '</div>';

		if ( empty( $queue_rows ) ) {
			echo '<div class="notice notice-info inline"><p>No orders are currently waiting to be shipped.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Order</th>';
		echo '<th>Customer</th>';
		echo '<th>Contact</th>';
		echo '<th>Event</th>';
		echo '<th>Queue Type</th>';
		echo '<th>Source</th>';
		echo '<th>Bucket Scope</th>';
		echo '<th>Shipping Details</th>';
		echo '<th>Access</th>';
		echo '<th>Shipping</th>';
		echo '<th>Status</th>';
		echo '<th>Created</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $queue_rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['order_ref'] ) . '</td>';
			echo '<td>' . esc_html( $row['customer_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['customer_contact'] ?? 'No contact on file' ) . '</td>';
			echo '<td>' . esc_html( $row['event_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['queue_type'] ) . '</td>';
			echo '<td>' . esc_html( $row['source_label'] ) . '</td>';
			echo '<td>' . esc_html( $row['scope_label'] ?? 'Scoped' ) . '</td>';
			echo '<td>' . esc_html( $row['shipping_details'] ?? 'Shipping address not yet stored' ) . '<br><small>' . esc_html( $row['fulfillment_hint'] ?? '' ) . '</small></td>';
			echo '<td>' . esc_html( $row['access_label'] ?? 'Scoped access' ) . '</td>';
			echo '<td>' . esc_html( $row['shipping_label'] ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
