<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Overview_Page {
	private $data_provider;
	private $actions;
	private $current_user_id;
	private $user_vendor_id;

	public function __construct( AIMS_Inventory_Overview_Data_Provider $data_provider = null ) {
		global $current_user;
		$this->data_provider   = $data_provider ?: new AIMS_Inventory_Overview_Data_Provider();
		$this->actions         = new AIMS_Inventory_Transfer_Actions();
		$this->current_user_id = get_current_user_id();
		$this->user_vendor_id  = $this->resolve_user_vendor(); // Simplified vendor assignment
	}

	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Inventory Transfer Workspace', 'ai-man-sys' ) . '</h1>';

		$this->render_status_message();

		echo '<div id="aims-inventory-container" style="margin-top: 20px;">';

		// Outgoing transfers panel
		echo '<h2 style="border-bottom: 2px solid #ccc; padding-bottom: 10px;">' . esc_html__( 'Outgoing Transfers (Your Dispatches)', 'ai-man-sys' ) . '</h2>';
		$this->render_outgoing_panel();

		echo '<hr style="margin: 30px 0;" />';

		// Incoming transfers panel
		echo '<h2 style="border-bottom: 2px solid #ccc; padding-bottom: 10px;">' . esc_html__( 'Incoming Transfers (To Receive)', 'ai-man-sys' ) . '</h2>';
		$this->render_incoming_panel();

		echo '<hr style="margin: 30px 0;" />';

		// Architecture notes
		echo '<h3>' . esc_html__( 'Inventory Architecture', 'ai-man-sys' ) . '</h3>';
		echo '<ul style="list-style: disc; padding-left: 20px;">';
		foreach ( $this->data_provider->get_outline() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';

		echo '</div>';
		echo '</div>';
	}

	private function render_status_message(): void {
		$status  = isset( $_GET['aims_status'] ) ? sanitize_key( $_GET['aims_status'] ) : '';
		$message = isset( $_GET['aims_message'] ) ? sanitize_text_field( $_GET['aims_message'] ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_outgoing_panel(): void {
		if ( $this->user_vendor_id <= 0 ) {
			echo '<p>' . esc_html__( 'No vendor assignment found for your user.', 'ai-man-sys' ) . '</p>';
			return;
		}

		$outgoing = $this->data_provider->get_outgoing_transfers( $this->user_vendor_id );

		if ( ! empty( $outgoing ) ) {
			echo '<h3>' . esc_html__( 'Active Transfers', 'ai-man-sys' ) . '</h3>';
			$this->render_transfer_list( $outgoing, 'outgoing' );
		}

		// Create new transfer form
		echo '<h3 style="margin-top: 30px;">' . esc_html__( 'Create New Transfer', 'ai-man-sys' ) . '</h3>';
		$this->render_create_transfer_form();
	}

	private function render_incoming_panel(): void {
		if ( $this->user_vendor_id <= 0 ) {
			echo '<p>' . esc_html__( 'No vendor assignment found for your user.', 'ai-man-sys' ) . '</p>';
			return;
		}

		$incoming = $this->data_provider->get_incoming_transfers( $this->user_vendor_id );

		if ( empty( $incoming ) ) {
			echo '<p>' . esc_html__( 'No incoming transfers awaiting your receipt.', 'ai-man-sys' ) . '</p>';
			return;
		}

		echo '<h3>' . esc_html__( 'Transfers Awaiting Receipt', 'ai-man-sys' ) . '</h3>';
		$this->render_receipt_list( $incoming );
	}

	private function render_create_transfer_form(): void {
		$vendors = $this->get_vendor_options();
		$buckets = $this->data_provider->get_available_buckets( $this->user_vendor_id );
		$products = $this->data_provider->get_available_products();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_create_draft' ) ); ?>" style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 4px; max-width: 600px;">
			<?php wp_nonce_field( 'aims_inventory_transfer_create_draft', '_aims_inventory_transfer_create_draft_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="source_vendor"><?php esc_html_e( 'From (Sending Vendor)', 'ai-man-sys' ); ?></label></th>
						<td>
							<select id="source_vendor" name="source_vendor_id" required>
								<option value="<?php echo esc_attr( $this->user_vendor_id ); ?>"><?php echo esc_html( 'Your Vendor (' . $this->user_vendor_id . ')' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( "You're always the source.", 'ai-man-sys' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="target_vendor"><?php esc_html_e( 'To (Receiving Vendor)', 'ai-man-sys' ); ?></label></th>
						<td>
							<select id="target_vendor" name="target_vendor_id" required>
								<option value=""><?php esc_html_e( '-- Select Receiving Vendor --', 'ai-man-sys' ); ?></option>
								<?php foreach ( $vendors as $vendor_id => $vendor_name ) : ?>
									<?php if ( (int) $vendor_id !== $this->user_vendor_id ) : ?>
										<option value="<?php echo esc_attr( $vendor_id ); ?>"><?php echo esc_html( $vendor_name ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="transfer_type"><?php esc_html_e( 'Transfer Type', 'ai-man-sys' ); ?></label></th>
						<td>
							<select id="transfer_type" name="transfer_type">
								<option value="standard"><?php esc_html_e( 'Standard', 'ai-man-sys' ); ?></option>
								<option value="return"><?php esc_html_e( 'Return', 'ai-man-sys' ); ?></option>
								<option value="emergency"><?php esc_html_e( 'Emergency', 'ai-man-sys' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="transfer_notes"><?php esc_html_e( 'Notes', 'ai-man-sys' ); ?></label></th>
						<td>
							<textarea id="transfer_notes" name="notes" rows="3" style="width: 100%; max-width: 400px;"></textarea>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( esc_html__( 'Create Transfer Draft', 'ai-man-sys' ) ); ?>
		</form>
		<?php
	}

	private function render_transfer_list( array $transfers, string $type = 'outgoing' ): void {
		?>
		<table class="widefat striped" style="margin: 20px 0;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Items', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Created', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ai-man-sys' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $transfers as $transfer ) : ?>
					<?php $transfer_id = (int) ( $transfer['id'] ?? 0 ); ?>
					<tr>
						<td><strong><?php echo esc_html( $transfer['transfer_code'] ?? 'N/A' ); ?></strong></td>
						<td><?php echo esc_html( AIMS_Inventory_Overview_Data_Provider::get_transfer_status_label( $transfer['transfer_status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $transfer['item_count'] ?? 0 ); ?></td>
						<td><?php echo esc_html( mysql2date( 'M j, Y @ g:i a', $transfer['created_at'] ?? '' ) ); ?></td>
						<td>
							<?php if ( 'pending' === (string) $transfer['transfer_status'] ) : ?>
								<button type="button" class="button button-small" onclick="jQuery('#transfer-detail-<?php echo esc_attr( $transfer_id ); ?>').toggle();">
									<?php esc_html_e( 'View & Add Items', 'ai-man-sys' ); ?>
								</button>
							<?php elseif ( 'dispatched' === (string) $transfer['transfer_status'] ) : ?>
								<span class="inline"><?php esc_html_e( 'Dispatched • In Transit', 'ai-man-sys' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( 'pending' === (string) $transfer['transfer_status'] ) : ?>
						<tr id="transfer-detail-<?php echo esc_attr( $transfer_id ); ?>" style="display: none;">
							<td colspan="5">
								<?php $this->render_transfer_detail( $transfer ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_transfer_detail( array $transfer ): void {
		$transfer_id = (int) ( $transfer['id'] ?? 0 );
		$items       = $transfer['items'] ?? array();
		$buckets     = $this->data_provider->get_available_buckets( $this->user_vendor_id );
		$products    = $this->data_provider->get_available_products();
		?>
		<div style="background: #fafafa; padding: 15px; border-left: 4px solid #0073aa;">
			<h4><?php esc_html_e( 'Items in Transfer', 'ai-man-sys' ); ?></h4>

			<?php if ( ! empty( $items ) ) : ?>
				<table class="striped" style="width: 100%; margin-bottom: 20px; font-size: 13px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'ai-man-sys' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'ai-man-sys' ); ?></th>
							<th><?php esc_html_e( 'From Bucket', 'ai-man-sys' ); ?></th>
							<th><?php esc_html_e( 'To Bucket', 'ai-man-sys' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( get_post_type_object( 'product' ) !== null ? esc_html( 'Product #' . $item['product_id'] ) : 'N/A' ); ?></td>
								<td><?php echo esc_html( number_format( (float) ( $item['requested_quantity'] ?? 0 ), 2 ) ); ?></td>
								<td><?php echo esc_html( $item['source_bucket_code'] ?? 'N/A' ); ?></td>
								<td><?php echo esc_html( $item['target_bucket_code'] ?? 'N/A' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color: #666;"><?php esc_html_e( 'No items added yet.', 'ai-man-sys' ); ?></p>
			<?php endif; ?>

			<h4><?php esc_html_e( 'Add Item to Transfer', 'ai-man-sys' ); ?></h4>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_add_item' ) ); ?>" style="display: grid; gap: 10px; max-width: 500px;">
				<?php wp_nonce_field( 'aims_inventory_transfer_add_item', '_aims_inventory_transfer_add_item_nonce' ); ?>
				<input type="hidden" name="transfer_id" value="<?php echo esc_attr( $transfer_id ); ?>" />

				<div>
					<label><?php esc_html_e( 'Product (SKU or name)', 'ai-man-sys' ); ?></label>
					<input type="text" name="product_input" placeholder="<?php esc_attr_e( 'Enter product SKU or ID', 'ai-man-sys' ); ?>" required style="width: 100%; padding: 5px;" />
					<small><?php esc_html_e( 'Can be scanned via barcode', 'ai-man-sys' ); ?></small>
				</div>

				<div>
					<label><?php esc_html_e( 'From Bucket', 'ai-man-sys' ); ?></label>
					<select name="source_bucket_id" required style="width: 100%; padding: 5px;">
						<option value=""><?php esc_html_e( '-- Select --', 'ai-man-sys' ); ?></option>
						<?php foreach ( $buckets as $bucket ) : ?>
							<option value="<?php echo esc_attr( $bucket['id'] ); ?>"><?php echo esc_html( $bucket['bucket_code'] . ' - ' . $bucket['bucket_name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<label><?php esc_html_e( 'To Bucket', 'ai-man-sys' ); ?></label>
					<select name="target_bucket_id" required style="width: 100%; padding: 5px;">
						<option value=""><?php esc_html_e( '-- Select --', 'ai-man-sys' ); ?></option>
						<?php foreach ( $buckets as $bucket ) : ?>
							<option value="<?php echo esc_attr( $bucket['id'] ); ?>"><?php echo esc_html( $bucket['bucket_code'] . ' - ' . $bucket['bucket_name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<label><?php esc_html_e( 'Quantity', 'ai-man-sys' ); ?></label>
					<input type="number" name="quantity" step="0.0001" min="0.0001" required style="width: 100%; padding: 5px;" />
				</div>

				<button type="submit" class="button button-primary" style="align-self: flex-start;"><?php esc_html_e( 'Add Item', 'ai-man-sys' ); ?></button>
			</form>

			<h4 style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 15px;"><?php esc_html_e( 'Ready to Send?', 'ai-man-sys' ); ?></h4>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_dispatch' ) ); ?>" style="display: inline;">
				<?php wp_nonce_field( 'aims_inventory_transfer_dispatch', '_aims_inventory_transfer_dispatch_nonce' ); ?>
				<input type="hidden" name="transfer_id" value="<?php echo esc_attr( $transfer_id ); ?>" />
				<?php submit_button( esc_html__( 'Dispatch Transfer (Mark In Transit)', 'ai-man-sys' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_receipt_list( array $transfers ): void {
		?>
		<table class="widefat striped" style="margin: 20px 0;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'From Vendor', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Items', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Dispatched', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ai-man-sys' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $transfers as $transfer ) : ?>
					<?php $transfer_id = (int) ( $transfer['id'] ?? 0 ); ?>
					<tr>
						<td><strong><?php echo esc_html( $transfer['transfer_code'] ?? 'N/A' ); ?></strong></td>
						<td><?php echo esc_html( 'Vendor #' . $transfer['source_vendor_id'] ); ?></td>
						<td><?php echo esc_html( $transfer['item_count'] ?? 0 ); ?></td>
						<td><?php echo esc_html( AIMS_Inventory_Overview_Data_Provider::get_transfer_status_label( $transfer['transfer_status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( mysql2date( 'M j @ g:i a', $transfer['dispatch_confirmed_at'] ?? '' ) ); ?></td>
						<td>
							<?php if ( 'dispatched' === (string) $transfer['transfer_status'] ) : ?>
								<button type="button" class="button button-small" onclick="jQuery('#receipt-detail-<?php echo esc_attr( $transfer_id ); ?>').toggle();">
									<?php esc_html_e( 'Confirm Receipt', 'ai-man-sys' ); ?>
								</button>
							<?php else : ?>
								<span class="inline"><?php esc_html_e( 'Completed', 'ai-man-sys' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( 'dispatched' === (string) $transfer['transfer_status'] ) : ?>
						<tr id="receipt-detail-<?php echo esc_attr( $transfer_id ); ?>" style="display: none;">
							<td colspan="6">
								<?php $this->render_receipt_form( $transfer ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_receipt_form( array $transfer ): void {
		$transfer_id = (int) ( $transfer['id'] ?? 0 );
		$items       = $transfer['items'] ?? array();
		?>
		<div style="background: #fafafa; padding: 15px; border-left: 4px solid #0073aa;">
			<h4><?php esc_html_e( 'Confirm Receipt of Items', 'ai-man-sys' ); ?></h4>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_confirm_receipt' ) ); ?>">
				<?php wp_nonce_field( 'aims_inventory_transfer_confirm_receipt', '_aims_inventory_transfer_confirm_receipt_nonce' ); ?>
				<input type="hidden" name="transfer_id" value="<?php echo esc_attr( $transfer_id ); ?>" />

				<table class="form-table" style="width: 100%;">
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<?php $item_id = (int) ( $item['id'] ?? 0 ); ?>
							<tr>
								<th scope="row">
									<label>
										<?php esc_html_e( 'Product #', 'ai-man-sys' ); ?>
										<?php echo esc_html( $item['product_id'] ?? 0 ); ?>
									</label>
								</th>
								<td>
									<div style="margin-bottom: 10px;">
										<span style="font-weight: bold;"><?php esc_html_e( 'Expected Qty:', 'ai-man-sys' ); ?></span>
										<?php echo esc_html( number_format( (float) ( $item['requested_quantity'] ?? 0 ), 2 ) ); ?>
									</div>
									<label><?php esc_html_e( 'Received Qty:', 'ai-man-sys' ); ?></label>
									<input type="number" name="items[<?php echo esc_attr( $item_id ); ?>][received_quantity]" step="0.0001" min="0" value="<?php echo esc_attr( number_format( (float) ( $item['requested_quantity'] ?? 0 ), 4 ) ); ?>" style="width: 100%; padding: 5px; max-width: 150px;" required />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div style="margin-top: 20px;">
					<?php submit_button( esc_html__( 'Confirm Receipt', 'ai-man-sys' ), 'primary' ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	private function get_vendor_options(): array {
		// Simplified vendor list - in production, would query vendor repo
		return array(
			1 => 'Vendor 1',
			2 => 'Vendor 2',
			3 => 'Vendor 3',
		);
	}

	private function resolve_user_vendor(): int {
		// Simplified - in production, would use responsibility repo
		// For now, return vendor ID 1 as demo
		return 1;
	}
}
