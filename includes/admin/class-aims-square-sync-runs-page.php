<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Runs_Page {
	private $data_provider;

	public function __construct( AIMS_Square_Sync_Runs_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();
		$summary = $this->data_provider->get_summary();

		echo '<div class="wrap">';
		echo '<h1>Sync Runs / Replay</h1>';
		echo '<p>Sync runs and replay controls are capability-gated and nonce-protected. Replay and undo requests are idempotent per run so duplicate submissions are blocked.</p>';
		$this->render_status_notice();
		$this->render_summary_panel( $summary );

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No sync runs are currently available for replay.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Run</th>';
		echo '<th>Source</th>';
		echo '<th>Watermark</th>';
		echo '<th>Status</th>';
		echo '<th>Processed</th>';
		echo '<th>Errors</th>';
		echo '<th>Completed</th>';
		echo '<th>Actions</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['run_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['source_system'] ) . '</td>';
			echo '<td>' . esc_html( $row['sync_watermark'] ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['processed_records'] ) . '</td>';
			echo '<td>' . esc_html( $row['error_count'] ) . '</td>';
			echo '<td>' . esc_html( $row['completed_at'] ) . '</td>';
			echo '<td>' . $this->render_actions_cell( $row ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_status_notice(): void {
		$status  = isset( $_GET['aims_square_sync_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_square_sync_status'] ) ) : '';
		$message = isset( $_GET['aims_square_sync_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_square_sync_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_summary_panel( array $summary ): void {
		echo '<div class="notice notice-info inline"><p>';
		echo '<strong>Telemetry:</strong> ';
		echo 'Runs: ' . esc_html( (string) ( $summary['total_runs'] ?? 0 ) );
		echo ' | Processed rows: ' . esc_html( (string) ( $summary['total_processed_records'] ?? 0 ) );
		echo ' | Errors: ' . esc_html( (string) ( $summary['total_error_count'] ?? 0 ) );
		echo ' | Last status: ' . esc_html( (string) ( $summary['last_sync_status'] ?? 'never' ) );
		echo ' | Last completed: ' . esc_html( (string) ( $summary['last_sync_completed_at'] ?? '' ) );
		echo '</p></div>';
	}

	private function render_actions_cell( array $row ): string {
		$run_id = (int) ( $row['run_id'] ?? 0 );
		if ( $run_id <= 0 ) {
			return '';
		}

		$actions = array();

		if ( ! empty( $row['can_replay'] ) ) {
			$actions[] = $this->render_action_form( 'aims_square_replay', 'Replay', $run_id );
		}

		if ( ! empty( $row['can_undo'] ) ) {
			$actions[] = $this->render_action_form( 'aims_square_undo', 'Undo', $run_id );
		}

		if ( empty( $actions ) ) {
			return '<em>No actions available</em>';
		}

		return implode( '', array_map( static function ( string $form_html ): string {
			return '<div style="margin-bottom:6px;">' . $form_html . '</div>';
		}, $actions ) );
	}

	private function render_action_form( string $action, string $label, int $run_id ): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run_id ); ?>">
			<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'aims_square_sync_action', '_aims_nonce' ); } ?>
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
