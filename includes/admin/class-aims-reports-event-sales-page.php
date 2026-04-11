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
		$totals   = $this->data_provider->get_summary_totals( $event_id );

		echo '<h2>Event Sales and Attribution</h2>';
		echo '<p>Compare sales, payouts, expenses, and <strong>Total Show Profit</strong> by event using <code>event_id</code> as the shared operational key.</p>';
		$this->render_filter_form( $events, $event_id );

		if ( ! empty( $rows ) ) {
			$this->render_summary_cards( $totals );
		}

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No report rows match the selected filter.</p></div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Event</th><th>Status</th><th>Sales Rows</th><th>Gross</th><th>Net</th><th>Discount</th><th>Tip</th><th>Attribution Rows</th><th>Commission</th><th>Payout</th><th>Expenses</th><th>Total Show Profit</th>';
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
			echo '<td>' . esc_html( $this->format_money( $row['expense_total'] ?? 0 ) ) . '</td>';
			echo '<td>' . $this->format_profit_markup( $row['profit_total'] ?? 0 ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '<tfoot><tr>';
		echo '<th colspan="2">Filtered Totals</th>';
		echo '<th>' . esc_html( (string) ( $totals['sales_count'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( $this->format_money( $totals['gross_total'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( $this->format_money( $totals['net_total'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( $this->format_money( $totals['discount_total'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( $this->format_money( $totals['tip_total'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( (string) ( $totals['attribution_count'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( $this->format_money( $totals['commission_total'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( $this->format_money( $totals['payout_total'] ?? 0 ) ) . '</th>';
		echo '<th>' . esc_html( $this->format_money( $totals['expense_total'] ?? 0 ) ) . '</th>';
		echo '<th>' . $this->format_profit_markup( $totals['profit_total'] ?? 0 ) . '</th>';
		echo '</tr></tfoot>';
		echo '</table>';
	}

	private function render_summary_cards( array $totals ): void {
		$cards = array(
			'Gross Sales'       => (float) ( $totals['gross_total'] ?? 0 ),
			'Net Sales'         => (float) ( $totals['net_total'] ?? 0 ),
			'Vendor Payout'     => (float) ( $totals['payout_total'] ?? 0 ),
			'Expenses'          => (float) ( $totals['expense_total'] ?? 0 ),
			'Total Show Profit' => (float) ( $totals['profit_total'] ?? 0 ),
		);

		echo '<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin: 0 0 16px 0;">';
		foreach ( $cards as $label => $value ) {
			$is_profit = 'Total Show Profit' === $label;
			$style     = $is_profit && $value < 0
				? 'border-left:4px solid #d63638;background:#fff5f5;'
				: 'border-left:4px solid #2271b1;background:#fff;';

			echo '<div style="padding:12px;border:1px solid #dcdcde;border-radius:4px;' . esc_attr( $style ) . '">';
			echo '<div style="font-size:12px;color:#50575e;text-transform:uppercase;letter-spacing:.03em;">' . esc_html( $label ) . '</div>';
			echo '<div style="font-size:20px;font-weight:600;margin-top:6px;">' . esc_html( $this->format_money( $value ) ) . '</div>';
			echo '</div>';
		}
		echo '</div>';
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

	private function format_profit_markup( $value ): string {
		$amount = (float) $value;
		$style  = $amount < 0 ? 'color:#b32d2e;font-weight:700;' : 'color:#135e2b;font-weight:700;';

		return '<span style="' . esc_attr( $style ) . '">' . esc_html( $this->format_money( $amount ) ) . '</span>';
	}
}

