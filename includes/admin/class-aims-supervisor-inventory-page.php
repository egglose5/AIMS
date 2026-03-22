<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Supervisor_Inventory_Page {
	private $data_provider;
	private const ACTION_NONCE = 'aims_supervisor_inventory_action';

	public function __construct( AIMS_Supervisor_Inventory_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_VIEW_BUCKETS )
			&& ! current_user_can( AIMS_Capabilities::CAP_PORTAL_BUCKETS )
			&& ! current_user_can( AIMS_Capabilities::CAP_MANAGE_BUCKETS )
			&& ! current_user_can( AIMS_Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access supervisor inventory.', 'ai-man-sys' ) );
		}

		$notice = $this->handle_actions();
		$rows    = $this->data_provider->get_rows();
		$summary = $this->data_provider->get_summary();
		$transfer_rows = $this->data_provider->get_event_transfer_rows();
		$transfer_summary = $this->data_provider->get_event_transfer_summary();
		$access_mode = $this->data_provider->get_access_mode_label();

		echo '<div class="wrap">';
		echo '<h1>Supervisor Inventory</h1>';
		echo '<p>Inventory buckets are bucket-scoped by vendor or event ownership so future supervisor screens can show only the inventory a user should see.</p>';
		echo '<p>Use the transfer rows below to move stock to an event bucket or return it to warehouse once the show is over.</p>';
		echo '<p><strong>Access:</strong> ' . esc_html( $access_mode ) . '</p>';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-' . esc_attr( $this->notice_type( $notice ) ) . ' inline" style="margin:16px 0 0;"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
		echo '<div class="notice notice-info inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['total'] ) . '</strong> buckets</div>';
		echo '<div class="notice notice-success inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['event'] ) . '</strong> event buckets</div>';
		echo '<div class="notice notice-warning inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['vendor'] ) . '</strong> vendor buckets</div>';
		echo '<div class="notice notice-alt inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $summary['warehouse'] ) . '</strong> warehouse buckets</div>';
		echo '</div>';

		echo '<h2>Event Transfer Scaffolding</h2>';
		echo '<p>Each row shows the show bucket, its matched warehouse source, and the current movement capacity for moving stock out to the event or returning it back to warehouse.</p>';
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
		echo '<div class="notice notice-info inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $transfer_summary['ready_to_transfer'] ) . '</strong> ready to transfer</div>';
		echo '<div class="notice notice-success inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $transfer_summary['at_show'] ) . '</strong> at show</div>';
		echo '<div class="notice notice-warning inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $transfer_summary['partially_returned'] ) . '</strong> partially returned</div>';
		echo '<div class="notice notice-alt inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $transfer_summary['show_complete'] ) . '</strong> show complete</div>';
		echo '<div class="notice notice-error inline" style="margin:0;padding:12px 16px;"><strong>' . esc_html( (string) $transfer_summary['source_missing'] ) . '</strong> missing source</div>';
		echo '</div>';

		if ( ! empty( $transfer_rows ) ) {
			echo '<table class="widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>Event Bucket</th>';
			echo '<th>Warehouse Source</th>';
			echo '<th>Event Qty</th>';
			echo '<th>Event Available</th>';
			echo '<th>Transfer In</th>';
			echo '<th>Return In</th>';
			echo '<th>State</th>';
			echo '<th>Last Movement</th>';
			echo '<th>Actions</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $transfer_rows as $row ) {
				$bucket = ! empty( $row['bucket'] ) && is_array( $row['bucket'] ) ? $row['bucket'] : array();
				$warehouse_bucket = ! empty( $row['warehouse_bucket'] ) && is_array( $row['warehouse_bucket'] ) ? $row['warehouse_bucket'] : array();
				$workflow_actions = ! empty( $row['workflow_actions'] ) && is_array( $row['workflow_actions'] ) ? $row['workflow_actions'] : array();
				$summary_row = ! empty( $row['transfer_summary'] ) && is_array( $row['transfer_summary'] ) ? $row['transfer_summary'] : array();
				$transfer_action = ! empty( $workflow_actions['warehouse_to_event'] ) && is_array( $workflow_actions['warehouse_to_event'] ) ? $workflow_actions['warehouse_to_event'] : array();
				$return_action = ! empty( $workflow_actions['event_return'] ) && is_array( $workflow_actions['event_return'] ) ? $workflow_actions['event_return'] : array();
				$transfer_allowed = ! empty( $transfer_action['can_initiate'] );
				$return_allowed = ! empty( $return_action['can_initiate'] );
				echo '<tr>';
				echo '<td>' . esc_html( ! empty( $bucket['bucket_label'] ) ? (string) $bucket['bucket_label'] : 'Unlabeled event bucket' ) . '</td>';
				echo '<td>' . esc_html( ! empty( $warehouse_bucket['bucket_label'] ) ? (string) $warehouse_bucket['bucket_label'] : 'No warehouse source matched' ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $bucket['quantity'] ?? 0 ), 4, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $bucket['available_quantity'] ?? 0 ), 4, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $summary_row['transfer_in_quantity'] ?? 0 ), 4, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) ( $summary_row['return_in_quantity'] ?? 0 ), 4, '.', '' ) ) . '</td>';
				echo '<td>' . esc_html( ! empty( $row['operator_state_label'] ) ? (string) $row['operator_state_label'] : ( ! empty( $row['operator_state'] ) ? (string) $row['operator_state'] : 'ready_to_transfer' ) ) . '</td>';
				echo '<td>' . esc_html( ! empty( $summary_row['last_movement_type'] ) ? (string) $summary_row['last_movement_type'] : 'none' ) . '</td>';
				echo '<td>';
				echo '<div style="display:flex;flex-direction:column;gap:4px;">';
				echo $this->render_transfer_action_block( $transfer_action, 'transfer_warehouse_to_event', $transfer_allowed, 'Send to event' );
				echo $this->render_transfer_action_block( $return_action, 'record_event_return', $return_allowed, 'Return to warehouse' );
				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No accessible inventory buckets are available for this account yet.</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Bucket</th>';
		echo '<th>Scope</th>';
		echo '<th>Type</th>';
		echo '<th>Access</th>';
		echo '<th>Qty</th>';
		echo '<th>Reserved</th>';
		echo '<th>Available</th>';
		echo '<th>Updated</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['bucket_label'] ) . '</td>';
			echo '<td>' . esc_html( $row['scope_label'] ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $row['bucket_type'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['access_label'] ) . '</td>';
			echo '<td>' . esc_html( $row['quantity'] ) . '</td>';
			echo '<td>' . esc_html( $row['reserved_quantity'] ) . '</td>';
			echo '<td>' . esc_html( $row['available_quantity'] ) . '</td>';
			echo '<td>' . esc_html( $row['updated_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function handle_actions(): string {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return '';
		}

		$action = sanitize_key( $_POST['aims_supervisor_inventory_action'] ?? '' );
		if ( '' === $action ) {
			return '';
		}

		check_admin_referer( self::ACTION_NONCE, 'aims_supervisor_inventory_nonce' );

		$quantity = isset( $_POST['quantity_delta'] ) ? abs( (float) $_POST['quantity_delta'] ) : 0.0;
		if ( $quantity <= 0 ) {
			return 'Quantity must be greater than zero.';
		}

		if ( $quantity > 1000000 ) {
			return 'Quantity is too large for a single supervisor transfer.';
		}

		$reference_type = sanitize_key( wp_unslash( $_POST['reference_type'] ?? 'aims_supervisor_inventory_transfer' ) );
		$reference_id   = sanitize_text_field( wp_unslash( $_POST['reference_id'] ?? '' ) );
		$source_bucket  = wp_unslash( $_POST['source_bucket'] ?? '' );
		$destination_bucket = wp_unslash( $_POST['destination_bucket'] ?? '' );

		if ( '' === $reference_id ) {
			return 'Missing transfer reference.';
		}

		$payload = array(
			'reference_type'    => $reference_type ?: 'aims_supervisor_inventory_transfer',
			'reference_id'      => $reference_id,
			'quantity_delta'    => $quantity,
			'source_bucket'     => $source_bucket,
			'destination_bucket' => $destination_bucket,
		);

		$result = null;
		if ( 'transfer_warehouse_to_event' === $action ) {
			$result = $this->data_provider->get_inventory_service()->transfer_warehouse_to_event_bucket( $payload, get_current_user_id() );
		} elseif ( 'record_event_return' === $action ) {
			$result = $this->data_provider->get_inventory_service()->record_event_return( $payload, get_current_user_id() );
		}

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		if ( ! is_array( $result ) ) {
			return 'Unable to process the inventory movement.';
		}

		$source_label = ! empty( $result['source']['bucket_context']['bucket_label'] ) ? (string) $result['source']['bucket_context']['bucket_label'] : 'source bucket';
		$destination_label = ! empty( $result['destination']['bucket_context']['bucket_label'] ) ? (string) $result['destination']['bucket_context']['bucket_label'] : 'destination bucket';
		return 'Inventory movement saved: ' . $source_label . ' -> ' . $destination_label . ' (' . number_format( (float) ( $result['quantity'] ?? $quantity ), 4, '.', '' ) . ').';
	}

	private function render_transfer_action_block( array $action, string $action_name, bool $allowed, string $button_label ): string {
		$reference_id = wp_generate_uuid4();
		$source_bucket = ! empty( $action['source_bucket'] ) && is_array( $action['source_bucket'] ) ? $action['source_bucket'] : array();
		$destination_bucket = ! empty( $action['destination_bucket'] ) && is_array( $action['destination_bucket'] ) ? $action['destination_bucket'] : array();
		$quantity_limit = (float) ( $action['quantity_limit'] ?? 0 );
		$source_label = ! empty( $action['source_label'] ) ? (string) $action['source_label'] : 'Source bucket';
		$destination_label = ! empty( $action['destination_label'] ) ? (string) $action['destination_label'] : 'Destination bucket';
		$helper_text = $allowed ? 'Enter the quantity to move for this show.' : $this->build_blocked_transfer_helper_text( $action );
		$input_value = $quantity_limit > 0 ? number_format( $quantity_limit, 4, '.', '' ) : '0.0000';

		ob_start();
		echo '<div style="padding:8px 0;border-top:1px solid #ddd;">';
		echo '<div style="font-size:12px;"><strong>' . esc_html( ! empty( $action['label'] ) ? (string) $action['label'] : $button_label ) . '</strong></div>';
		echo '<div style="font-size:12px;color:#555;">' . esc_html( $source_label ) . ' -> ' . esc_html( $destination_label ) . '</div>';
		echo '<div style="font-size:11px;color:#666;margin-top:2px;">' . esc_html( $helper_text ) . '</div>';
		echo '<form method="post" style="margin-top:6px;">';
		wp_nonce_field( self::ACTION_NONCE, 'aims_supervisor_inventory_nonce' );
		echo '<input type="hidden" name="aims_supervisor_inventory_action" value="' . esc_attr( $action_name ) . '">';
		echo '<input type="hidden" name="reference_type" value="aims_supervisor_inventory_transfer">';
		echo '<input type="hidden" name="reference_id" value="' . esc_attr( $reference_id ) . '">';
		echo '<input type="hidden" name="source_bucket" value="' . esc_attr( wp_json_encode( $source_bucket ) ) . '">';
		echo '<input type="hidden" name="destination_bucket" value="' . esc_attr( wp_json_encode( $destination_bucket ) ) . '">';
		echo '<input type="number" min="0.0001" step="0.0001" max="' . esc_attr( (string) $quantity_limit ) . '" name="quantity_delta" value="' . esc_attr( $input_value ) . '" style="width:110px;"' . ( $allowed ? '' : ' disabled="disabled"' ) . '> ';
		echo '<button type="submit" class="button button-small"' . ( $allowed ? '' : ' disabled="disabled"' ) . '>' . esc_html( $button_label ) . '</button>';
		if ( ! $allowed ) {
			echo '<div style="font-size:11px;color:#666;margin-top:4px;">This row is not transferable yet.</div>';
		}
		echo '</form>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	private function build_blocked_transfer_helper_text( array $action ): string {
		if ( ! empty( $action['source_missing'] ) ) {
			return 'No warehouse source is matched yet for this event bucket.';
		}

		if ( empty( $action['quantity_limit'] ) ) {
			return 'No transferable quantity is available right now.';
		}

		return 'This row is not transferable yet.';
	}

	private function notice_type( string $notice ): string {
		$lower = strtolower( $notice );
		if ( false !== strpos( $lower, 'unable' ) || false !== strpos( $lower, 'missing' ) || false !== strpos( $lower, 'must be greater' ) || false !== strpos( $lower, 'too large' ) ) {
			return 'error';
		}

		return 'success';
	}
}
