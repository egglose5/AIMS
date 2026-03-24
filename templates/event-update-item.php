<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$update_title = (string) ( $update_item['update_title'] ?? '' );
$update_type  = (string) ( $update_item['update_type_label'] ?? 'Update' );
$published_at = (string) ( $update_item['published_at_label'] ?? '' );
$summary      = trim( (string) ( $update_item['update_summary'] ?? '' ) );
$body         = trim( (string) ( $update_item['update_body'] ?? '' ) );
$is_pinned    = ! empty( $update_item['is_pinned'] );
$hero_image   = trim( (string) ( $update_item['hero_image_reference'] ?? '' ) );
?>
<article class="aims-event-update<?php echo $is_pinned ? ' is-pinned' : ''; ?>" data-update-id="<?php echo esc_attr( (string) ( $update_item['update_id'] ?? 0 ) ); ?>">
	<header class="aims-event-update-header">
		<div class="aims-event-update-meta">
			<span class="aims-event-update-type"><?php echo esc_html( $update_type ); ?></span>
			<?php if ( '' !== $published_at ) : ?>
				<span class="aims-event-update-date"><?php echo esc_html( $published_at ); ?></span>
			<?php endif; ?>
		</div>
		<h4><?php echo esc_html( '' !== $update_title ? $update_title : $update_type ); ?></h4>
	</header>

	<?php if ( $is_pinned ) : ?>
		<p class="aims-event-update-pin"><?php echo esc_html__( 'Pinned update', 'ai-man-sys' ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $hero_image ) : ?>
		<div class="aims-event-update-media">
			<img src="<?php echo esc_url( $hero_image ); ?>" alt="<?php echo esc_attr( $update_title ); ?>" loading="lazy" />
		</div>
	<?php endif; ?>

	<?php if ( '' !== $summary ) : ?>
		<div class="aims-event-update-summary">
			<?php echo wp_kses_post( nl2br( esc_html( $summary ) ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $body ) : ?>
		<div class="aims-event-update-body">
			<?php echo wp_kses_post( nl2br( esc_html( $body ) ) ); ?>
		</div>
	<?php endif; ?>
</article>
