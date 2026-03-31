<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="aims-stitch-portal">
	<style>
		.aims-stitch-portal { max-width: 980px; margin: 0 auto; padding: 16px; }
		.aims-stitch-portal .portal-shell,
		.aims-stitch-portal .portal-card,
		.aims-stitch-portal .portal-status,
		.aims-stitch-portal .portal-job,
		.aims-stitch-portal .portal-bucket { border: 1px solid #dcdcde; border-radius: 18px; background: #fff; }
		.aims-stitch-portal .portal-shell { padding: 16px; box-shadow: 0 6px 30px rgba(0,0,0,.04); }
		.aims-stitch-portal .portal-card { padding: 14px; margin: 0 0 12px; }
		.aims-stitch-portal .portal-grid { display: grid; gap: 12px; }
		.aims-stitch-portal .portal-job,
		.aims-stitch-portal .portal-bucket { padding: 14px; }
		.aims-stitch-portal .portal-job-list,
		.aims-stitch-portal .portal-bucket-list { display: grid; gap: 12px; }
		.aims-stitch-portal .portal-status { padding: 12px 14px; margin: 0 0 12px; }
		.aims-stitch-portal .portal-muted { color: #646970; }
		.aims-stitch-portal .portal-meta { margin: 6px 0 0; color: #646970; }
		.aims-stitch-portal .portal-content-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
		.aims-stitch-portal .portal-content-table th,
		.aims-stitch-portal .portal-content-table td { border-top: 1px solid #dcdcde; padding: 8px 6px; text-align: left; vertical-align: top; }
		.aims-stitch-portal .portal-actions { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
		.aims-stitch-portal .portal-actions button { min-width: 160px; }
		.aims-stitch-portal textarea { width: 100%; min-height: 90px; box-sizing: border-box; }
		@media (min-width: 768px) {
			.aims-stitch-portal .portal-grid { grid-template-columns: 1.15fr .85fr; }
		}
	</style>

	<div class="portal-shell">
		<h1><?php echo esc_html( $portal_title ); ?></h1>
		<p class="portal-muted"><?php echo esc_html( $portal_description ); ?></p>

		<?php if ( empty( $portal_model['logged_in'] ) ) : ?>
			<div class="portal-status">
				<p><?php esc_html_e( 'Stitcher portal access requires a logged-in stitcher account.', 'ai-man-sys' ); ?></p>
				<?php if ( ! empty( $portal_model['login_url'] ) ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( $portal_model['login_url'] ); ?>"><?php esc_html_e( 'Log In', 'ai-man-sys' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php elseif ( empty( $portal_model['can_view'] ) ) : ?>
			<div class="portal-status">
				<p><?php esc_html_e( 'This account is not authorized for the stitcher portal.', 'ai-man-sys' ); ?></p>
			</div>
		<?php else : ?>
			<?php if ( ! empty( $portal_model['status_message']['message'] ) ) : ?>
				<div class="portal-status">
					<p><?php echo esc_html( (string) $portal_model['status_message']['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="portal-card">
				<h2><?php esc_html_e( 'Current Stitch Custody', 'ai-man-sys' ); ?></h2>
				<p class="portal-muted">
					<?php
					echo esc_html(
						$portal_model['stitcher_name']
							? sprintf( 'Signed in as %s.', $portal_model['stitcher_name'] )
							: 'Signed in stitcher.'
					);
					?>
				</p>

				<?php if ( empty( $portal_model['stitcher_buckets'] ) ) : ?>
					<p class="portal-muted"><?php esc_html_e( 'No stitch custody buckets are assigned to this account yet.', 'ai-man-sys' ); ?></p>
				<?php else : ?>
					<div class="portal-bucket-list">
						<?php foreach ( (array) $portal_model['stitcher_buckets'] as $bucket ) : ?>
							<div class="portal-bucket">
								<strong><?php echo esc_html( (string) ( $bucket['display_label'] ?? '' ) ); ?></strong>
								<div class="portal-meta">
									<?php
									echo esc_html(
										sprintf(
											'Status: %s | Contents: %d line%s | Qty: %s',
											(string) ( $bucket['status'] ?? '' ),
											(int) ( $bucket['contents_line_count'] ?? 0 ),
											1 === (int) ( $bucket['contents_line_count'] ?? 0 ) ? '' : 's',
											number_format( (float) ( $bucket['contents_total_qty'] ?? 0 ), 2, '.', '' )
										)
									);
									?>
								</div>

								<?php if ( empty( $bucket['contents_summary'] ) ) : ?>
									<p class="portal-muted"><?php esc_html_e( 'No inventoried contents are recorded for this bucket.', 'ai-man-sys' ); ?></p>
								<?php else : ?>
									<table class="portal-content-table">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Product', 'ai-man-sys' ); ?></th>
												<th><?php esc_html_e( 'Qty', 'ai-man-sys' ); ?></th>
												<th><?php esc_html_e( 'Reserved', 'ai-man-sys' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( (array) $bucket['contents_summary'] as $content_row ) : ?>
												<tr>
													<td>
														<?php echo esc_html( (string) ( $content_row['product_label'] ?? 'Product' ) ); ?><br />
														<small><?php echo esc_html( 'Product #' . (int) ( $content_row['product_id'] ?? 0 ) ); ?></small>
													</td>
													<td><?php echo esc_html( number_format( (float) ( $content_row['quantity'] ?? 0 ), 2, '.', '' ) ); ?></td>
													<td><?php echo esc_html( number_format( (float) ( $content_row['reserved_quantity'] ?? 0 ), 2, '.', '' ) ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="portal-card">
				<h2><?php esc_html_e( 'Open Stitch Work', 'ai-man-sys' ); ?></h2>
				<?php if ( empty( $portal_model['open_jobs'] ) ) : ?>
					<p class="portal-muted"><?php esc_html_e( 'No open stitch work is assigned to this account.', 'ai-man-sys' ); ?></p>
				<?php else : ?>
					<div class="portal-job-list">
						<?php foreach ( (array) $portal_model['open_jobs'] as $job ) : ?>
							<div class="portal-job">
								<strong><?php echo esc_html( (string) ( $job['job_code'] ?? 'Stitch Work' ) ); ?></strong>
								<div class="portal-meta">
									<?php
									echo esc_html(
										sprintf(
											'Status: %s | Priority: %s%s',
											(string) ( $job['job_status_label'] ?? '' ),
											(string) ( $job['priority'] ?? 'normal' ),
											'' !== (string) ( $job['due_at_label'] ?? '' ) ? ' | Due: ' . (string) $job['due_at_label'] : ''
										)
									);
									?>
								</div>
								<?php if ( '' !== (string) ( $job['event_name'] ?? '' ) ) : ?>
									<p class="portal-meta"><?php echo esc_html( (string) $job['event_name'] ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== (string) ( $job['notes'] ?? '' ) ) : ?>
									<p><?php echo wp_kses_post( nl2br( esc_html( (string) $job['notes'] ) ) ); ?></p>
								<?php endif; ?>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="aims_stitch_complete_item" />
									<input type="hidden" name="stitch_job_id" value="<?php echo esc_attr( (string) ( $job['id'] ?? 0 ) ); ?>" />
									<input type="hidden" name="_aims_return_url" value="<?php echo esc_attr( $portal_model['return_url'] ); ?>" />
									<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'aims_stitch_complete_item', '_aims_stitch_nonce' ); } ?>
									<div class="portal-actions">
										<button type="submit" class="button button-primary"><?php echo esc_html( $portal_button_label ); ?></button>
									</div>
								</form>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
