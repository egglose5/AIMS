<?php

$product_name = (string) ( $label_data['product_name'] ?? '' );
$product_sku  = (string) ( $label_data['product_sku'] ?? '' );
$barcode_svg  = (string) ( $label_data['barcode_svg'] ?? '' );
?>
<div class="aims-stitch-label">
	<div>
		<?php if ( ! empty( $print_model['template']['show_product_name'] ) ) : ?>
			<p class="aims-stitch-label__product-name"><?php echo esc_html( $product_name ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $print_model['template']['show_sku_text'] ) ) : ?>
			<p class="aims-stitch-label__sku"><code><?php echo esc_html( $product_sku ); ?></code></p>
		<?php endif; ?>
	</div>
	<div>
		<div class="aims-stitch-label__barcode"><?php echo $barcode_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php if ( ! empty( $print_model['template']['show_barcode_text'] ) ) : ?>
			<div class="aims-stitch-label__barcode-text"><?php echo esc_html( $product_sku ); ?></div>
		<?php endif; ?>
	</div>
</div>
