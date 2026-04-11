<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Reports_Module implements AIMS_Module {
	private $responsibility_auth;
	private $data_provider;

	public function __construct( AIMS_Responsibility_Authorization_Service $responsibility_auth = null, AIMS_Reports_Event_Sales_Data_Provider $data_provider = null ) {
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
		$this->data_provider       = $data_provider ?: new AIMS_Reports_Event_Sales_Data_Provider();
	}

	public function register(): void {
		add_action( 'admin_post_aims_reports_export_event_sales', array( $this, 'handle_export_event_sales' ) );
	}

	public function render_shell(): void {
		if ( ! $this->can_view_reports() ) {
			wp_die( esc_html__( 'You do not have permission to view reports.', 'ai-man-sys' ) );
		}

		$page = new AIMS_Reports_Event_Sales_Page( $this->data_provider );

		echo '<div class="wrap">';
		echo '<h1>Reports &amp; Analytics</h1>';
		$this->render_status_notice();
		$page->render();
		echo '</div>';
	}

	public function handle_export_event_sales(): void {
		if ( ! $this->can_view_reports() ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_reports_export_event_sales' );

		$event_id = isset( $_POST['event_id'] ) ? max( 0, (int) wp_unslash( $_POST['event_id'] ) ) : 0;
		$rows     = $this->data_provider->get_rows( $event_id );

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
		fputcsv( $output, $this->get_event_sales_export_header_row() );

		foreach ( $rows as $row ) {
			fputcsv( $output, $this->build_event_sales_export_row( $row ) );
		}

		fclose( $output );
		exit;
	}

	private function get_event_sales_export_header_row(): array {
		return array(
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
			'expense_total',
			'total_show_profit',
		);
	}

	private function build_event_sales_export_row( array $row ): array {
		return array(
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
			(float) ( $row['expense_total'] ?? 0 ),
			(float) ( $row['profit_total'] ?? 0 ),
		);
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

	private function can_view_reports(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $user_id > 0 && is_object( $this->responsibility_auth ) && $this->responsibility_auth->can_view_reports( $user_id );
	}
}
