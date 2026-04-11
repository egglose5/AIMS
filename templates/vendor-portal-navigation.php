<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="aims-vendor-portal-nav">
	<style>
		.aims-vendor-portal-nav { font-size: 14px; line-height: 1.5; }
		.aims-vendor-portal-nav .nav-section { margin-bottom: 20px; }
		.aims-vendor-portal-nav .nav-section h3 { margin: 0 0 12px; font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #444; }
		.aims-vendor-portal-nav .nav-list { list-style: none; margin: 0; padding: 0; }
		.aims-vendor-portal-nav .nav-list li { margin: 0 0 8px; }
		.aims-vendor-portal-nav .nav-item { display: block; padding: 8px 0; text-decoration: none; color: #0073aa; transition: color 0.2s ease; }
		.aims-vendor-portal-nav .nav-item:hover { color: #005a87; text-decoration: underline; }
		.aims-vendor-portal-nav .nav-status { display: inline-block; font-size: 12px; margin-left: 6px; padding: 2px 6px; background: #e7f3ff; color: #0073aa; border-radius: 3px; }
		.aims-vendor-portal-nav .nav-status.upcoming { background: #fff3cd; color: #856404; }
		.aims-vendor-portal-nav .nav-status.past { background: #f8d7da; color: #721c24; }
		.aims-vendor-portal-nav .nav-signin { padding: 12px 0; margin-top: 12px; border-top: 1px solid #ddd; }
		.aims-vendor-portal-nav .nav-signin a,
		.aims-vendor-portal-nav .nav-action-button { display: inline-block; padding: 8px 16px; background: #0073aa; color: #fff; text-decoration: none; border: 0; border-radius: 4px; transition: background 0.2s ease; cursor: pointer; }
		.aims-vendor-portal-nav .nav-signin a:hover,
		.aims-vendor-portal-nav .nav-action-button:hover { background: #005a87; }
		.aims-vendor-portal-nav .nav-empty { padding: 12px 0; color: #666; }
		.aims-vendor-portal-nav .nav-notice { margin: 0 0 16px; padding: 10px 12px; border-radius: 4px; }
		.aims-vendor-portal-nav .nav-notice.success { background: #ecf9f0; color: #1e6b36; }
		.aims-vendor-portal-nav .nav-notice.error { background: #fce8e6; color: #8a1f11; }
		.aims-vendor-portal-nav .nav-card { padding: 14px; border: 1px solid #ddd; border-radius: 6px; background: #fff; }
		.aims-vendor-portal-nav .nav-card-title { margin: 0 0 6px; font-size: 15px; }
		.aims-vendor-portal-nav .nav-meta { font-size: 12px; color: #666; margin: 0 0 8px; }
		.aims-vendor-portal-nav .nav-summary { margin: 0 0 10px; color: #444; }
		.aims-vendor-portal-nav .nav-join-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
		.aims-vendor-portal-nav .nav-join-form select { min-width: 180px; }
	</style>

	<?php if ( ! empty( $nav_model['status_message'] ) ) : ?>
		<?php $status_class = 'success' === (string) ( $nav_model['status_message']['status'] ?? '' ) ? 'success' : 'error'; ?>
		<div class="nav-notice <?php echo esc_attr( $status_class ); ?>">
			<p><?php echo esc_html( (string) ( $nav_model['status_message']['message'] ?? '' ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $nav_model['logged_in'] ) ) : ?>
		<div class="nav-signin">
			<p><?php esc_html_e( 'Sign in to access vendor portal.', 'ai-man-sys' ); ?></p>
			<?php if ( ! empty( $nav_model['login_url'] ) ) : ?>
				<a href="<?php echo esc_url( $nav_model['login_url'] ); ?>"><?php esc_html_e( 'Sign In', 'ai-man-sys' ); ?></a>
			<?php endif; ?>
		</div>
	<?php elseif ( empty( $nav_model['assigned_vendors'] ) ) : ?>
		<div class="nav-empty">
			<p><?php esc_html_e( 'This account is not currently linked to a vendor profile.', 'ai-man-sys' ); ?></p>
		</div>
	<?php else : ?>
		<div class="nav-section">
			<h3><?php esc_html_e( 'Upcoming Shows', 'ai-man-sys' ); ?></h3>
			<?php if ( empty( $nav_model['available_events'] ) ) : ?>
				<div class="nav-empty">
					<p><?php esc_html_e( 'No additional published upcoming shows are open right now.', 'ai-man-sys' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="nav-list">
					<?php foreach ( (array) $nav_model['available_events'] as $event ) : ?>
						<?php
						$event_id       = (int) ( $event['event_id'] ?? 0 );
						$event_name     = (string) ( $event['event_name'] ?? '' );
						$date_label     = (string) ( $event['date_range_label'] ?? '' );
						$location_name  = (string) ( $event['location_name'] ?? '' );
						$summary        = (string) ( $event['public_summary'] ?? '' );
						$can_join       = ! empty( $event['can_join'] );
						$vendor_options = is_array( $event['vendor_options'] ?? null ) ? $event['vendor_options'] : array();
						$default_vendor = (int) ( $event['vendor_id'] ?? 0 );
						?>
						<li>
							<div class="nav-card">
								<p class="nav-card-title"><strong><?php echo esc_html( $event_name ); ?></strong><span class="nav-status upcoming"><?php esc_html_e( 'Open', 'ai-man-sys' ); ?></span></p>
								<?php if ( '' !== $date_label || '' !== $location_name ) : ?>
									<div class="nav-meta">
										<?php echo esc_html( trim( $date_label . ( '' !== $date_label && '' !== $location_name ? ' • ' : '' ) . $location_name ) ); ?>
									</div>
								<?php endif; ?>
								<?php if ( '' !== $summary ) : ?>
									<div class="nav-summary"><?php echo wp_kses_post( $summary ); ?></div>
								<?php endif; ?>
								<?php if ( $can_join ) : ?>
									<form class="nav-join-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="aims_vendor_join_show" />
										<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
										<input type="hidden" name="_aims_return_url" value="<?php echo esc_attr( (string) ( $nav_model['return_url'] ?? '' ) ); ?>" />
										<?php wp_nonce_field( 'aims_vendor_join_show', '_aims_vendor_portal_nonce' ); ?>
										<?php if ( count( $vendor_options ) > 1 ) : ?>
											<label>
												<span class="screen-reader-text"><?php esc_html_e( 'Choose vendor profile', 'ai-man-sys' ); ?></span>
												<select name="vendor_id">
													<?php foreach ( $vendor_options as $vendor_option ) : ?>
														<option value="<?php echo esc_attr( (int) ( $vendor_option['vendor_id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $vendor_option['vendor_name'] ?? '' ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
										<?php else : ?>
											<input type="hidden" name="vendor_id" value="<?php echo esc_attr( $default_vendor ); ?>" />
										<?php endif; ?>
										<button type="submit" class="nav-action-button"><?php esc_html_e( 'Join Show', 'ai-man-sys' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="nav-section">
			<h3><?php esc_html_e( 'Assigned Events', 'ai-man-sys' ); ?></h3>
			<?php if ( empty( $nav_model['authorized_events'] ) ) : ?>
				<div class="nav-empty">
					<p><?php esc_html_e( 'No assigned vendor events available yet.', 'ai-man-sys' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="nav-list">
					<?php foreach ( (array) $nav_model['authorized_events'] as $event ) : ?>
						<?php
						$event_name  = (string) ( $event['event_name'] ?? '' );
						$vendor_name = (string) ( $event['vendor_name'] ?? '' );
						$checkin_url = (string) ( $event['checkin_url'] ?? '' );
						$can_checkin = ! empty( $event['can_checkin'] );
						$is_past     = ! empty( $event['is_past'] );
						?>
						<li>
							<?php if ( $can_checkin && '' !== $checkin_url ) : ?>
								<a class="nav-item" href="<?php echo esc_url( $checkin_url ); ?>">
									<strong><?php echo esc_html( $event_name ); ?></strong>
									<span class="nav-status"><?php esc_html_e( 'Check In', 'ai-man-sys' ); ?></span>
								</a>
							<?php else : ?>
								<span class="nav-item">
									<strong><?php echo esc_html( $event_name ); ?></strong>
									<?php if ( $is_past ) : ?>
										<span class="nav-status past"><?php esc_html_e( 'Past', 'ai-man-sys' ); ?></span>
									<?php else : ?>
										<span class="nav-status upcoming"><?php esc_html_e( 'Upcoming', 'ai-man-sys' ); ?></span>
									<?php endif; ?>
								</span>
							<?php endif; ?>
							<div style="font-size: 12px; color: #666; margin-top: 4px;">
								<?php echo esc_html( $vendor_name ); ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
