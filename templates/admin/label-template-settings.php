<?php

$template_model = (array) ( $label_template_page['template_model'] ?? array() );
$active_template = (array) ( $template_model['active_template'] ?? array() );
$template_choices = (array) ( $template_model['template_choices'] ?? array() );
$templates = (array) ( $template_model['templates'] ?? array() );
$sample_items = (array) ( $label_template_page['sample_items'] ?? array() );
$notice_status = (string) ( $label_template_page['notice_status'] ?? '' );
$notice_message = (string) ( $label_template_page['notice_message'] ?? '' );
$save_action_url = (string) ( $label_template_page['save_action_url'] ?? admin_url( 'admin-post.php' ) );
$print_action_url = (string) ( $label_template_page['print_action_url'] ?? admin_url( 'admin-post.php' ) );

if ( '' !== $notice_status && '' !== $notice_message ) {
	$notice_class = 'success' === $notice_status ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
	echo '<div class="' . esc_attr( $notice_class ) . '"><p>' . esc_html( $notice_message ) . '</p></div>';
}

echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
echo '<h2 style="margin-top:0;">Active Template</h2>';
echo '<p><strong>' . esc_html( (string) ( $active_template['template_name'] ?? 'Label Template' ) ) . '</strong> <code>' . esc_html( (string) ( $active_template['template_key'] ?? '' ) ) . '</code></p>';
echo '<p>' . esc_html( (string) ( $active_template['description'] ?? '' ) ) . '</p>';
echo '<p><strong>Dimensions:</strong> ' . esc_html( (string) ( $active_template['label_width_in'] ?? '0' ) ) . 'in x ' . esc_html( (string) ( $active_template['label_height_in'] ?? '0' ) ) . 'in</p>';
echo '</div>';

echo '<form method="post" action="' . esc_url( $save_action_url ) . '">';
wp_nonce_field( 'aims_save_stitch_label_template_settings' );
echo '<input type="hidden" name="action" value="aims_save_stitch_label_template_settings" />';
echo '<input type="hidden" name="template_key" value="' . esc_attr( (string) ( $active_template['template_key'] ?? '' ) ) . '" />';

echo '<table class="form-table" role="presentation">';
echo '<tr><th scope="row"><label for="template_name">Template Name</label></th><td><input type="text" class="regular-text" id="template_name" name="template_name" value="' . esc_attr( (string) ( $active_template['template_name'] ?? '' ) ) . '" /></td></tr>';
echo '<tr><th scope="row"><label for="description">Description</label></th><td><textarea class="large-text" rows="3" id="description" name="description">' . esc_textarea( (string) ( $active_template['description'] ?? '' ) ) . '</textarea></td></tr>';
echo '<tr><th scope="row"><label for="label_width_in">Label Width (in)</label></th><td><input type="number" step="0.01" min="0" id="label_width_in" name="label_width_in" value="' . esc_attr( (string) ( $active_template['label_width_in'] ?? '2' ) ) . '" /></td></tr>';
echo '<tr><th scope="row"><label for="label_height_in">Label Height (in)</label></th><td><input type="number" step="0.01" min="0" id="label_height_in" name="label_height_in" value="' . esc_attr( (string) ( $active_template['label_height_in'] ?? '0.75' ) ) . '" /></td></tr>';
echo '<tr><th scope="row"><label for="padding_in">Padding (in)</label></th><td><input type="number" step="0.01" min="0" id="padding_in" name="padding_in" value="' . esc_attr( (string) ( $active_template['padding_in'] ?? '0.06' ) ) . '" /></td></tr>';
echo '<tr><th scope="row"><label for="product_name_font_px">Product Name Font (px)</label></th><td><input type="number" step="1" min="0" id="product_name_font_px" name="product_name_font_px" value="' . esc_attr( (string) ( $active_template['product_name_font_px'] ?? '10' ) ) . '" /></td></tr>';
echo '<tr><th scope="row"><label for="sku_font_px">SKU Font (px)</label></th><td><input type="number" step="1" min="0" id="sku_font_px" name="sku_font_px" value="' . esc_attr( (string) ( $active_template['sku_font_px'] ?? '8' ) ) . '" /></td></tr>';
echo '<tr><th scope="row"><label for="barcode_height_px">Barcode Height (px)</label></th><td><input type="number" step="1" min="0" id="barcode_height_px" name="barcode_height_px" value="' . esc_attr( (string) ( $active_template['barcode_height_px'] ?? '26' ) ) . '" /></td></tr>';
echo '<tr><th scope="row"><label for="barcode_margin_top_px">Barcode Margin Top (px)</label></th><td><input type="number" step="1" min="0" id="barcode_margin_top_px" name="barcode_margin_top_px" value="' . esc_attr( (string) ( $active_template['barcode_margin_top_px'] ?? '2' ) ) . '" /></td></tr>';
echo '<tr><th scope="row">Display Flags</th><td>';
$show_product_name_checked = ! empty( $active_template['show_product_name'] ) ? ' checked="checked"' : '';
$show_sku_text_checked     = ! empty( $active_template['show_sku_text'] ) ? ' checked="checked"' : '';
$show_barcode_text_checked = ! empty( $active_template['show_barcode_text'] ) ? ' checked="checked"' : '';
echo '<label style="display:block; margin-bottom:6px;"><input type="checkbox" name="show_product_name" value="1"' . $show_product_name_checked . '> Show product name</label>';
echo '<label style="display:block; margin-bottom:6px;"><input type="checkbox" name="show_sku_text" value="1"' . $show_sku_text_checked . '> Show SKU text</label>';
echo '<label style="display:block;"><input type="checkbox" name="show_barcode_text" value="1"' . $show_barcode_text_checked . '> Show barcode text</label>';
echo '<label style="display:block; margin-top:10px;"><input type="checkbox" name="set_active" value="1" checked="checked"> Keep this template active when saved</label>';
echo '</td></tr>';
echo '</table>';
echo '<p><button type="submit" class="button button-primary">Save Label Template</button></p>';
echo '</form>';

echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
echo '<h2 style="margin-top:0;">Available Templates</h2>';
if ( empty( $template_choices ) ) {
	echo '<p>No templates are available.</p>';
} else {
	echo '<table class="widefat fixed striped"><thead><tr><th>Template</th><th>Key</th><th>Dimensions</th></tr></thead><tbody>';
	foreach ( $template_choices as $template_key => $label ) {
		$template = (array) ( $templates[ $template_key ] ?? array() );
		echo '<tr>';
		echo '<td>' . esc_html( (string) ( $template['template_name'] ?? $label ) ) . '</td>';
		echo '<td><code>' . esc_html( (string) $template_key ) . '</code></td>';
		echo '<td>' . esc_html( (string) ( $template['label_width_in'] ?? '0' ) ) . 'in x ' . esc_html( (string) ( $template['label_height_in'] ?? '0' ) ) . 'in</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}
echo '</div>';

echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
echo '<h2 style="margin-top:0;">Print Preview</h2>';
echo '<p>Use this preview to verify layout before wiring a stitching workflow into the print route.</p>';
echo '<form method="post" action="' . esc_url( $print_action_url ) . '" target="_blank">';
wp_nonce_field( 'aims_print_stitch_labels' );
echo '<input type="hidden" name="action" value="aims_print_stitch_labels" />';
echo '<input type="hidden" name="template_key" value="' . esc_attr( (string) ( $active_template['template_key'] ?? '' ) ) . '" />';
echo '<p><label for="label_items_json"><strong>Sample Stitch Items JSON</strong></label></p>';
echo '<textarea class="large-text code" rows="8" id="label_items_json" name="label_items_json">' . esc_textarea( wp_json_encode( $sample_items, JSON_PRETTY_PRINT ) ) . '</textarea>';
echo '<p><button type="submit" class="button button-secondary">Open Printable Labels</button></p>';
echo '</form>';
echo '</div>';
