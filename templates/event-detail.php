<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="aims-event-detail" data-event-id="<?php echo esc_attr( (string) $detail_event['event_id'] ); ?>">
	<header>
		<h2><?php echo esc_html( $detail_event['event_name'] ); ?></h2>
		<?php if ( '' !== (string) $detail_event['date_range_label'] ) : ?>
			<p><strong><?php esc_html_e( 'When:', 'ai-man-sys' ); ?></strong> <?php echo esc_html( $detail_event['date_range_label'] ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== (string) $detail_event['location_name'] ) : ?>
			<p><strong><?php esc_html_e( 'Where:', 'ai-man-sys' ); ?></strong> <?php echo esc_html( $detail_event['location_name'] ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( '' !== trim( wp_strip_all_tags( (string) $detail_event['public_summary'] ) ) ) : ?>
		<div class="aims-event-detail-summary">
			<?php echo wp_kses_post( wpautop( (string) $detail_event['public_summary'] ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $detail_public_updates ) || ! empty( $detail_updates_empty_message ) ) : ?>
		<?php include AIMS_PLUGIN_PATH . 'templates/event-updates-feed.php'; ?>
	<?php endif; ?>

	<?php if ( ! empty( $detail_demand_shortcode ) ) : ?>
		<div class="aims-event-detail-demand">
			<?php echo $detail_demand_shortcode; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endif; ?>
</section>
