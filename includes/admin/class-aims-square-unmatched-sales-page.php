<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Unmatched_Sales_Page {
	private $data_provider;

	public function __construct( AIMS_Square_Unmatched_Sales_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();

		echo '<div class="wrap">';
		echo '<h1>Unmatched Sales</h1>';
		echo '<p>Sales that have not resolved to an event or runtime assignment will be reviewed here before attribution and payout work is allowed to proceed.</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No unmatched sales are currently waiting for review.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Order</th>';
		echo '<th>Event</th>';
		echo '<th>Vendor</th>';
		echo '<th>State</th>';
		echo '<th>Reason</th>';
		echo '<th>Created</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['order_ref'] ) . '</td>';
			echo '<td>' . esc_html( $row['event_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['vendor_name'] ) . '</td>';
			echo '<td>' . esc_html( $row['match_state'] ) . '</td>';
			echo '<td>' . esc_html( $row['reason'] ) . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
