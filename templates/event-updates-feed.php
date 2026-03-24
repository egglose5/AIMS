<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="aims-event-detail-updates" data-event-id="<?php echo esc_attr( (string) ( $detail_event['event_id'] ?? 0 ) ); ?>">
	<header>
		<h3><?php echo esc_html( (string) ( $detail_updates_title ?? 'Latest Updates' ) ); ?></h3>
	</header>

	<?php if ( empty( $detail_public_updates ) ) : ?>
		<p class="aims-event-detail-updates-empty"><?php echo esc_html( (string) ( $detail_updates_empty_message ?? 'No public updates have been posted yet.' ) ); ?></p>
	<?php else : ?>
		<div class="aims-event-detail-updates-list">
			<?php foreach ( (array) $detail_public_updates as $update_item ) : ?>
				<?php include AIMS_PLUGIN_PATH . 'templates/event-update-item.php'; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
