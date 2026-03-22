<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Queue_Page {
	private const ACTION_NONCE = 'aims_stitch_queue_action';

	private $data_provider;

	public function __construct( AIMS_Stitch_Queue_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_PORTAL_STITCH ) && ! current_user_can( AIMS_Capabilities::CAP_MANAGE_STITCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access stitch jobs.', 'ai-man-sys' ) );
		}

		$notice = $this->handle_actions();
		$rows   = $this->data_provider->get_rows();
		$summary = $this->data_provider->get_summary();
		$can_manage = $this->data_provider->user_can_manage_stitch_jobs();

		echo '<div class="wrap">';
		echo '<h1>Stitch Queue</h1>';
		echo '<p>Use this queue to receive stitch jobs, move them into work, mark them ready, and close them when finished.</p>';
		echo '<p><strong>Actions:</strong> ' . esc_html( $can_manage ? 'Transition controls are enabled for this account.' : 'This account can view the queue, but transitions are read-only.' ) . '</p>';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-' . esc_attr( $this->notice_type( $notice ) ) . ' inline" style="margin:16px 0 0;"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 24px;">';
		$this->render_summary_card( 'Total', (int) ( $summary['total'] ?? 0 ) );
		$this->render_summary_card( 'Open', (int) ( $summary['open'] ?? 0 ) );
		$this->render_summary_card( 'Queued', (int) ( $summary['queued'] ?? 0 ) );
		$this->render_summary_card( 'Received', (int) ( $summary['received'] ?? 0 ) );
		$this->render_summary_card( 'In progress', (int) ( $summary['in_progress'] ?? 0 ) );
		$this->render_summary_card( 'Ready for pickup', (int) ( $summary['ready_for_pickup'] ?? 0 ) );
		$this->render_summary_card( 'Closed', (int) ( $summary['closed'] ?? 0 ) );
		echo '</div>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No stitch jobs are queued yet.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Job</th>';
		echo '<th>Status</th>';
		echo '<th>Priority</th>';
		echo '<th>Due</th>';
		echo '<th>Assigned</th>';
		echo '<th>Next Step</th>';
		echo '<th>Actions</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$next = ! empty( $row['next_transition'] ) && is_array( $row['next_transition'] ) ? $row['next_transition'] : array();
			$next_label = ! empty( $next['label'] ) ? (string) $next['label'] : '';
			$next_status = ! empty( $next['next_status'] ) ? (string) $next['next_status'] : '';
			$next_available = ! empty( $next['available'] );
			$can_advance = ! empty( $next['can_initiate'] );
			$assigned = ! empty( $row['assigned_user_id'] ) ? 'User #' . (int) $row['assigned_user_id'] : 'Unassigned';
			$next_display = $next_available ? $next_label : 'No next step';
			if ( $next_available && ! $can_advance ) {
				$next_display = $next_label . ' (manager only)';
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $row['job_code'] ) . '</strong></td>';
			echo '<td>' . esc_html( (string) $row['status_label'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['priority_label'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['due_label'] ) . ( ! empty( $row['is_overdue'] ) ? '<br><span style="color:#b32d2e;font-size:12px;">Overdue</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $assigned ) . '</td>';
			echo '<td>' . esc_html( $next_display ) . '</td>';
			echo '<td>' . $this->render_transition_form( (int) $row['id'], $next_status, $next_label, $can_advance ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function handle_actions(): string {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return '';
		}

		$action = sanitize_key( $_POST['aims_stitch_queue_action'] ?? '' );
		if ( 'transition_job' !== $action ) {
			return '';
		}

		check_admin_referer( self::ACTION_NONCE, 'aims_stitch_queue_nonce' );

		$job_id = absint( $_POST['job_id'] ?? 0 );
		$next_status = sanitize_key( $_POST['next_status'] ?? '' );
		if ( $job_id <= 0 || '' === $next_status ) {
			return 'Missing stitch job details.';
		}

		if ( ! $this->data_provider->user_can_manage_stitch_jobs() ) {
			return 'You do not have permission to manage stitch jobs.';
		}

		$result = $this->data_provider->get_workflow_service()->transition_job_status(
			$job_id,
			$next_status,
			get_current_user_id(),
			array()
		);

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		if ( empty( $result['message'] ) ) {
			return 'The stitch job was updated.';
		}

		return (string) $result['message'];
	}

	private function render_summary_card( string $label, int $count ): void {
		echo '<div class="notice notice-info inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $count ) . '</strong> ' . esc_html( $label ) . '</div>';
	}

	private function render_transition_form( int $job_id, string $next_status, string $next_label, bool $enabled ): string {
		if ( ! $enabled || '' === $next_status ) {
			return '<span style="color:#666;">Transition unavailable</span>';
		}

		ob_start();
		echo '<form method="post" style="display:inline-block;margin:0;">';
		wp_nonce_field( self::ACTION_NONCE, 'aims_stitch_queue_nonce' );
		echo '<input type="hidden" name="job_id" value="' . esc_attr( (string) $job_id ) . '">';
		echo '<input type="hidden" name="next_status" value="' . esc_attr( $next_status ) . '">';
		echo '<input type="hidden" name="aims_stitch_queue_action" value="transition_job">';
		echo '<button type="submit" class="button button-small">' . esc_html( $next_label ) . '</button>';
		echo '</form>';

		return (string) ob_get_clean();
	}

	private function notice_type( string $notice ): string {
		$lower = strtolower( $notice );
		if ( false !== strpos( $lower, 'missing' ) || false !== strpos( $lower, 'cannot' ) || false !== strpos( $lower, 'unable' ) ) {
			return 'error';
		}

		return 'success';
	}
}
