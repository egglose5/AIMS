<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Reports_Module implements AIMS_Module {
	public function register(): void {
		add_action( 'admin_post_aims_reports_export_event_sales', array( $this, 'handle_export_event_sales' ) );
	}

	public function render_shell(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to view reports.', 'ai-man-sys' ) );
		}

		$page = new AIMS_Reports_Event_Sales_Page( new AIMS_Reports_Event_Sales_Data_Provider() );

		echo '<div class="wrap">';
		echo '<h1>Reports &amp; Analytics</h1>';
		$this->render_status_notice();
		$page->render();
		echo '</div>';
	}

	public function handle_export_event_sales(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_reports_export_event_sales' );

		$event_id = isset( $_POST['event_id'] ) ? max( 0, (int) wp_unslash( $_POST['event_id'] ) ) : 0;
		$rows     = ( new AIMS_Reports_Event_Sales_Data_Provider() )->get_rows( $event_id );

		if ( empty( $rows ) ) {
			$redirect = add_query_arg(
				array(
					'page'                 => 'aims-reports',
					'event_id'             => $event_id,
					'aims_reports_status'  => 'error',
					'aims_reports_message' => 'No rows available to export.',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$filename = 'aims-event-sales-report-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		fputcsv(
			$output,
			array(
				'event_id',
				'event_name',
				'event_code',
				'status',
				'sales_count',
				'gross_total',
				'net_total',
				'discount_total',
				'tip_total',
				'attribution_count',
				'commission_total',
				'payout_total',
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					(int) ( $row['event_id'] ?? 0 ),
					(string) ( $row['event_name'] ?? '' ),
					(string) ( $row['event_code'] ?? '' ),
					(string) ( $row['status'] ?? '' ),
					(int) ( $row['sales_count'] ?? 0 ),
					(float) ( $row['gross_total'] ?? 0 ),
					(float) ( $row['net_total'] ?? 0 ),
					(float) ( $row['discount_total'] ?? 0 ),
					(float) ( $row['tip_total'] ?? 0 ),
					(int) ( $row['attribution_count'] ?? 0 ),
					(float) ( $row['commission_total'] ?? 0 ),
					(float) ( $row['payout_total'] ?? 0 ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	private function render_status_notice(): void {
		$status  = isset( $_GET['aims_reports_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_reports_status'] ) ) : '';
		$message = isset( $_GET['aims_reports_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_reports_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}
}
