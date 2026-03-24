<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="aims-vendor-checkin-portal">
	<style>
		.aims-vendor-checkin-portal { max-width: 760px; margin: 0 auto; padding: 16px; }
		.aims-vendor-checkin-portal .portal-shell,
		.aims-vendor-checkin-portal .portal-card,
		.aims-vendor-checkin-portal .portal-event,
		.aims-vendor-checkin-portal .portal-status { border: 1px solid #dcdcde; border-radius: 18px; background: #fff; }
		.aims-vendor-checkin-portal .portal-shell { padding: 16px; box-shadow: 0 6px 30px rgba(0,0,0,.04); }
		.aims-vendor-checkin-portal .portal-card { padding: 14px; margin: 0 0 12px; }
		.aims-vendor-checkin-portal .portal-grid { display: grid; gap: 12px; }
		.aims-vendor-checkin-portal .portal-event { display: block; padding: 14px; text-decoration: none; color: inherit; }
		.aims-vendor-checkin-portal .portal-event strong { display: block; margin-bottom: 4px; }
		.aims-vendor-checkin-portal .portal-event.is-selected { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1 inset; }
		.aims-vendor-checkin-portal label { display: block; font-weight: 600; margin-bottom: 6px; }
		.aims-vendor-checkin-portal input[type="text"],
		.aims-vendor-checkin-portal input[type="file"],
		.aims-vendor-checkin-portal select,
		.aims-vendor-checkin-portal textarea,
		.aims-vendor-checkin-portal button { width: 100%; box-sizing: border-box; }
		.aims-vendor-checkin-portal input[type="file"] { padding: 10px 0; }
		.aims-vendor-checkin-portal textarea { min-height: 110px; }
		.aims-vendor-checkin-portal .portal-actions { display: grid; gap: 12px; }
		.aims-vendor-checkin-portal .portal-status { padding: 12px 14px; margin: 0 0 12px; }
		.aims-vendor-checkin-portal .portal-muted { color: #646970; }
		@media (min-width: 768px) {
			.aims-vendor-checkin-portal .portal-actions { grid-template-columns: 1fr 1fr; }
		}
	</style>

	<div class="portal-shell">
		<h1><?php echo esc_html( $portal_title ); ?></h1>
		<p class="portal-muted"><?php echo esc_html( $portal_description ); ?></p>

		<?php if ( empty( $portal_model['logged_in'] ) ) : ?>
			<div class="portal-status">
				<p><?php esc_html_e( 'Vendor check-in requires a logged-in account linked to an assigned vendor.', 'ai-man-sys' ); ?></p>
				<?php if ( ! empty( $portal_model['login_url'] ) ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( $portal_model['login_url'] ); ?>"><?php esc_html_e( 'Log In', 'ai-man-sys' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<?php if ( ! empty( $portal_model['status_message'] ) ) : ?>
				<div class="portal-status">
					<p><?php echo esc_html( (string) ( $portal_model['status_message']['message'] ?? '' ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $portal_model['authorized_events'] ) ) : ?>
				<div class="portal-status">
					<p><?php esc_html_e( 'No assigned vendor events are available for mobile check-in yet.', 'ai-man-sys' ); ?></p>
				</div>
			<?php else : ?>
				<div class="portal-card">
					<h2><?php esc_html_e( 'Available Events', 'ai-man-sys' ); ?></h2>
					<div class="portal-grid">
						<?php foreach ( (array) $portal_model['authorized_events'] as $event ) : ?>
							<?php
							$event_id = (int) ( $event['id'] ?? 0 );
							$event_url = add_query_arg(
								array(
									'event_id' => $event_id,
								),
								$portal_model['return_url']
							);
							$is_selected = $event_id === (int) ( $portal_model['selected_event_id'] ?? 0 );
							?>
							<a class="portal-event<?php echo $is_selected ? ' is-selected' : ''; ?>" href="<?php echo esc_url( $event_url ); ?>">
								<strong><?php echo esc_html( (string) ( $event['event_name'] ?? '' ) ); ?></strong>
								<span><?php echo esc_html( (string) ( $event['date_range_label'] ?? '' ) ); ?></span>
								<?php if ( '' !== (string) ( $event['location_name'] ?? '' ) ) : ?>
									<br /><span><?php echo esc_html( (string) $event['location_name'] ); ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( ! empty( $portal_model['selected_event'] ) && ! empty( $portal_model['can_submit'] ) ) : ?>
					<?php $selected_event = (array) $portal_model['selected_event']; ?>
					<?php $selected_assignment = (array) ( $portal_model['selected_vendor_event_assignment'] ?? array() ); ?>
					<div class="portal-card">
						<h2><?php esc_html_e( 'Check In', 'ai-man-sys' ); ?></h2>
						<p class="portal-muted">
							<?php
							echo esc_html(
								sprintf(
									'Event #%d | Vendor assignment #%d',
									(int) ( $selected_event['id'] ?? 0 ),
									(int) ( $selected_assignment['id'] ?? 0 )
								)
							);
							?>
						</p>
						<p class="portal-muted">
							<?php if ( ! empty( $portal_model['is_first_checkin'] ) ) : ?>
								<?php esc_html_e( 'This first mobile check-in records event arrival and publishes the opening live update.', 'ai-man-sys' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Additional mobile check-ins publish live event updates only. They do not change inventory or execution state.', 'ai-man-sys' ); ?>
							<?php endif; ?>
						</p>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
							<input type="hidden" name="action" value="aims_vendor_event_checkin_submit" />
							<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) ( $selected_event['id'] ?? 0 ) ); ?>" />
							<input type="hidden" name="_aims_return_url" value="<?php echo esc_attr( $portal_model['return_url'] ); ?>" />
							<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'aims_vendor_event_checkin_submit', '_aims_vendor_checkin_nonce' ); } ?>

							<p>
								<label for="aims-vendor-checkin-bucket"><?php esc_html_e( 'Assigned Bucket', 'ai-man-sys' ); ?></label>
								<select id="aims-vendor-checkin-bucket" name="bucket_assignment_id" required>
									<option value=""><?php esc_html_e( 'Select an assigned bucket', 'ai-man-sys' ); ?></option>
									<?php foreach ( (array) $portal_model['bucket_options'] as $bucket_option ) : ?>
										<?php
										$bucket_label = (string) ( $bucket_option['bucket_label'] ?? '' );
										$bucket_status = (string) ( $bucket_option['assignment_status'] ?? '' );
										?>
										<option value="<?php echo esc_attr( (string) ( $bucket_option['assignment_id'] ?? 0 ) ); ?>">
											<?php echo esc_html( trim( $bucket_label . ' - ' . $bucket_status ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>

							<div class="portal-actions">
								<p>
									<label for="aims-vendor-checkin-selfie"><?php esc_html_e( 'Selfie / Check-In Image', 'ai-man-sys' ); ?></label>
									<input id="aims-vendor-checkin-selfie" type="file" name="selfie_photo" accept="image/*" capture="user" required />
								</p>
								<p>
									<label for="aims-vendor-checkin-booth"><?php esc_html_e( 'Booth / Setup Images', 'ai-man-sys' ); ?></label>
									<input id="aims-vendor-checkin-booth" type="file" name="booth_setup_photos[]" accept="image/*" capture="environment" multiple required />
								</p>
							</div>

							<p>
								<label for="aims-vendor-checkin-notes"><?php esc_html_e( 'Check-In Comments', 'ai-man-sys' ); ?></label>
								<textarea id="aims-vendor-checkin-notes" name="checkin_notes" placeholder="<?php esc_attr_e( 'Arrival, setup status, issues, or other notes.', 'ai-man-sys' ); ?>"></textarea>
							</p>

							<p>
								<label for="aims-vendor-location-notes"><?php esc_html_e( 'Location Notes', 'ai-man-sys' ); ?></label>
								<textarea id="aims-vendor-location-notes" name="location_notes" placeholder="<?php esc_attr_e( 'Booth number, staging area, dock notes, or onsite instructions.', 'ai-man-sys' ); ?>"></textarea>
							</p>

							<p>
								<button type="submit" class="button button-primary"><?php echo esc_html( $portal_button_label ); ?></button>
							</p>
						</form>
					</div>

					<div class="portal-card">
						<h2><?php esc_html_e( 'Recent Updates', 'ai-man-sys' ); ?></h2>
						<?php if ( empty( $portal_model['recent_updates'] ) ) : ?>
							<p class="portal-muted"><?php esc_html_e( 'No public event updates have been posted yet.', 'ai-man-sys' ); ?></p>
						<?php else : ?>
							<ul>
								<?php foreach ( (array) $portal_model['recent_updates'] as $update_item ) : ?>
									<li>
										<?php
										echo esc_html(
											sprintf(
												'%s - %s',
												(string) ( $update_item['update_title'] ?? $update_item['update_type_label'] ?? 'Update' ),
												(string) ( $update_item['published_at_label'] ?? '' )
											)
										);
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php elseif ( ! empty( $portal_model['selected_event'] ) ) : ?>
					<div class="portal-status">
						<p><?php esc_html_e( 'This event is not yet within the vendor check-in window or does not have an eligible bucket assignment.', 'ai-man-sys' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</section>
