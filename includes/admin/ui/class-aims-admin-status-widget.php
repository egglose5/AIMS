<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Status_Widget extends AIMS_Admin_Widget {
	public function render(): void {
		$manifest = $this->get_data( 'manifest', array() );
		if ( empty( $manifest['success'] ) ) {
			echo '<p><strong>Connection error:</strong> ' . esc_html( (string) ( $manifest['message'] ?? 'Unable to reach the AIMS core.' ) ) . '</p>';
			return;
		}

		$json = is_array( $manifest['json'] ?? null ) ? $manifest['json'] : array();
		?>
		<div class="aims-widget aims-status-widget">
			<p><strong>HTTP:</strong> <?php echo esc_html( (string) ( $manifest['code'] ?? 0 ) ); ?></p>
			<p><strong>Manifest:</strong> <?php echo esc_html( (string) ( $json['manifest_uuid'] ?? 'n/a' ) ); ?></p>
			<p><strong>Generated:</strong> <?php echo esc_html( (string) ( $json['generated_at'] ?? 'n/a' ) ); ?></p>
			<p><strong>Items:</strong> <?php echo esc_html( (string) ( $json['summary']['merged_items'] ?? 0 ) ); ?></p>
			<pre class="aims-pre-scroll"><?php echo esc_html( wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		</div>
		<?php
	}
}
