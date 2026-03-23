<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="aims-event-demand-intake-shell" data-aims-event-demand="1" data-aims-disable-theme-chrome="<?php echo esc_attr( $public_disable_chrome ? '1' : '0' ); ?>">
	<h2><?php echo esc_html( $title ); ?></h2>
	<p><?php echo esc_html( $description ); ?></p>
	<p><strong><?php esc_html_e( 'Planning only:', 'ai-man-sys' ); ?></strong> <?php esc_html_e( 'This intake does not take payment, reserve inventory, or create a Square order. It records event demand against event_id and Woo product identity for later planning workflows.', 'ai-man-sys' ); ?></p>

	<?php if ( ! empty( $public_status ) ) : ?>
		<div class="notice <?php echo 'success' === $public_status['status'] ? 'notice-success' : 'notice-error'; ?>" style="padding:12px; margin: 12px 0;">
			<p><?php echo esc_html( $public_status['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $public_confirmed ) ) : ?>
		<div class="aims-event-demand-confirmation" style="padding:12px; border:1px solid #dcdcde; margin-bottom:16px;">
			<p><strong><?php esc_html_e( 'Latest submission', 'ai-man-sys' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: 1: event id, 2: product sku, 3: quantity */
					esc_html__( 'Event #%1$s | SKU %2$s | Qty %3$s', 'ai-man-sys' ),
					esc_html( (string) $public_confirmed['event_id'] ),
					esc_html( (string) $public_confirmed['product_sku'] ),
					esc_html( (string) $public_confirmed['quantity_requested'] )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $public_event_id <= 0 ) : ?>
		<div class="notice notice-warning" style="padding:12px;">
			<p><?php esc_html_e( 'This form requires a valid event_id from the shortcode or page context.', 'ai-man-sys' ); ?></p>
		</div>
	<?php elseif ( empty( $public_is_logged_in ) ) : ?>
		<div class="notice notice-warning" style="padding:12px;">
			<p><?php esc_html_e( 'You must be logged in to submit an event demand request. This remains a planning-only flow and does not create a payment, reservation, or Square order.', 'ai-man-sys' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $public_login_url ); ?>"><?php esc_html_e( 'Log In to Continue', 'ai-man-sys' ); ?></a></p>
		</div>
	<?php elseif ( empty( $public_products ) ) : ?>
		<div class="notice notice-warning" style="padding:12px;">
			<p><?php esc_html_e( 'No eligible physical WooCommerce products are currently available for event demand intake.', 'ai-man-sys' ); ?></p>
		</div>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( $public_action_url ); ?>" class="aims-event-demand-form">
			<input type="hidden" name="action" value="aims_event_demand_submit" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $public_event_id ); ?>" />
			<input type="hidden" name="_aims_return_url" value="<?php echo esc_url( $public_return_url ); ?>" />
			<input type="hidden" name="aims_event_demand_chrome" value="<?php echo esc_attr( $public_disable_chrome ? 'minimal' : '' ); ?>" />
			<?php wp_nonce_field( 'aims_event_demand_submit', '_aims_event_demand_nonce' ); ?>

			<p>
				<label for="aims-event-demand-product"><strong><?php esc_html_e( 'Product', 'ai-man-sys' ); ?></strong></label><br />
				<select id="aims-event-demand-product" name="woo_product_id" required>
					<option value=""><?php esc_html_e( 'Select a physical Woo product', 'ai-man-sys' ); ?></option>
					<?php foreach ( $public_products as $product_row ) : ?>
						<option value="<?php echo esc_attr( (string) $product_row['woo_product_id'] ); ?>">
							<?php
							echo esc_html(
								sprintf(
									'%s (%s)',
									(string) $product_row['product_name'],
									'' !== (string) $product_row['product_sku'] ? (string) $product_row['product_sku'] : 'SKU required'
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="aims-event-demand-quantity"><strong><?php esc_html_e( 'Requested Quantity', 'ai-man-sys' ); ?></strong></label><br />
				<input id="aims-event-demand-quantity" type="number" min="1" step="1" name="quantity_requested" required />
			</p>

			<div style="padding:12px; border:1px solid #dcdcde; margin-bottom:16px;">
				<p><strong><?php esc_html_e( 'Signed in as', 'ai-man-sys' ); ?></strong></p>
				<p><?php echo esc_html( (string) ( $public_user_snapshot['customer_name'] ?? '' ) ); ?></p>
				<p><?php echo esc_html( (string) ( $public_user_snapshot['customer_email'] ?? '' ) ); ?></p>
			</div>

			<p>
				<label for="aims-event-demand-phone"><strong><?php esc_html_e( 'Phone', 'ai-man-sys' ); ?></strong></label><br />
				<input id="aims-event-demand-phone" type="text" name="customer_phone" maxlength="50" value="<?php echo esc_attr( (string) ( $public_user_snapshot['customer_phone'] ?? '' ) ); ?>" />
			</p>

			<p>
				<label for="aims-event-demand-notes"><strong><?php esc_html_e( 'Notes', 'ai-man-sys' ); ?></strong></label><br />
				<textarea id="aims-event-demand-notes" name="request_notes" rows="5"></textarea>
			</p>

			<p>
				<button type="submit"><?php echo esc_html( $button_label ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
