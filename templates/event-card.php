<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article class="aims-event-card" data-event-id="<?php echo esc_attr( (string) $card_event['event_id'] ); ?>">
	<h3><?php echo esc_html( $card_event['event_name'] ); ?></h3>
	<?php if ( '' !== (string) $card_event['date_range_label'] ) : ?>
		<p><strong><?php esc_html_e( 'When:', 'ai-man-sys' ); ?></strong> <?php echo esc_html( $card_event['date_range_label'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== (string) $card_event['location_name'] ) : ?>
		<p><strong><?php esc_html_e( 'Where:', 'ai-man-sys' ); ?></strong> <?php echo esc_html( $card_event['location_name'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== trim( wp_strip_all_tags( (string) $card_event['public_summary'] ) ) ) : ?>
		<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $card_event['public_summary'] ), 28 ) ); ?></p>
	<?php endif; ?>
	<p>
		<code><?php echo esc_html( (string) $card_event['event_slug'] ); ?></code>
	</p>
</article>
