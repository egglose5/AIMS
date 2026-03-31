<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Jobs_Page {
	private $data_provider;

	public function __construct( AIMS_Stitch_Jobs_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$model = $this->data_provider->get_page_model();

		echo '<div class="aims-stitch-wrap">';
		echo '<style>
			.aims-stitch-wrap { display:flex; flex-direction:column; gap:24px; }
			.aims-stitch-toolbar { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
			.aims-stitch-layout { display:grid; grid-template-columns:minmax(0,2fr) minmax(280px,360px); gap:24px; align-items:start; }
			.aims-stitch-card { background:#fff; border:1px solid #dcdcde; }
			.aims-stitch-card h2, .aims-stitch-card h3 { margin:0; padding:16px 18px; border-bottom:1px solid #dcdcde; }
			.aims-stitch-card .inside { padding:18px; }
			.aims-stitch-kpi { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
			.aims-stitch-kpi div { background:#f6f7f7; border:1px solid #dcdcde; padding:12px; }
			.aims-stitch-kpi strong { display:block; font-size:20px; margin-top:4px; }
			.aims-stitch-directory { display:flex; flex-direction:column; gap:12px; }
			.aims-stitch-directory article { border:1px solid #dcdcde; background:#fff; padding:12px 14px; }
			@media (max-width: 960px) {
				.aims-stitch-layout, .aims-stitch-kpi { grid-template-columns:1fr; }
				.aims-stitch-toolbar { flex-direction:column; }
			}
		</style>';

		echo '<div class="aims-stitch-toolbar">';
		echo '<div>';
		echo '<h2>Stitch Jobs</h2>';
		echo '<p>Producer-owned stitching order management. Stitch jobs stay stitch jobs; management access belongs to producer responsibility, while stitchers remain separate execution actors.</p>';
		echo '</div>';
		echo '</div>';

		$this->render_notice();

		echo '<div class="aims-stitch-layout">';
		echo '<div class="aims-stitch-card">';
		echo '<h3>Open Stitch Jobs</h3>';
		echo '<div class="inside">';

		$summary = (array) ( $model['summary'] ?? array() );
		echo '<div class="aims-stitch-kpi" style="margin-bottom:18px;">';
		$this->render_kpi_card( 'Total Jobs', (string) ( $summary['total_jobs'] ?? 0 ) );
		$this->render_kpi_card( 'Open', (string) ( $summary['open_jobs'] ?? 0 ) );
		$this->render_kpi_card( 'In Progress', (string) ( $summary['in_progress'] ?? 0 ) );
		$this->render_kpi_card( 'Completed', (string) ( $summary['completed_jobs'] ?? 0 ) );
		echo '</div>';

		if ( empty( $model['jobs'] ) ) {
			echo '<p>' . esc_html( (string) ( $model['empty_message'] ?? 'No stitch jobs are currently available.' ) ) . '</p>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr><th>Job</th><th>Stitcher</th><th>Lines</th><th>Qty</th><th>Progress</th><th>Status</th><th>Actions</th></tr></thead>';
			echo '<tbody>';
			foreach ( (array) $model['jobs'] as $job ) {
				$workspace_url = (string) ( $job['workspace_url'] ?? $this->data_provider->get_workspace_url( (int) ( $job['job_id'] ?? 0 ) ) );
				echo '<tr>';
				echo '<td>';
				echo '<div style="font-weight:600;">' . esc_html( (string) ( $job['job_name'] ?? '' ) ) . '</div>';
				if ( '' !== (string) ( $job['job_code'] ?? '' ) ) {
					echo '<code>' . esc_html( (string) $job['job_code'] ) . '</code>';
				}
				if ( ! empty( $job['notes'] ) ) {
					echo '<div style="color:#666;margin-top:4px;">' . esc_html( (string) $job['notes'] ) . '</div>';
				}
				echo '</td>';
				echo '<td>' . esc_html( (string) ( $job['stitcher_name'] ?: 'Unassigned' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $job['line_count'] ?? 0 ), 0, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $job['total_quantity'] ?? 0 ), 2, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $job['progress_percent'] ?? 0 ), 1, '.', '' ) ) . '%</td>';
				echo '<td>' . esc_html( ucwords( str_replace( '_', ' ', (string) ( $job['status'] ?? 'open' ) ) ) ) . '</td>';
				echo '<td><a class="button button-small" href="' . esc_url( $workspace_url ) . '">Open Workspace</a></td>';
				echo '</tr>';
			}
			echo '</tbody>';
			echo '</table>';
		}

		echo '</div>';
		echo '</div>';

		echo '<div>';
		echo '<div class="aims-stitch-card">';
		echo '<h3>Stitcher Assignment Context</h3>';
		echo '<div class="inside">';
		$this->render_stitcher_directory( (array) ( $model['stitcher_directory'] ?? array() ) );
		echo '</div>';
		echo '</div>';

		echo '<div class="aims-stitch-card" style="margin-top:24px;">';
		echo '<h3>Producer Notes</h3>';
		echo '<div class="inside">';
		echo '<p style="margin-top:0;">This workspace is intentionally read-focused until a stitch service lands. The module already exposes thin action seams for future producer-safe updates.</p>';
		echo '<p style="margin-bottom:0;">No label rendering internals or stitcher portal pages are exposed here.</p>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	private function render_stitcher_directory( array $stitcher_directory ): void {
		if ( empty( $stitcher_directory ) ) {
			echo '<p style="margin-bottom:0;">No stitcher assignment context is connected yet.</p>';
			return;
		}

		echo '<div class="aims-stitch-directory">';
		foreach ( $stitcher_directory as $entry ) {
			echo '<article>';
			echo '<strong>' . esc_html( (string) ( $entry['stitcher_name'] ?? 'Unassigned' ) ) . '</strong>';
			echo '<div style="margin-top:6px;">Jobs: ' . esc_html( (string) ( $entry['job_count'] ?? 0 ) ) . ' | Open: ' . esc_html( (string) ( $entry['open_jobs'] ?? 0 ) ) . ' | In progress: ' . esc_html( (string) ( $entry['in_progress'] ?? 0 ) ) . ' | Completed: ' . esc_html( (string) ( $entry['completed'] ?? 0 ) ) . '</div>';
			echo '<div>Lines: ' . esc_html( (string) ( $entry['total_lines'] ?? 0 ) ) . ' | Qty: ' . esc_html( number_format( (float) ( $entry['total_quantity'] ?? 0 ), 2, '.', '' ) ) . '</div>';
			echo '</article>';
		}
		echo '</div>';
	}

	private function render_kpi_card( string $label, string $value ): void {
		echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
	}

	private function render_notice(): void {
		$status  = isset( $_GET['aims_stitch_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_stitch_status'] ) ) : '';
		$message = isset( $_GET['aims_stitch_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_stitch_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}
}
