<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Reports_Event_Sales_Page {
	private $data_provider;

	public function __construct( AIMS_Reports_Event_Sales_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$event_id = isset( $_GET['event_id'] ) ? max( 0, (int) wp_unslash( $_GET['event_id'] ) ) : 0;
		$rows     = $this->data_provider->get_rows( $event_id );
		$events   = $this->data_provider->get_event_options();

		echo '<h2>Event Sales and Attribution</h2>';
		echo '<p>Compare sales and attribution totals by event using `event_id` as the shared operational key.</p>';
		$this->render_filter_form( $events, $event_id );

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No report rows match the selected filter.</p></div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Event</th><th>Status</th><th>Sales Rows</th><th>Gross</th><th>Net</th><th>Discount</th><th>Tip</th><th>Attribution Rows</th><th>Commission</th><th>Payout</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['event_name'] ?? '' ) ) . '</strong><br /><code>' . esc_html( (string) ( $row['event_code'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( ucfirst( (string) ( $row['status'] ?? 'draft' ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['sales_count'] ?? '0' ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_money( $row['gross_total'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_money( $row['net_total'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_money( $row['discount_total'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_money( $row['tip_total'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['attribution_count'] ?? '0' ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_money( $row['commission_total'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_money( $row['payout_total'] ?? 0 ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_filter_form( array $events, int $event_id ): void {
		$admin_url = admin_url( 'admin.php' );
		echo '<form method="get" action="' . esc_url( $admin_url ) . '" style="margin: 12px 0 16px 0;">';
		echo '<input type="hidden" name="page" value="aims-reports" />';
		echo '<label for="aims-reports-event-filter"><strong>Event:</strong></label> ';
		echo '<select id="aims-reports-event-filter" name="event_id">';
		echo '<option value="0">All events</option>';
		foreach ( $events as $event ) {
			$selected = $event_id === (int) $event['id'] ? ' selected' : '';
			echo '<option value="' . esc_attr( (string) $event['id'] ) . '"' . $selected . '>' . esc_html( (string) $event['name'] ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button">Apply Filter</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom: 16px;">';
		wp_nonce_field( 'aims_reports_export_event_sales' );
		echo '<input type="hidden" name="action" value="aims_reports_export_event_sales" />';
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '" />';
		echo '<button type="submit" class="button button-secondary">Export CSV</button>';
		echo '</form>';
	}

	private function format_money( $value ): string {
		return '$' . number_format( (float) $value, 2, '.', ',' );
	}
}

