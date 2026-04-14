<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Hot_Db_Health_Widget extends AIMS_Admin_Widget {
	public function render(): void {
		$snapshot = $this->get_data( 'snapshot', array() );
		$band_label    = (string) ( $snapshot['band_label'] ?? 'Green' );
		$band_color    = (string) ( $snapshot['band_color'] ?? '#2e7d32' );
		$usage_percent = max( 0, min( 100, (int) ( $snapshot['usage_percent'] ?? 0 ) ) );
		$total_rows    = (int) ( $snapshot['total_hot_rows'] ?? 0 );
		$target        = (int) ( $snapshot['capacity_target'] ?? 250000 );
		$order_guess   = (int) ( $snapshot['estimated_order_equivalent'] ?? 0 );
		$counts        = is_array( $snapshot['counts'] ?? null ) ? $snapshot['counts'] : array();
		$message       = (string) ( $snapshot['message'] ?? '' );
		$thresholds    = is_array( $snapshot['thresholds'] ?? null ) ? $snapshot['thresholds'] : array();
		$green_limit   = (int) ( $thresholds['green'] ?? 100000 );
		$yellow_limit  = (int) ( $thresholds['yellow'] ?? 250000 );

		?>
		<div class="aims-widget aims-hot-db-health-widget">
			<p>This is the real impact view for the hot AIMS tables living beside WordPress.</p>
			<div style="display:flex;align-items:center;gap:12px;margin:12px 0;">
				<span aria-hidden="true" style="display:inline-block;width:16px;height:16px;border-radius:50%;background:<?php echo esc_attr( $band_color ); ?>;box-shadow:0 0 0 3px rgba(0,0,0,0.06);"></span>
				<strong><?php echo esc_html( $band_label ); ?> Band</strong>
				<span><?php echo esc_html( $usage_percent ); ?>% of hot-row target</span>
			</div>
			<div style="height:14px;background:#e5e7eb;border-radius:999px;overflow:hidden;max-width:720px;">
				<div style="height:14px;width:<?php echo esc_attr( (string) $usage_percent ); ?>%;background:<?php echo esc_attr( $band_color ); ?>;"></div>
			</div>
			<p style="margin-top:12px;">
				<strong>Hot Rows:</strong> <?php echo esc_html( number_format( $total_rows ) ); ?>
				<span style="color:#646970;">/ <?php echo esc_html( number_format( $target ) ); ?> target</span><br />
				<strong>Estimated Order Equivalent:</strong> <?php echo esc_html( number_format( $order_guess ) ); ?>
				<span style="color:#646970;">(based on roughly 4 sale lines per order)</span>
			</p>
			<p><strong>Comfort Bands:</strong> Green under <?php echo esc_html( number_format( $green_limit ) ); ?>, Yellow from <?php echo esc_html( number_format( $green_limit ) ); ?> to under <?php echo esc_html( number_format( $yellow_limit ) ); ?>, Red at <?php echo esc_html( number_format( $yellow_limit ) ); ?> and above.</p>

			<table class="widefat striped" style="max-width:720px;">
				<thead><tr><th>Hot Table</th><th>Rows</th></tr></thead>
				<tbody>
					<tr><td>Square sale lines</td><td><?php echo esc_html( number_format( (int) ( $counts['square_sales'] ?? 0 ) ) ); ?></td></tr>
					<tr><td>Bucket custody movements</td><td><?php echo esc_html( number_format( (int) ( $counts['bucket_inventory_moves'] ?? 0 ) ) ); ?></td></tr>
					<tr><td>Fulfillment allocations</td><td><?php echo esc_html( number_format( (int) ( $counts['fulfillment_allocations'] ?? 0 ) ) ); ?></td></tr>
					<tr><td>Inventory movements</td><td><?php echo esc_html( number_format( (int) ( $counts['inventory_movements'] ?? 0 ) ) ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( '' !== $message ) : ?>
				<p style="margin-top:12px;"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
