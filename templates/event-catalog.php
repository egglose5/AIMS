<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="aims-events-catalog">
	<h2><?php echo esc_html( $catalog_title ); ?></h2>

	<?php if ( empty( $catalog_events ) ) : ?>
		<p><?php echo esc_html( $catalog_empty_message ); ?></p>
	<?php else : ?>
		<div class="aims-events-catalog-grid">
			<?php foreach ( $catalog_events as $catalog_event ) : ?>
				<?php echo do_shortcode( sprintf( '[aims_event_card event_id="%d"]', (int) $catalog_event['event_id'] ) ); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
