<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Low_Stock_Widget extends AIMS_Admin_Widget {
	public function render(): void {
		$snapshot = $this->get_data( 'snapshot', array() );
		$threshold      = (int) ( $snapshot['threshold'] ?? 5 );
		$tracked        = (int) ( $snapshot['tracked_products'] ?? 0 );
		$low_stock      = (int) ( $snapshot['low_stock_products'] ?? 0 );
		$active_rows    = (int) ( $snapshot['active_positions'] ?? 0 );
		$alerts         = is_array( $snapshot['alerts'] ?? null ) ? $snapshot['alerts'] : array();

		?>
		<div class="aims-widget aims-low-stock-widget">
			<p>Alerts are read-only and based on active bucket position availability.</p>
			<p><strong>Threshold:</strong> <?php echo esc_html( number_format( $threshold ) ); ?> | <strong>Tracked Products:</strong> <?php echo esc_html( number_format( $tracked ) ); ?> | <strong>Active Positions:</strong> <?php echo esc_html( number_format( $active_rows ) ); ?></p>

			<?php if ( $low_stock <= 0 ) : ?>
				<p><strong>No low-stock products detected.</strong></p>
			<?php else : ?>
				<p><strong><?php echo esc_html( number_format( $low_stock ) ); ?> product(s) are at or below threshold.</strong></p>
				<table class="widefat striped" style="max-width:960px;">
					<thead><tr><th>Product</th><th>Available</th><th>Total</th><th>Reserved</th><th>Buckets</th><th>Vendors</th><th>Status</th></tr></thead>
					<tbody>
						<?php foreach ( $alerts as $item ) : ?>
							<?php $status_label = 'low' === (string) ( $item['status'] ?? '' ) ? 'Low' : 'Out'; ?>
							<tr>
								<td><?php echo esc_html( (string) ( $item['product_name'] ?? '' ) ); ?> <span style="color:#646970;">#<?php echo esc_html( (string) ( $item['product_id'] ?? 0 ) ); ?></span></td>
								<td><strong><?php echo esc_html( number_format( (float) ( $item['available_quantity'] ?? 0 ), 4 ) ); ?></strong></td>
								<td><?php echo esc_html( number_format( (float) ( $item['total_quantity'] ?? 0 ), 4 ) ); ?></td>
								<td><?php echo esc_html( number_format( (float) ( $item['reserved_quantity'] ?? 0 ), 4 ) ); ?></td>
								<td><?php echo esc_html( number_format( (int) ( $item['bucket_count'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( number_format( (int) ( $item['vendor_count'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( $status_label ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
