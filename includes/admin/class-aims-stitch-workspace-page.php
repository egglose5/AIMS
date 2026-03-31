<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Workspace_Page {
	public const PAGE_SLUG = 'aims-stitch-workspace';

	private $data_provider;

	public function __construct( AIMS_Stitch_Workspace_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$model = $this->data_provider->get_page_model( wp_unslash( $_GET ) );

		echo '<div class="aims-stitch-workspace-wrap">';
		echo '<style>
			.aims-stitch-workspace-wrap { display:flex; flex-direction:column; gap:24px; }
			.aims-stitch-workspace-layout { display:grid; grid-template-columns:minmax(0,2fr) minmax(280px,360px); gap:24px; align-items:start; }
			.aims-stitch-workspace-card { background:#fff; border:1px solid #dcdcde; }
			.aims-stitch-workspace-card h2, .aims-stitch-workspace-card h3 { margin:0; padding:16px 18px; border-bottom:1px solid #dcdcde; }
			.aims-stitch-workspace-card .inside { padding:18px; }
			.aims-stitch-workspace-meta { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
			.aims-stitch-workspace-meta div { background:#f6f7f7; border:1px solid #dcdcde; padding:10px 12px; }
			@media (max-width: 960px) {
				.aims-stitch-workspace-layout, .aims-stitch-workspace-meta { grid-template-columns:1fr; }
			}
		</style>';

		echo '<div class="aims-stitch-workspace-layout">';
		echo '<div class="aims-stitch-workspace-card">';
		echo '<h3>Selected Stitch Job</h3>';
		echo '<div class="inside">';

		if ( ! empty( $model['selection_message'] ) ) {
			echo '<p>' . esc_html( (string) $model['selection_message'] ) . '</p>';
		}

		if ( empty( $model['selected_job'] ) ) {
			echo '<p>' . esc_html( (string) ( $model['empty_message'] ?? 'No stitch job is selected.' ) ) . '</p>';
			echo '</div></div>';
			echo '<div class="aims-stitch-workspace-card">';
			echo '<h3>Stitcher Context</h3>';
			echo '<div class="inside"><p style="margin-bottom:0;">No selected stitch job is available yet.</p></div>';
			echo '</div></div></div>';
			return;
		}

		$selected_job = (array) $model['selected_job'];
		$summary      = (array) ( $model['workspace_summary'] ?? array() );
		echo '<div class="aims-stitch-workspace-meta">';
		$this->render_meta_card( 'Job', (string) ( $selected_job['job_name'] ?? '' ) );
		$this->render_meta_card( 'Stitcher', (string) ( $selected_job['stitcher_name'] ?: 'Unassigned' ) );
		$this->render_meta_card( 'Lines', (string) ( $summary['line_count'] ?? 0 ) );
		$this->render_meta_card( 'Progress', number_format( (float) ( $summary['progress_percent'] ?? 0 ), 1, '.', '' ) . '%' );
		echo '</div>';

		if ( ! empty( $selected_job['notes'] ) ) {
			echo '<p><strong>Producer Notes:</strong> ' . esc_html( (string) $selected_job['notes'] ) . '</p>';
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>Job Code</th><th>Status</th><th>Total Qty</th><th>Completed</th><th>Remaining</th></tr></thead>';
		echo '<tbody><tr>';
		echo '<td><code>' . esc_html( (string) ( $selected_job['job_code'] ?? '—' ) ) . '</code></td>';
		echo '<td>' . esc_html( ucwords( str_replace( '_', ' ', (string) ( $selected_job['status'] ?? 'open' ) ) ) ) . '</td>';
		echo '<td>' . esc_html( number_format( (float) ( $summary['total_quantity'] ?? 0 ), 2, '.', '' ) ) . '</td>';
		echo '<td>' . esc_html( number_format( (float) ( $summary['completed_quantity'] ?? 0 ), 2, '.', '' ) ) . '</td>';
		echo '<td>' . esc_html( number_format( (float) ( $summary['remaining_quantity'] ?? 0 ), 2, '.', '' ) ) . '</td>';
		echo '</tr></tbody>';
		echo '</table>';

		echo '<h3 style="margin-top:24px;">Job Lines</h3>';
		if ( empty( $model['lines'] ) ) {
			echo '<p>No stitch job lines are available for this job yet.</p>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr><th>Line</th><th>Product</th><th>SKU</th><th>Qty</th><th>Completed</th><th>Remaining</th><th>Status</th></tr></thead>';
			echo '<tbody>';
			foreach ( (array) $model['lines'] as $line ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $line['line_id'] ?? 0 ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $line['product_name'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $line['product_sku'] ?? '—' ) ) . '</code></td>';
				echo '<td>' . esc_html( number_format( (float) ( $line['quantity'] ?? 0 ), 2, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $line['completed'] ?? 0 ), 2, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $line['remaining'] ?? 0 ), 2, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( ucwords( str_replace( '_', ' ', (string) ( $line['status'] ?? 'open' ) ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody>';
			echo '</table>';
		}

		echo '</div>';
		echo '</div>';

		echo '<div class="aims-stitch-workspace-card">';
		echo '<h3>Stitcher Context</h3>';
		echo '<div class="inside">';
		$this->render_stitcher_context( (array) ( $model['assignment_context'] ?? array() ), (array) ( $model['progress_summary'] ?? array() ) );
		echo '<div style="margin-top:18px;">';
		echo '<strong>Workspace Summary</strong>';
		echo '<table class="widefat striped" style="margin-top:8px;"><tbody>';
		$this->render_summary_row( 'Total Lines', (string) ( $summary['line_count'] ?? 0 ) );
		$this->render_summary_row( 'Progress', number_format( (float) ( $summary['progress_percent'] ?? 0 ), 1, '.', '' ) . '%' );
		$this->render_summary_row( 'Open Lines', (string) ( $summary['open_lines'] ?? 0 ) );
		$this->render_summary_row( 'Remaining Qty', number_format( (float) ( $summary['remaining_quantity'] ?? 0 ), 2, '.', '' ) );
		echo '</tbody></table>';
		echo '</div>';

		if ( ! empty( $model['safe_actions_enabled'] ) ) {
			echo '<p style="margin-top:16px;">Producer-safe actions are connected. Add buttons only when a stitch service lands.</p>';
		} else {
			echo '<p style="margin-top:16px;">Action wiring is intentionally inert until a stitch service lands. The page stays read-first.</p>';
		}

		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	private function render_meta_card( string $label, string $value ): void {
		echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
	}

	private function render_stitcher_context( array $context, array $progress_summary ): void {
		if ( empty( $context ) ) {
			echo '<p style="margin-top:0;">No stitcher assignment context is connected yet.</p>';
		} else {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $context as $key => $value ) {
				echo '<tr><th scope="row">' . esc_html( ucwords( str_replace( '_', ' ', (string) $key ) ) ) . '</th><td>' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $progress_summary ) ) {
			echo '<div style="margin-top:18px;">';
			echo '<strong>Progress Summary</strong>';
			echo '<table class="widefat striped" style="margin-top:8px;"><tbody>';
			foreach ( $progress_summary as $key => $value ) {
				echo '<tr><th scope="row">' . esc_html( ucwords( str_replace( '_', ' ', (string) $key ) ) ) . '</th><td>' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}
	}

	private function render_summary_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
