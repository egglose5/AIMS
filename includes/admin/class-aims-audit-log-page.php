<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Audit_Log_Page {
	public const PAGE_SLUG = 'aims-activity-log';

	private $data_provider;

	public function __construct( AIMS_Audit_Log_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$model = $this->data_provider->get_page_model( $this->get_filters() );
		$rows  = (array) ( $model['rows'] ?? array() );

		echo '<div class="wrap">';
		echo '<h1>AIMS Activity Log</h1>';
		echo '<p>Structured plugin-side action proof for the WordPress control surface. The log stays append-only and lean so the operational database does not carry audit lookup weight.</p>';

		$this->render_summary( (array) ( $model['summary'] ?? array() ) );
		$this->render_filters( (array) ( $model['filters'] ?? array() ) );

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No matching activity log entries were found.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Time</th>';
		echo '<th>User</th>';
		echo '<th>Capability</th>';
		echo '<th>Action</th>';
		echo '<th>Reference</th>';
		echo '<th>Status</th>';
		echo '<th>Surface</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['ts'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['user_name'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $row['capability_key'] ?? '' ) ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) ( $row['action_key'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $row['reference_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $row['surface'] ?? '' ) ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_summary( array $summary ): void {
		echo '<div class="notice notice-info inline"><p>';
		echo '<strong>Rows:</strong> ' . esc_html( (string) ( $summary['total'] ?? 0 ) );
		echo ' | <strong>Success:</strong> ' . esc_html( (string) ( $summary['successes'] ?? 0 ) );
		echo ' | <strong>Other:</strong> ' . esc_html( (string) ( $summary['failures'] ?? 0 ) );
		echo ' | <strong>Latest:</strong> ' . esc_html( (string) ( $summary['latest_ts'] ?? 'n/a' ) );
		echo '</p></div>';
	}

	private function render_filters( array $filters ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:16px 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="aims-audit-user-id">User ID</label></th><td><input id="aims-audit-user-id" type="number" min="0" step="1" class="small-text" name="aims_audit_user_id" value="' . esc_attr( (string) ( $filters['user_id'] ?? 0 ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-audit-action">Action</label></th><td><input id="aims-audit-action" type="text" class="regular-text" name="aims_audit_action" value="' . esc_attr( (string) ( $filters['action_key'] ?? '' ) ) . '" placeholder="movement_send" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-audit-status">Status</label></th><td><select id="aims-audit-status" name="aims_audit_status"><option value="">All</option><option value="success"' . selected( (string) ( $filters['status'] ?? '' ), 'success', false ) . '>Success</option><option value="failed"' . selected( (string) ( $filters['status'] ?? '' ), 'failed', false ) . '>Failed</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="aims-audit-search">Search</label></th><td><input id="aims-audit-search" type="text" class="regular-text" name="aims_audit_search" value="' . esc_attr( (string) ( $filters['search'] ?? '' ) ) . '" placeholder="SKU, reference, or capability" /></td></tr>';
		echo '<tr><th scope="row"><label for="aims-audit-limit">Limit</label></th><td><input id="aims-audit-limit" type="number" min="1" max="200" step="1" class="small-text" name="aims_audit_limit" value="' . esc_attr( (string) ( $filters['limit'] ?? 50 ) ) . '" /></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Filter Activity', 'secondary', '', false );
		echo '</form>';
	}

	private function get_filters(): array {
		return array(
			'user_id'    => isset( $_GET['aims_audit_user_id'] ) ? absint( wp_unslash( $_GET['aims_audit_user_id'] ) ) : 0,
			'action_key' => isset( $_GET['aims_audit_action'] ) ? sanitize_key( wp_unslash( $_GET['aims_audit_action'] ) ) : '',
			'status'     => isset( $_GET['aims_audit_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_audit_status'] ) ) : '',
			'search'     => isset( $_GET['aims_audit_search'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_audit_search'] ) ) : '',
			'limit'      => isset( $_GET['aims_audit_limit'] ) ? absint( wp_unslash( $_GET['aims_audit_limit'] ) ) : 50,
		);
	}
}
