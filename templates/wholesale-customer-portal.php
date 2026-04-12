<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="aims-wholesale-portal">
	<style>
		.aims-wholesale-portal { --ink: #1f2933; --muted: #5f6c7b; --line: #dbe2ea; --bg-soft: #f4f7fb; --ok: #0f766e; --warn: #b45309; --btn: #0b5fff; --btn-hover: #094acc; color: var(--ink); font-size: 15px; line-height: 1.5; }
		.aims-wholesale-portal .portal-wrap { background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
		.aims-wholesale-portal .portal-head { padding: 18px 20px; background: linear-gradient(135deg, #eef4ff 0%, #f6fbff 55%, #eefbf7 100%); border-bottom: 1px solid var(--line); }
		.aims-wholesale-portal .portal-head h2 { margin: 0 0 4px; font-size: 24px; letter-spacing: 0.01em; }
		.aims-wholesale-portal .portal-sub { margin: 0; color: var(--muted); }
		.aims-wholesale-portal .portal-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-top: 14px; }
		.aims-wholesale-portal .kpi { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; }
		.aims-wholesale-portal .kpi .label { display: block; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
		.aims-wholesale-portal .kpi .value { font-size: 18px; font-weight: 700; }
		.aims-wholesale-portal .portal-body { padding: 20px; }
		.aims-wholesale-portal .panel { margin-bottom: 18px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
		.aims-wholesale-portal .panel h3 { margin: 0; padding: 12px 14px; border-bottom: 1px solid var(--line); font-size: 16px; }
		.aims-wholesale-portal .panel-content { padding: 14px; }
		.aims-wholesale-portal .integration-steps { margin: 0 0 10px; padding-left: 18px; }
		.aims-wholesale-portal .integration-steps li { margin: 0 0 6px; }
		.aims-wholesale-portal .integration-code { margin: 10px 0; padding: 10px; background: #f8fafc; border: 1px solid var(--line); border-radius: 8px; overflow-x: auto; font-size: 12px; }
		.aims-wholesale-portal .integration-note { font-size: 13px; color: var(--muted); margin: 8px 0 0; }
		.aims-wholesale-portal .integration-subhead { margin: 12px 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
		.aims-wholesale-portal .rates { width: 100%; border-collapse: collapse; }
		.aims-wholesale-portal .rates th, .aims-wholesale-portal .rates td { border-bottom: 1px solid var(--line); padding: 8px 6px; text-align: left; }
		.aims-wholesale-portal .rates th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
		.aims-wholesale-portal .order-card { border: 1px solid var(--line); border-radius: 10px; padding: 12px; margin-bottom: 12px; background: var(--bg-soft); }
		.aims-wholesale-portal .order-meta { color: var(--muted); font-size: 13px; margin: 2px 0 8px; }
		.aims-wholesale-portal .item-list { margin: 0 0 10px; padding-left: 18px; }
		.aims-wholesale-portal .est { font-size: 13px; margin-bottom: 10px; }
		.aims-wholesale-portal .badge { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; vertical-align: middle; }
		.aims-wholesale-portal .badge.ok { background: #ddfbe8; color: var(--ok); }
		.aims-wholesale-portal .badge.warn { background: #fff4d6; color: var(--warn); }
		.aims-wholesale-portal .portal-button { background: var(--btn); color: #fff; border: 0; border-radius: 8px; padding: 9px 14px; font-weight: 600; cursor: pointer; }
		.aims-wholesale-portal .portal-button:hover { background: var(--btn-hover); }
		.aims-wholesale-portal .empty { color: var(--muted); margin: 0; }
		@media (max-width: 760px) {
			.aims-wholesale-portal .portal-head h2 { font-size: 20px; }
			.aims-wholesale-portal .portal-body { padding: 14px; }
		}
	</style>

	<?php if ( empty( $portal_model['logged_in'] ) ) : ?>
		<div class="portal-wrap">
			<div class="portal-head">
				<h2><?php esc_html_e( 'Wholesale Customer Portal', 'ai-man-sys' ); ?></h2>
				<p class="portal-sub"><?php esc_html_e( 'Sign in to view your contract terms and one-press reorder options.', 'ai-man-sys' ); ?></p>
			</div>
			<div class="portal-body">
				<?php if ( ! empty( $portal_model['login_url'] ) ) : ?>
					<a class="portal-button" href="<?php echo esc_url( (string) $portal_model['login_url'] ); ?>"><?php esc_html_e( 'Sign In', 'ai-man-sys' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	<?php elseif ( empty( $portal_model['is_wholesale'] ) ) : ?>
		<div class="portal-wrap">
			<div class="portal-head">
				<h2><?php esc_html_e( 'Wholesale Customer Portal', 'ai-man-sys' ); ?></h2>
				<p class="portal-sub"><?php esc_html_e( 'This account does not yet have an active wholesale contract.', 'ai-man-sys' ); ?></p>
			</div>
		</div>
	<?php else : ?>
		<?php $contract = is_array( $portal_model['contract'] ?? null ) ? $portal_model['contract'] : array(); ?>
		<?php $tier_rates = is_array( $contract['tier_rates'] ?? null ) ? $contract['tier_rates'] : array(); ?>
		<?php $recent_orders = is_array( $portal_model['recent_orders'] ?? null ) ? $portal_model['recent_orders'] : array(); ?>
		<?php $integration_ingest_url = (string) ( $portal_model['integration_ingest_url'] ?? '' ); ?>
		<?php $integration_feed_url = (string) ( $portal_model['integration_feed_url'] ?? '' ); ?>
		<?php $price_formatter = function_exists( 'wc_price' ) ? 'wc_price' : null; ?>
		<?php $number_formatter = static function ( $value, int $decimals = 0 ): string {
			return number_format( (float) $value, $decimals, '.', ',' );
		}; ?>
		<div class="portal-wrap">
			<div class="portal-head">
				<h2><?php esc_html_e( 'Wholesale Customer Portal', 'ai-man-sys' ); ?>
					<?php if ( ! empty( $portal_model['elevated_customer'] ) ) : ?>
						<span class="badge ok"><?php esc_html_e( 'Elevated WC Customer', 'ai-man-sys' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="portal-sub"><?php esc_html_e( 'Contract-ready ordering with lead-time visibility and one-press reorder.', 'ai-man-sys' ); ?></p>
				<div class="portal-grid">
					<div class="kpi"><span class="label"><?php esc_html_e( 'Lead Time', 'ai-man-sys' ); ?></span><span class="value"><?php echo esc_html( (string) ( (int) ( $contract['lead_time_days'] ?? 7 ) ) ); ?> <?php esc_html_e( 'days', 'ai-man-sys' ); ?></span></div>
					<div class="kpi"><span class="label"><?php esc_html_e( 'Payment Terms', 'ai-man-sys' ); ?></span><span class="value"><?php echo esc_html( (string) ( $contract['payment_terms'] ?? '' ) ); ?></span></div>
					<div class="kpi"><span class="label"><?php esc_html_e( 'Shipping Window', 'ai-man-sys' ); ?></span><span class="value"><?php echo esc_html( (string) ( $contract['shipping_window'] ?? '' ) ); ?></span></div>
					<div class="kpi"><span class="label"><?php esc_html_e( 'Minimum Reorder Qty', 'ai-man-sys' ); ?></span><span class="value"><?php echo esc_html( (string) ( (int) ( $contract['min_order_qty'] ?? 1 ) ) ); ?></span></div>
				</div>
			</div>
			<div class="portal-body">
				<div class="panel">
					<h3><?php esc_html_e( 'Share Your Inventory with AIMS', 'ai-man-sys' ); ?></h3>
					<div class="panel-content">
						<p><?php esc_html_e( 'Use this to automatically send us your current on-hand inventory so AIMS always has your latest counts.', 'ai-man-sys' ); ?></p>
						<ol class="integration-steps">
							<li><?php esc_html_e( 'Ask your AIMS contact for your integration token.', 'ai-man-sys' ); ?></li>
							<li><?php esc_html_e( 'Send your latest on-hand quantities to the endpoint below whenever your counts change.', 'ai-man-sys' ); ?></li>
							<li><?php esc_html_e( 'Optionally read the updates feed if you also want to pull changes back from AIMS.', 'ai-man-sys' ); ?></li>
						</ol>

						<?php if ( '' !== $integration_ingest_url ) : ?>
							<p><strong><?php esc_html_e( 'Send Inventory Updates (POST)', 'ai-man-sys' ); ?>:</strong> <?php echo esc_html( $integration_ingest_url ); ?></p>
							<p class="integration-subhead"><?php esc_html_e( 'Example Body', 'ai-man-sys' ); ?></p>
							<pre class="integration-code">{
  "updates": [
    {
      "sku": "SKU-123",
      "available_quantity": 28,
      "total_quantity": 30,
      "reserved_quantity": 2,
      "source_reference": "partner-sync-2026-04-12T14:00:00Z"
    }
  ]
}</pre>
							<p class="integration-subhead"><?php esc_html_e( 'Example cURL Request', 'ai-man-sys' ); ?></p>
							<pre class="integration-code">curl -X POST "<?php echo esc_html( $integration_ingest_url ); ?>" \
	-H "X-Ames-Token: &lt;your integration token&gt;" \
	-H "Content-Type: application/json" \
	-d '{
		"updates": [
			{
				"sku": "SKU-123",
				"available_quantity": 28,
				"total_quantity": 30,
				"reserved_quantity": 2,
				"source_reference": "partner-sync-2026-04-12T14:00:00Z"
			}
		]
	}'</pre>
						<?php endif; ?>

						<?php if ( '' !== $integration_feed_url ) : ?>
							<p><strong><?php esc_html_e( 'Read AIMS Updates (GET)', 'ai-man-sys' ); ?>:</strong> <?php echo esc_html( $integration_feed_url ); ?></p>
							<p class="integration-subhead"><?php esc_html_e( 'Example Feed Request', 'ai-man-sys' ); ?></p>
							<pre class="integration-code">curl -X GET "<?php echo esc_html( $integration_feed_url ); ?>?limit=50" \
  -H "X-Ames-Token: &lt;your integration token&gt;"</pre>
						<?php endif; ?>

						<pre class="integration-code">X-Ames-Token: &lt;your integration token&gt;
Content-Type: application/json</pre>
						<p class="integration-note"><?php esc_html_e( 'Tip: include a stable source_reference on each update so duplicate sends can be safely ignored, and send updates whenever counts change to keep both systems aligned.', 'ai-man-sys' ); ?></p>
					</div>
				</div>

				<div class="panel">
					<h3><?php esc_html_e( 'Wholesale Tier Rates', 'ai-man-sys' ); ?></h3>
					<div class="panel-content">
						<?php if ( empty( $tier_rates ) ) : ?>
							<p class="empty"><?php esc_html_e( 'No tier discounts configured yet. Your contract manager can add quantity break pricing.', 'ai-man-sys' ); ?></p>
						<?php else : ?>
							<table class="rates">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Minimum Qty', 'ai-man-sys' ); ?></th>
										<th><?php esc_html_e( 'Discount', 'ai-man-sys' ); ?></th>
										<th><?php esc_html_e( 'Rate Multiplier', 'ai-man-sys' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $tier_rates as $tier ) : ?>
										<tr>
											<td><?php echo esc_html( $number_formatter( (int) ( $tier['min_qty'] ?? 0 ), 0 ) ); ?></td>
											<td><?php echo esc_html( $number_formatter( (float) ( $tier['discount_percent'] ?? 0 ), 2 ) ); ?>%</td>
											<td>x<?php echo esc_html( $number_formatter( (float) ( $tier['multiplier'] ?? 1 ), 4 ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>

				<div class="panel">
					<h3><?php esc_html_e( 'One-Press Reorder', 'ai-man-sys' ); ?></h3>
					<div class="panel-content">
						<?php if ( empty( $recent_orders ) ) : ?>
							<p class="empty"><?php esc_html_e( 'No prior orders found yet for quick reorder.', 'ai-man-sys' ); ?></p>
						<?php else : ?>
							<?php foreach ( $recent_orders as $order_row ) : ?>
								<div class="order-card">
									<strong>#<?php echo esc_html( (string) ( $order_row['order_number'] ?? '' ) ); ?></strong>
									<span class="badge <?php echo ! empty( $order_row['discount_percent'] ) ? 'ok' : 'warn'; ?>"><?php echo ! empty( $order_row['discount_percent'] ) ? esc_html( $number_formatter( (float) $order_row['discount_percent'], 2 ) . '% tier applied' ) : esc_html__( 'base rate', 'ai-man-sys' ); ?></span>
									<p class="order-meta"><?php echo esc_html( (string) ( $order_row['date_created'] ?? '' ) ); ?> • <?php echo esc_html( ucfirst( (string) ( $order_row['status'] ?? '' ) ) ); ?> • <?php echo esc_html( $number_formatter( (int) ( $order_row['total_qty'] ?? 0 ), 0 ) ); ?> <?php esc_html_e( 'units', 'ai-man-sys' ); ?></p>
									<?php if ( ! empty( $order_row['line_items'] ) ) : ?>
										<ul class="item-list">
											<?php foreach ( (array) $order_row['line_items'] as $line ) : ?>
												<li><?php echo esc_html( (string) ( $line['name'] ?? '' ) ); ?> x<?php echo esc_html( (string) ( (int) ( $line['qty'] ?? 0 ) ) ); ?></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
									<?php $estimated_total = (float) ( $order_row['estimated_reorder'] ?? 0 ); ?>
									<?php $estimated_label = is_string( $price_formatter ) ? (string) $price_formatter( $estimated_total ) : '$' . $number_formatter( $estimated_total, 2 ); ?>
									<p class="est"><?php esc_html_e( 'Estimated Reorder Total:', 'ai-man-sys' ); ?> <strong><?php echo wp_kses_post( $estimated_label ); ?></strong></p>
									<form method="post" action="<?php echo esc_url( (string) ( $portal_model['reorder_post_url'] ?? '' ) ); ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( (string) ( $portal_model['reorder_action'] ?? '' ) ); ?>" />
										<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) ( (int) ( $order_row['order_id'] ?? 0 ) ) ); ?>" />
										<?php wp_nonce_field( (string) ( $portal_model['reorder_nonce_action'] ?? '' ), '_aims_wholesale_nonce' ); ?>
										<button type="submit" class="portal-button"><?php esc_html_e( 'Reorder This Order', 'ai-man-sys' ); ?></button>
									</form>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( ! empty( $contract['contract_notes'] ) ) : ?>
					<div class="panel">
						<h3><?php esc_html_e( 'Contract Notes', 'ai-man-sys' ); ?></h3>
						<div class="panel-content"><p><?php echo esc_html( (string) $contract['contract_notes'] ); ?></p></div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
