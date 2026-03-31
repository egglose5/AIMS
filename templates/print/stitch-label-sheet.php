<?php

$template = (array) ( $print_model['template'] ?? array() );
$labels   = (array) ( $print_model['labels'] ?? array() );
$width_in = number_format( (float) ( $template['label_width_in'] ?? 2.0 ), 2, '.', '' );
$height_in = number_format( (float) ( $template['label_height_in'] ?? 0.75 ), 2, '.', '' );
$padding_in = number_format( (float) ( $template['padding_in'] ?? 0.06 ), 2, '.', '' );
$template_name = (string) ( $template['template_name'] ?? 'Label Template' );

?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $template_name ); ?></title>
	<style>
		@page {
			size: <?php echo esc_html( $width_in ); ?>in <?php echo esc_html( $height_in ); ?>in;
			margin: 0;
		}
		html, body {
			margin: 0;
			padding: 0;
			width: 100%;
			height: 100%;
			background: #fff;
			font-family: Arial, Helvetica, sans-serif;
		}
		body {
			-webkit-print-color-adjust: exact;
			print-color-adjust: exact;
		}
		.aims-stitch-label-page {
			width: 100%;
			height: 100%;
		}
		.aims-stitch-label {
			width: <?php echo esc_html( $width_in ); ?>in;
			height: <?php echo esc_html( $height_in ); ?>in;
			box-sizing: border-box;
			padding: <?php echo esc_html( $padding_in ); ?>in;
			overflow: hidden;
			page-break-after: always;
			display: flex;
			flex-direction: column;
			justify-content: space-between;
		}
		.aims-stitch-label__product-name {
			font-size: <?php echo esc_html( (string) ( $template['product_name_font_px'] ?? 10 ) ); ?>px;
			font-weight: 700;
			line-height: 1.05;
			margin: 0;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		.aims-stitch-label__sku {
			font-size: <?php echo esc_html( (string) ( $template['sku_font_px'] ?? 8 ) ); ?>px;
			line-height: 1.05;
			margin: 0;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		.aims-stitch-label__barcode {
			margin-top: <?php echo esc_html( (string) ( $template['barcode_margin_top_px'] ?? 2 ) ); ?>px;
			display: flex;
			justify-content: center;
			align-items: center;
			min-height: <?php echo esc_html( (string) ( $template['barcode_height_px'] ?? 26 ) ); ?>px;
		}
		.aims-stitch-label__barcode svg {
			display: block;
			max-width: 100%;
			height: auto;
		}
		.aims-stitch-label__barcode-text {
			font-size: 7px;
			text-align: center;
			line-height: 1;
			margin-top: 1px;
			letter-spacing: 0.02em;
		}
		@media screen {
			body {
				padding: 16px;
				background: #f3f4f5;
			}
			.aims-stitch-label {
				margin: 0 auto 12px auto;
				box-shadow: 0 0 0 1px #d0d7de;
			}
		}
	</style>
</head>
<body>
	<div class="aims-stitch-label-page">
		<?php foreach ( $labels as $label ) : ?>
			<?php
			$label_data = (array) $label;
			include AIMS_PLUGIN_PATH . 'templates/print/stitch-label-item.php';
			?>
		<?php endforeach; ?>
	</div>
</body>
</html>
