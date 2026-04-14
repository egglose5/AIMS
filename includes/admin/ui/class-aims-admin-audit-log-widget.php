<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Audit_Log_Widget extends AIMS_Admin_Widget {
	public function render(): void {
		$rows    = $this->get_data( 'rows', array() );
		$summary = $this->get_data( 'summary', array() );
		$filters = $this->get_data( 'filters', array() );

		?>
		<div class="aims-widget aims-audit-log-widget">
			<div class="notice notice-info inline" style="margin-bottom: 20px;">
				<p>
					<strong>Rows:</strong> <?php echo esc_html( (string) ( $summary['total'] ?? 0 ) ); ?>
					 | <strong>Success:</strong> <?php echo esc_html( (string) ( $summary['successes'] ?? 0 ) ); ?>
					 | <strong>Other:</strong> <?php echo esc_html( (string) ( $summary['failures'] ?? 0 ) ); ?>
					 | <strong>Latest:</strong> <?php echo esc_html( (string) ( $summary['latest_ts'] ?? 'n/a' ) ); ?>
				</p>
			</div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
				<input type="hidden" name="page" value="aims-activity-log" />
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="aims-audit-user-id">User ID</label></th>
							<td><input id="aims-audit-user-id" type="number" min="0" step="1" class="small-text" name="aims_audit_user_id" value="<?php echo esc_attr( (string) ( $filters['user_id'] ?? 0 ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aims-audit-action">Action</label></th>
							<td><input id="aims-audit-action" type="text" class="regular-text" name="aims_audit_action" value="<?php echo esc_attr( (string) ( $filters['action_key'] ?? '' ) ); ?>" placeholder="movement_send" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aims-audit-status">Status</label></th>
							<td>
								<select id="aims-audit-status" name="aims_audit_status">
									<option value="">All</option>
									<option value="success" <?php selected( (string) ( $filters['status'] ?? '' ), 'success' ); ?>>Success</option>
									<option value="failed" <?php selected( (string) ( $filters['status'] ?? '' ), 'failed' ); ?>>Failed</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aims-audit-search">Search (SKU/Ref)</label></th>
							<td>
								<?php
								$search_input = new AIMS_Admin_Input_Element( array(
									'id'          => 'aims-audit-search',
									'name'        => 'aims_audit_search',
									'value'       => (string) ( $filters['search'] ?? '' ),
									'placeholder' => function_exists( '__' ) ? __( 'SKU, reference, or capability', 'ai-man-sys' ) : 'SKU, reference, or capability',
									'scan'        => true,
								) );
								echo $search_input->render();
								?>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( 'Filter Log', 'secondary', 'aims_audit_filter', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Time</th>
						<th>User</th>
						<th>Capability</th>
						<th>Action</th>
						<th>Reference</th>
						<th>Status</th>
						<th>Surface</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7">No audit events found matching criteria.</td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['ts'] ?? $row['created_at'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['user_name'] ?? $row['user_display_name'] ?? '#' . ( $row['user_id'] ?? '0' ) ) ); ?></td>
								<td><code><?php echo esc_html( (string) ( $row['capability_key'] ?? '' ) ); ?></code></td>
								<td><code><?php echo esc_html( (string) ( $row['action_key'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) ( $row['reference_id'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
								<td><code><?php echo esc_html( (string) ( $row['surface'] ?? '' ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
