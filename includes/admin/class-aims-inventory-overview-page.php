<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Overview_Page {
	private $data_provider;
	private $actions;

	public function __construct( AIMS_Inventory_Overview_Data_Provider $data_provider = null ) {
		$this->data_provider = $data_provider ?: new AIMS_Inventory_Overview_Data_Provider();
		$this->actions       = new AIMS_Inventory_Transfer_Actions();
	}

	public function render(): void {
		$model = $this->data_provider->get_route_model();
		$operator = $this->data_provider->get_operator_context();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Inventory Transfer Workspace', 'ai-man-sys' ) . '</h1>';
		echo '<p>Transfers are endpoint-aware. The workspace separates source and target bucket pools, and elevated operators can override the default route with an audit reason.</p>';

		$this->render_status_message();
		$this->render_operator_summary( $operator, $model );

		echo '<div id="aims-inventory-container" style="margin-top: 20px;">';
		$this->render_dispatch_panel( $operator );
		echo '<hr style="margin: 30px 0;" />';
		$this->render_receipt_panel( $operator );
		echo '<hr style="margin: 30px 0;" />';
		$this->render_workspace_panel( $model, $operator );
		echo '</div>';
		echo '</div>';
	}

	private function render_status_message(): void {
		$status  = isset( $_GET['aims_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_status'] ) ) : '';
		$message = isset( $_GET['aims_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_operator_summary( array $operator, array $route_model ): void {
		$suggested_route = (string) ( $route_model['suggested_route_label'] ?? 'Default route' );
		$note            = (string) ( $route_model['suggested_route_note'] ?? '' );
		$is_elevated     = ! empty( $operator['is_elevated'] );
		$node_label      = (string) ( $operator['node_label'] ?? 'Current operator' );
		$node_type       = (string) ( $operator['node_type'] ?? 'endpoint' );
		$node_id         = (int) ( $operator['node_id'] ?? 0 );

		echo '<div class="notice notice-info inline" style="padding:12px 16px; margin:20px 0;">';
		echo '<p style="margin:0 0 8px 0;"><strong>Operator context:</strong> ' . esc_html( $node_label ) . ' (' . esc_html( $node_type ) . ' #' . esc_html( (string) $node_id ) . ')</p>';
		echo '<p style="margin:0 0 8px 0;"><strong>Suggested route:</strong> ' . esc_html( $suggested_route ) . '</p>';
		if ( '' !== $note ) {
			echo '<p style="margin:0;">' . esc_html( $note ) . '</p>';
		}
		if ( $is_elevated ) {
			echo '<p style="margin:8px 0 0 0;">Elevated operators can bypass the default route with an audit reason.</p>';
		}
		echo '</div>';
	}

	private function render_dispatch_panel( array $operator ): void {
		$node_id   = (int) ( $operator['node_id'] ?? 0 );
		$node_type = (string) ( $operator['node_type'] ?? 'endpoint' );

		echo '<h2 style="border-bottom: 2px solid #ccc; padding-bottom: 10px;">Dispatch Queue</h2>';

		if ( $node_id <= 0 ) {
			echo '<p>No active endpoint context is connected yet. The queue will populate when a runtime endpoint provider supplies the operator node.</p>';
		} else {
			$outgoing = $this->data_provider->get_outgoing_transfers( $node_id, $node_type );
			if ( empty( $outgoing ) ) {
				echo '<p>No outgoing transfers are currently assigned to this endpoint.</p>';
			} else {
				echo '<h3>Active Dispatches</h3>';
				$this->render_transfer_list( $outgoing, 'dispatch' );
			}
		}

		echo '<h3 style="margin-top: 30px;">Create Transfer Draft</h3>';
		$this->render_create_transfer_form( 'dispatch' );
	}

	private function render_receipt_panel( array $operator ): void {
		$node_id   = (int) ( $operator['node_id'] ?? 0 );
		$node_type = (string) ( $operator['node_type'] ?? 'endpoint' );

		echo '<h2 style="border-bottom: 2px solid #ccc; padding-bottom: 10px;">Receipt Queue</h2>';

		if ( $node_id <= 0 ) {
			echo '<p>No active endpoint context is connected yet. Incoming receipt work will appear when the runtime provider resolves the operator node.</p>';
			return;
		}

		$incoming = $this->data_provider->get_incoming_transfers( $node_id, $node_type );
		if ( empty( $incoming ) ) {
			echo '<p>No incoming transfers awaiting receipt for this endpoint.</p>';
			return;
		}

		echo '<h3>Transfers Awaiting Receipt</h3>';
		$this->render_receipt_list( $incoming );
	}

	private function render_workspace_panel( array $route_model, array $operator ): void {
		$source_pool = (array) ( $route_model['source_pool'] ?? array() );
		$target_pool = (array) ( $route_model['target_pool'] ?? array() );
		$can_override = ! empty( $route_model['can_override_route'] );
		$route_suggestions = (array) ( $route_model['route_suggestions'] ?? array() );
		?>
		<h3>Endpoint Pools</h3>
		<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;align-items:start;">
			<div style="background:#fff;border:1px solid #dcdcde;padding:16px;">
				<h4 style="margin-top:0;">Source Endpoint Pool</h4>
				<p class="description">Choose the source bucket pool for the dispatch route.</p>
				<?php $this->render_bucket_pool_list( $source_pool, 'source' ); ?>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;padding:16px;">
				<h4 style="margin-top:0;">Target Endpoint Pool</h4>
				<p class="description">Choose the target bucket pool for the receiving route.</p>
				<?php $this->render_bucket_pool_list( $target_pool, 'target' ); ?>
			</div>
		</div>
		<?php if ( ! empty( $route_suggestions ) ) : ?>
			<div style="margin-top:24px;background:#fff;border:1px solid #dcdcde;padding:16px;">
				<h4 style="margin-top:0;">Suggested Routes</h4>
				<ul style="list-style:disc;padding-left:20px;margin:0;">
					<?php foreach ( $route_suggestions as $suggestion ) : ?>
						<li><?php echo esc_html( (string) ( $suggestion['label'] ?? '' ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php if ( $can_override ) : ?>
			<div style="margin-top:24px;background:#f8f9fa;border:1px solid #dcdcde;padding:16px;">
				<h4 style="margin-top:0;">Override / Direct Collection</h4>
				<p style="margin-top:0;">Elevated operators can bypass the suggested route when a live collection or recovery path is required.</p>
				<ul style="list-style:disc;padding-left:20px;margin-bottom:0;">
					<li>Set the route mode to direct collection or recovery.</li>
					<li>Enter an audit reason before submitting.</li>
					<li>The default route remains the suggested path, not a hard lock.</li>
				</ul>
			</div>
		<?php endif; ?>
		<?php
	}

	private function render_create_transfer_form( string $context = 'dispatch' ): void {
		$route_model  = $this->data_provider->get_route_model();
		$operator     = $this->data_provider->get_operator_context();
		$endpoints    = (array) $this->data_provider->get_runtime_endpoint_directory();
		$can_override = ! empty( $route_model['can_override_route'] );
		$current_key  = (string) ( $operator['endpoint_key'] ?? '' );
		$route_guidance = trim( implode( ' | ', array_filter( array(
			(string) ( $route_model['suggested_route_label'] ?? '' ),
			(string) ( $route_model['suggested_route_note'] ?? '' ),
		) ) ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_create_draft' ) ); ?>" style="background:#f5f5f5;padding:16px;border:1px solid #ddd;border-radius:4px;max-width:920px;">
			<?php wp_nonce_field( 'aims_inventory_transfer_create_draft', '_aims_inventory_transfer_create_draft_nonce' ); ?>
			<input type="hidden" name="transfer_context" value="<?php echo esc_attr( $context ); ?>" />
			<input type="hidden" name="source_node_type" value="<?php echo esc_attr( (string) ( $operator['node_type'] ?? 'vendor' ) ); ?>" />
			<input type="hidden" name="source_node_id" value="<?php echo esc_attr( (string) ( $operator['node_id'] ?? 0 ) ); ?>" />
			<input type="hidden" name="route_guidance" value="<?php echo esc_attr( $route_guidance ); ?>" />
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Source Endpoint', 'ai-man-sys' ); ?></th>
						<td>
							<?php if ( $can_override ) : ?>
								<select id="source_endpoint_selection" name="source_endpoint_selection" required>
									<option value=""><?php esc_html_e( '-- Select Source Endpoint --', 'ai-man-sys' ); ?></option>
									<?php $this->render_endpoint_options( $endpoints, '', $current_key ); ?>
								</select>
								<p class="description"><?php esc_html_e( 'Elevated operators can originate transfers from any authorized custody node. Use this for normal warehouse routing, direct collection, or recovery work.', 'ai-man-sys' ); ?></p>
							<?php else : ?>
								<input type="hidden" name="source_endpoint_selection" value="<?php echo esc_attr( (string) ( $operator['node_type'] ?? 'vendor' ) . ':' . (string) ( $operator['node_id'] ?? 0 ) ); ?>" />
								<strong><?php echo esc_html( (string) ( $operator['node_label'] ?? 'Current endpoint' ) ); ?></strong>
								<p class="description"><?php esc_html_e( 'The draft starts from your current custody node. You will choose the destination below, then assign actual buckets per line item.', 'ai-man-sys' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="target_endpoint_selection"><?php esc_html_e( 'Target Endpoint', 'ai-man-sys' ); ?></label></th>
						<td>
							<select id="target_endpoint_selection" name="target_endpoint_selection" required>
								<option value=""><?php esc_html_e( '-- Select Target Endpoint --', 'ai-man-sys' ); ?></option>
								<?php $this->render_endpoint_options( $endpoints, $current_key ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose the receiving custody node. The item-entry step will then use separate source and target bucket pools for that route.', 'ai-man-sys' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="transfer_type"><?php esc_html_e( 'Route Mode', 'ai-man-sys' ); ?></label></th>
						<td>
							<select id="transfer_type" name="transfer_type">
								<option value="standard"><?php esc_html_e( 'Default suggested route', 'ai-man-sys' ); ?></option>
								<option value="direct_collection"><?php esc_html_e( 'Direct collection', 'ai-man-sys' ); ?></option>
								<option value="recovery"><?php esc_html_e( 'Recovery route', 'ai-man-sys' ); ?></option>
								<option value="termination_collection"><?php esc_html_e( 'Termination collection', 'ai-man-sys' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The default route is suggested, not forced. Elevated operators can bypass it with an audit reason.', 'ai-man-sys' ); ?></p>
						</td>
					</tr>
					<?php if ( $can_override ) : ?>
						<tr>
							<th scope="row"><label for="override_route"><?php esc_html_e( 'Route Override', 'ai-man-sys' ); ?></label></th>
							<td>
								<label>
									<input id="override_route" type="checkbox" name="override_route" value="1" />
									<?php esc_html_e( 'This transfer bypasses the normal custody route.', 'ai-man-sys' ); ?>
								</label>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $can_override ) : ?>
						<tr>
							<th scope="row"><label for="route_override_reason"><?php esc_html_e( 'Override / Audit Reason', 'ai-man-sys' ); ?></label></th>
							<td>
								<textarea id="route_override_reason" name="override_reason" rows="3" style="width:100%;max-width:520px;" placeholder="<?php esc_attr_e( 'Explain why the default route is being bypassed.', 'ai-man-sys' ); ?>"></textarea>
								<p class="description"><?php esc_html_e( 'Required for direct collection or recovery routing. Leave blank for the default route.', 'ai-man-sys' ); ?></p>
							</td>
						</tr>
					<?php else : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Route Notes', 'ai-man-sys' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'Route overrides are reserved for elevated operators. Default route selection remains available here for normal route planning.', 'ai-man-sys' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="transfer_notes"><?php esc_html_e( 'Notes', 'ai-man-sys' ); ?></label></th>
						<td><textarea id="transfer_notes" name="notes" rows="3" style="width:100%;max-width:520px;"></textarea></td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( esc_html__( 'Create Transfer Draft', 'ai-man-sys' ) ); ?>
		</form>
		<?php
	}

	private function render_endpoint_options( array $endpoints, string $exclude_key = '', string $selected_key = '' ): void {
		foreach ( $endpoints as $endpoint_key => $endpoint ) {
			if ( ! is_array( $endpoint ) || ! empty( $endpoint['is_alias'] ) ) {
				continue;
			}

			$endpoint_key = sanitize_key( (string) $endpoint_key );
			if ( '' === $endpoint_key || ( '' !== $exclude_key && $endpoint_key === sanitize_key( $exclude_key ) ) ) {
				continue;
			}

			$node_type = sanitize_key( (string) ( $endpoint['node_type'] ?? $endpoint['endpoint_type'] ?? '' ) );
			$node_id   = (int) ( $endpoint['node_id'] ?? 0 );
			if ( '' === $node_type || $node_id <= 0 ) {
				continue;
			}

			$value = $node_type . ':' . $node_id;
			$label = (string) ( $endpoint['endpoint_label'] ?? $endpoint_key );
			$selected = ( $endpoint_key === sanitize_key( $selected_key ) ) ? ' selected="selected"' : '';
			echo '<option value="' . esc_attr( $value ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
		}
	}

	private function render_bucket_pool_list( array $pool, string $side ): void {
		if ( empty( $pool ) ) {
			echo '<p>No ' . esc_html( $side ) . ' bucket pool is connected yet.</p>';
			return;
		}

		echo '<div style="display:flex;flex-direction:column;gap:10px;max-height:260px;overflow:auto;">';
		foreach ( $pool as $bucket ) {
			echo '<div style="border:1px solid #dcdcde;background:#fff;padding:10px 12px;">';
			echo '<strong>' . esc_html( (string) ( $bucket['bucket_label'] ?? $bucket['bucket_code'] ?? 'Bucket' ) ) . '</strong>';
			echo '<div style="margin-top:4px;">' . esc_html( (string) ( $bucket['bucket_code'] ?? '' ) ) . ' | ' . esc_html( (string) ( $bucket['endpoint_label'] ?? 'Endpoint' ) ) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_bucket_options( array $pool ): void {
		foreach ( $pool as $bucket ) {
			$label = (string) ( $bucket['bucket_label'] ?? $bucket['bucket_code'] ?? 'Bucket' );
			$code  = (string) ( $bucket['bucket_code'] ?? '' );
			$endpoint_label = (string) ( $bucket['endpoint_label'] ?? '' );
			$option_label = trim( $label . ' (' . $code . ')' . ( '' !== $endpoint_label ? ' - ' . $endpoint_label : '' ) );
			echo '<option value="' . esc_attr( (string) ( $bucket['id'] ?? 0 ) ) . '">' . esc_html( $option_label ) . '</option>';
		}
	}

	private function render_transfer_list( array $transfers, string $type = 'outgoing' ): void {
		?>
		<table class="widefat striped" style="margin:20px 0;">
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
								<button type="button" class="button button-small" onclick="jQuery('#transfer-detail-<?php echo esc_attr( $transfer_id ); ?>').toggle();"><?php esc_html_e( 'View & Add Items', 'ai-man-sys' ); ?></button>
							<?php elseif ( 'dispatched' === (string) $transfer['transfer_status'] ) : ?>
								<span class="inline"><?php esc_html_e( 'Dispatched - In Transit', 'ai-man-sys' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( 'pending' === (string) $transfer['transfer_status'] ) : ?>
						<tr id="transfer-detail-<?php echo esc_attr( $transfer_id ); ?>" style="display:none;">
							<td colspan="5"><?php $this->render_transfer_detail( $transfer ); ?></td>
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
		$source_pool = $this->resolve_transfer_bucket_pool( $transfer, 'source' );
		$target_pool = $this->resolve_transfer_bucket_pool( $transfer, 'target' );
		?>
		<div style="background:#fafafa;padding:15px;border-left:4px solid #0073aa;">
			<h4><?php esc_html_e( 'Items in Transfer', 'ai-man-sys' ); ?></h4>
			<?php if ( ! empty( $items ) ) : ?>
				<table class="striped" style="width:100%;margin-bottom:20px;font-size:13px;">
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
								<td><?php echo esc_html( 'Product #' . (int) ( $item['product_id'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( number_format( (float) ( $item['requested_quantity'] ?? 0 ), 2 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $item['source_bucket_code'] ?? 'N/A' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $item['target_bucket_code'] ?? 'N/A' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color:#666;"><?php esc_html_e( 'No items added yet.', 'ai-man-sys' ); ?></p>
			<?php endif; ?>

			<h4><?php esc_html_e( 'Add Item to Transfer', 'ai-man-sys' ); ?></h4>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_add_item' ) ); ?>" style="display:grid;gap:10px;max-width:560px;">
				<?php wp_nonce_field( 'aims_inventory_transfer_add_item', '_aims_inventory_transfer_add_item_nonce' ); ?>
				<input type="hidden" name="transfer_id" value="<?php echo esc_attr( $transfer_id ); ?>" />
				<div>
					<label for="product_input_<?php echo esc_attr( $transfer_id ); ?>"><?php esc_html_e( 'Product (SKU or name)', 'ai-man-sys' ); ?></label>
					<?php
					$product_input = new AIMS_Admin_Input_Element( array(
						'id'          => 'product_input_' . $transfer_id,
						'name'        => 'product_input',
						'placeholder' => __( 'Enter product SKU or ID', 'ai-man-sys' ),
						'required'    => true,
						'scan'        => true,
						'class'       => '',
						'attributes'  => array( 'style' => 'width:100%;padding:5px;' ),
					) );
					echo $product_input->render();
					?>
				</div>
				<div>
					<label><?php esc_html_e( 'From Bucket', 'ai-man-sys' ); ?></label>
					<select name="source_bucket_id" required style="width:100%;padding:5px;">
						<option value=""><?php esc_html_e( '-- Select --', 'ai-man-sys' ); ?></option>
						<?php foreach ( $source_pool as $bucket ) : ?>
							<option value="<?php echo esc_attr( (string) ( $bucket['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $bucket['bucket_code'] ?? '' ) . ' - ' . (string) ( $bucket['bucket_label'] ?? '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label><?php esc_html_e( 'To Bucket', 'ai-man-sys' ); ?></label>
					<select name="target_bucket_id" required style="width:100%;padding:5px;">
						<option value=""><?php esc_html_e( '-- Select --', 'ai-man-sys' ); ?></option>
						<?php foreach ( $target_pool as $bucket ) : ?>
							<option value="<?php echo esc_attr( (string) ( $bucket['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $bucket['bucket_code'] ?? '' ) . ' - ' . (string) ( $bucket['bucket_label'] ?? '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label><?php esc_html_e( 'Quantity', 'ai-man-sys' ); ?></label>
					<input type="number" name="quantity" step="0.0001" min="0.0001" required style="width:100%;padding:5px;" />
				</div>
				<button type="submit" class="button button-primary" style="align-self:flex-start;"><?php esc_html_e( 'Add Item', 'ai-man-sys' ); ?></button>
			</form>

			<h4 style="margin-top:30px;border-top:1px solid #ddd;padding-top:15px;"><?php esc_html_e( 'Ready to Send?', 'ai-man-sys' ); ?></h4>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_dispatch' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'aims_inventory_transfer_dispatch', '_aims_inventory_transfer_dispatch_nonce' ); ?>
				<input type="hidden" name="transfer_id" value="<?php echo esc_attr( $transfer_id ); ?>" />
				<input type="hidden" name="override_route" value="<?php echo esc_attr( ! empty( $transfer['override_route'] ) ? '1' : '0' ); ?>" />
				<input type="hidden" name="audit_reason" value="<?php echo esc_attr( (string) ( $transfer['override_reason'] ?? '' ) ); ?>" />
				<input type="hidden" name="route_guidance" value="<?php echo esc_attr( (string) ( $transfer['override_note'] ?? '' ) ); ?>" />
				<?php submit_button( esc_html__( 'Dispatch Transfer (Mark In Transit)', 'ai-man-sys' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_receipt_list( array $transfers ): void {
		?>
		<table class="widefat striped" style="margin:20px 0;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'ai-man-sys' ); ?></th>
					<th><?php esc_html_e( 'From Node', 'ai-man-sys' ); ?></th>
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
						<td><?php echo esc_html( $this->describe_transfer_endpoint( $transfer, 'source' ) ); ?></td>
						<td><?php echo esc_html( $transfer['item_count'] ?? 0 ); ?></td>
						<td><?php echo esc_html( AIMS_Inventory_Overview_Data_Provider::get_transfer_status_label( $transfer['transfer_status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( mysql2date( 'M j @ g:i a', $transfer['dispatch_confirmed_at'] ?? '' ) ); ?></td>
						<td>
							<?php if ( 'dispatched' === (string) $transfer['transfer_status'] ) : ?>
								<button type="button" class="button button-small" onclick="jQuery('#receipt-detail-<?php echo esc_attr( $transfer_id ); ?>').toggle();"><?php esc_html_e( 'Confirm Receipt', 'ai-man-sys' ); ?></button>
							<?php else : ?>
								<span class="inline"><?php esc_html_e( 'Completed', 'ai-man-sys' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( 'dispatched' === (string) $transfer['transfer_status'] ) : ?>
						<tr id="receipt-detail-<?php echo esc_attr( $transfer_id ); ?>" style="display:none;">
							<td colspan="6"><?php $this->render_receipt_form( $transfer ); ?></td>
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
		<div style="background:#fafafa;padding:15px;border-left:4px solid #0073aa;">
			<h4><?php esc_html_e( 'Confirm Receipt of Items', 'ai-man-sys' ); ?></h4>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=aims_inventory_transfer_confirm_receipt' ) ); ?>">
				<?php wp_nonce_field( 'aims_inventory_transfer_confirm_receipt', '_aims_inventory_transfer_confirm_receipt_nonce' ); ?>
				<input type="hidden" name="transfer_id" value="<?php echo esc_attr( $transfer_id ); ?>" />
				<input type="hidden" name="override_route" value="<?php echo esc_attr( ! empty( $transfer['override_route'] ) ? '1' : '0' ); ?>" />
				<input type="hidden" name="audit_reason" value="<?php echo esc_attr( (string) ( $transfer['override_reason'] ?? '' ) ); ?>" />
				<input type="hidden" name="route_guidance" value="<?php echo esc_attr( (string) ( $transfer['override_note'] ?? '' ) ); ?>" />
				<table class="form-table" style="width:100%;">
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
									<div style="margin-bottom:10px;"><span style="font-weight:bold;"><?php esc_html_e( 'Expected Qty:', 'ai-man-sys' ); ?></span> <?php echo esc_html( number_format( (float) ( $item['requested_quantity'] ?? 0 ), 2 ) ); ?></div>
									<label><?php esc_html_e( 'Received Qty:', 'ai-man-sys' ); ?></label>
									<input type="number" name="items[<?php echo esc_attr( $item_id ); ?>][received_quantity]" step="0.0001" min="0" value="<?php echo esc_attr( number_format( (float) ( $item['requested_quantity'] ?? 0 ), 4 ) ); ?>" style="width:100%;padding:5px;max-width:150px;" required />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div style="margin-top:20px;"><?php submit_button( esc_html__( 'Confirm Receipt', 'ai-man-sys' ), 'primary' ); ?></div>
			</form>
		</div>
		<?php
	}

	private function resolve_transfer_bucket_pool( array $transfer, string $side ): array {
		$side = 'target' === $side ? 'target' : 'source';
		$node_type = sanitize_key( (string) ( $transfer[ $side . '_node_type' ] ?? 'vendor' ) );
		$node_id   = (int) ( $transfer[ $side . '_node_id' ] ?? 0 );
		$context   = array(
			'user_id'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'vendor_id' => ( 'vendor' === $node_type ) ? $node_id : 0,
			'vendor_ids'=> ( 'supervisor' === $node_type && $node_id > 0 ) ? array( $node_id ) : array(),
		);

		if ( 'target' === $side ) {
			return (array) $this->data_provider->get_target_buckets( $node_id, $node_type, $context );
		}

		return (array) $this->data_provider->get_source_buckets( $node_id, $node_type, $context );
	}

	private function describe_transfer_endpoint( array $transfer, string $side ): string {
		$side      = 'target' === $side ? 'target' : 'source';
		$node_type = sanitize_key( (string) ( $transfer[ $side . '_node_type' ] ?? 'vendor' ) );
		$node_id   = (int) ( $transfer[ $side . '_node_id' ] ?? 0 );

		$endpoint = $this->data_provider->get_bucket_sourcing_context(
			$node_id,
			$node_type,
			array(
				'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			)
		);

		$endpoint_data = (array) ( $endpoint[ $side . '_endpoint' ] ?? $endpoint['source_endpoint'] ?? array() );
		$label = (string) ( $endpoint_data['endpoint_label'] ?? '' );
		if ( '' !== $label ) {
			return $label;
		}

		return ucfirst( $node_type ) . ' #' . $node_id;
	}
}
