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
		.aims-vendor-portal-nav .nav-signin a { display: inline-block; padding: 8px 16px; background: #0073aa; color: #fff; text-decoration: none; border-radius: 4px; transition: background 0.2s ease; }
		.aims-vendor-portal-nav .nav-signin a:hover { background: #005a87; }
		.aims-vendor-portal-nav .nav-empty { padding: 12px 0; color: #666; }
	</style>

	<?php if ( empty( $nav_model['logged_in'] ) ) : ?>
		<div class="nav-signin">
			<p><?php esc_html_e( 'Sign in to access vendor portal.', 'ai-man-sys' ); ?></p>
			<?php if ( ! empty( $nav_model['login_url'] ) ) : ?>
				<a href="<?php echo esc_url( $nav_model['login_url'] ); ?>"><?php esc_html_e( 'Sign In', 'ai-man-sys' ); ?></a>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<?php if ( empty( $nav_model['authorized_events'] ) ) : ?>
			<div class="nav-empty">
				<p><?php esc_html_e( 'No assigned vendor events available.', 'ai-man-sys' ); ?></p>
			</div>
		<?php else : ?>
			<div class="nav-section">
				<h3><?php esc_html_e( 'Assigned Events', 'ai-man-sys' ); ?></h3>
				<ul class="nav-list">
					<?php foreach ( (array) $nav_model['authorized_events'] as $event ) : ?>
						<?php
						$event_id = (int) ( $event['event_id'] ?? 0 );
						$event_name = (string) ( $event['event_name'] ?? '' );
						$vendor_name = (string) ( $event['vendor_name'] ?? '' );
						$checkin_url = (string) ( $event['checkin_url'] ?? '' );
						$can_checkin = ! empty( $event['can_checkin'] );
						$is_past = ! empty( $event['is_past'] );
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
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
