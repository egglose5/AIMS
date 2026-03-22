<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Participation_Page {
	private const PAGE_SLUG = 'aims-event-participation';

	private $data_provider;
	private $event_automation;

	public function __construct(
		AIMS_Event_Participation_Data_Provider $data_provider = null,
		AIMS_Event_Automation_Service $event_automation = null
	) {
		$this->data_provider   = $data_provider ?: new AIMS_Event_Participation_Data_Provider();
		$audit = new AIMS_Audit_Service();
		$vendor_access = new AIMS_Vendor_Access_Service(
			new AIMS_Vendor_User_Access_Repository(),
			new AIMS_Vendor_Repository(),
			$audit
		);
		$this->event_automation = $event_automation ?: new AIMS_Event_Automation_Service(
			new AIMS_Event_Repository(),
			new AIMS_Square_Sale_Repository(),
			new AIMS_Vendor_Event_Assignment_Repository(),
			new AIMS_Event_Financial_Service(
				new AIMS_Event_Repository(),
				new AIMS_Square_Sale_Repository(),
				new AIMS_Event_Expense_Repository(),
				new AIMS_Vendor_Event_Assignment_Repository(),
				new AIMS_Product_Cost_Service(
					new AIMS_Product_Cost_Rule_Repository()
				)
			),
			$vendor_access,
			$audit
		);
	}

	public function render(): void {
		if ( ! $this->can_view_surface() ) {
			wp_die( esc_html__( 'You do not have permission to access event participation.', 'ai-man-sys' ) );
		}

		$is_management_mode = $this->can_manage_surface();
		$notice = $this->handle_actions();
		$rows   = $this->data_provider->get_rows();
		$summary = $this->data_provider->get_summary();
		$selected_event_id = ! empty( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : (int) ( $rows[0]['event_id'] ?? 0 );
		$bundle = $selected_event_id > 0 ? $this->data_provider->get_event_bundle( $selected_event_id ) : array();

		echo '<div class="wrap">';
		echo '<h1>Event Participation</h1>';
		echo '<p>' . esc_html( $this->get_surface_mode_label() ) . '</p>';
		if ( $is_management_mode ) {
			echo '<p>Use this screen to open request windows, review FCFS request queues, approve vendors, and apply manual fallback assignments.</p>';
			echo '<p>Operator flow: open the request window when the event is available, approve the next queued vendor in sequence, and use manual fallback only when the queue or capacity needs an override.</p>';
		} else {
			echo '<p>Portal flow: review request status, queue position, and assignment visibility. State-changing controls are reserved for event managers.</p>';
		}

		if ( ! empty( $notice ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( $notice ) . '</p></div>';
		}

		$this->render_summary( $summary );
		$this->render_event_table( $rows, $is_management_mode );
		$this->render_event_detail( $bundle, $is_management_mode );
		echo '</div>';
	}

	private function handle_actions(): string {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return '';
		}

		$action = sanitize_key( $_POST['aims_event_participation_action'] ?? '' );
		if ( '' === $action ) {
			return '';
		}

		check_admin_referer( 'aims_event_participation_action', 'aims_event_participation_nonce' );

		$event_id = absint( $_POST['event_id'] ?? 0 );
		if ( $event_id <= 0 ) {
			return 'Missing event ID.';
		}

		$actor_user_id = get_current_user_id();
		if ( ! $this->can_manage_surface() ) {
			return 'You do not have permission to manage event participation.';
		}
		$result = null;

		switch ( $action ) {
			case 'open_event_for_requests':
				$result = $this->event_automation->open_event_for_requests( $event_id, $actor_user_id, array() );
				return $this->format_status_notice( 'opened', $event_id, $result );
			case 'close_event_requests':
				$result = $this->event_automation->close_event_requests( $event_id, $actor_user_id );
				return $this->format_status_notice( 'closed', $event_id, $result );
			case 'approve_next_vendor_request':
				$result = $this->event_automation->approve_next_vendor_request( $event_id, $actor_user_id );
				return $this->format_approval_notice( $event_id, $result );
			case 'manual_assign_vendor_to_event':
				$vendor_id = absint( $_POST['vendor_id'] ?? 0 );
				if ( $vendor_id <= 0 ) {
					return 'Choose a vendor before assigning manually.';
				}

				$assignment_data = array();
				$commission_rate = isset( $_POST['commission_rate'] ) ? (float) $_POST['commission_rate'] : 0.0;
				if ( $commission_rate > 0 ) {
					$assignment_data['commission_rate'] = $commission_rate;
				}

				$result = $this->event_automation->manual_assign_vendor_to_event( $event_id, $vendor_id, $actor_user_id, $assignment_data );
				return $this->format_manual_assignment_notice( $event_id, $vendor_id, $result );
		}

		return '';
	}

	private function can_view_surface(): bool {
		return current_user_can( AIMS_Capabilities::CAP_PORTAL_EVENTS ) || $this->can_manage_surface();
	}

	private function can_manage_surface(): bool {
		return $this->event_automation->user_can_manage_event_participation( get_current_user_id() );
	}

	private function get_surface_mode_label(): string {
		return $this->can_manage_surface()
			? 'Management view: state changes, approvals, and fallback assignment are enabled.'
			: 'Portal view: read-only participation visibility for request status and queue awareness.';
	}

	private function render_summary( array $summary ): void {
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 24px;">';
		$this->render_summary_card( 'Open for request', (int) ( $summary['open_for_request'] ?? 0 ) );
		$this->render_summary_card( 'Partially assigned', (int) ( $summary['partially_assigned'] ?? 0 ) );
		$this->render_summary_card( 'Request closed', (int) ( $summary['request_closed'] ?? 0 ) );
		$this->render_summary_card( 'Fully assigned', (int) ( $summary['fully_assigned'] ?? 0 ) );
		echo '</div>';
	}

	private function render_summary_card( string $label, int $count ): void {
		echo '<div class="notice notice-info inline" style="margin:0;padding:12px 16px;">';
		echo '<strong>' . esc_html( (string) $count ) . '</strong> ' . esc_html( $label );
		echo '</div>';
	}

	private function render_event_table( array $rows, bool $can_manage ): void {
		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No events are available yet.</p></div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Event</th>';
		echo '<th>Status</th>';
		echo '<th>Requests</th>';
		echo '<th>Authorized</th>';
		echo '<th>Capacity</th>';
		echo '<th>Window</th>';
		echo '<th>' . esc_html( $can_manage ? 'Actions' : 'Access' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$event_id = (int) ( $row['event_id'] ?? 0 );
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['event_name'] ?? 'Event' ) ) . '</strong><br><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'event_id' => $event_id ), admin_url( 'admin.php' ) ) ) . '">View participation</a></td>';
			echo '<td>' . esc_html( (string) ( $row['state_label'] ?? $row['participation_status'] ?? 'draft' ) ) . '<br><small>' . esc_html( (string) ( $row['request_status_label'] ?? 'Waiting' ) ) . '</small></td>';
			echo '<td>' . esc_html( (string) ( $row['request_count'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['authorized_count'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['capacity_label'] ?? 'Unlimited' ) ) . '<br><small>' . esc_html( (string) ( $row['request_status_label'] ?? 'Waiting' ) ) . '</small></td>';
			echo '<td>' . esc_html( (string) ( $row['request_window_label'] ?? 'Draft' ) ) . '</td>';
			echo '<td>' . $this->render_inline_action_buttons( $row, $can_manage ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_event_detail( array $bundle, bool $can_manage ): void {
		if ( empty( $bundle['event'] ) ) {
			return;
		}

		$event = $bundle['event'];
		$model = ! empty( $bundle['model'] ) ? $bundle['model'] : array();
		$requests = ! empty( $bundle['request_queue'] ) ? $bundle['request_queue'] : array();
		$authorized = ! empty( $bundle['authorized_assignments'] ) ? $bundle['authorized_assignments'] : array();
		$vendor_options = ! empty( $bundle['vendor_options'] ) ? $bundle['vendor_options'] : array();
		$actionability = ! empty( $bundle['actionability'] ) ? $bundle['actionability'] : array();
		$event_id = (int) ( $event['id'] ?? 0 );

		echo '<hr><h2>' . esc_html( (string) ( $event['event_name'] ?? 'Selected event' ) ) . '</h2>';
		echo '<p>Status: <strong>' . esc_html( (string) ( $model['state_label'] ?? $model['participation_status'] ?? 'draft' ) ) . '</strong> | ';
		echo 'Requests: <strong>' . esc_html( (string) ( $model['request_count'] ?? 0 ) ) . '</strong> | ';
		echo 'Authorized: <strong>' . esc_html( (string) ( $model['authorized_count'] ?? 0 ) ) . '</strong> | ';
		echo 'Capacity: <strong>' . esc_html( (string) ( $model['capacity_label'] ?? 'Unlimited' ) ) . '</strong></p>';
		echo '<p><strong>Actionability:</strong> ';
		if ( $can_manage ) {
			echo esc_html( (string) ( $actionability['request_status_label'] ?? 'Waiting' ) );
			echo ' | ';
			echo esc_html( ! empty( $actionability['can_open_requests'] ) ? 'Open requests available' : 'Open requests unavailable' );
			echo ' | ';
			echo esc_html( ! empty( $actionability['can_close_requests'] ) ? 'Close requests available' : 'Close requests unavailable' );
			echo ' | ';
			echo esc_html( ! empty( $actionability['can_approve_next'] ) ? 'Approve-next available' : 'Approve-next unavailable' );
			echo ' | ';
			echo esc_html( (string) ( $actionability['manual_assignment_label'] ?? ( ! empty( $actionability['can_manual_assign'] ) ? 'Manual fallback allowed' : 'Manual fallback unavailable' ) ) );
			echo ' | Remaining capacity: ' . esc_html( (string) ( $model['remaining_capacity'] ?? 0 ) );
		} else {
			echo esc_html( (string) ( $actionability['request_status_label'] ?? 'Waiting' ) );
			echo ' | ';
			echo esc_html__( 'Portal view only', 'ai-man-sys' );
			echo ' | Remaining capacity: ' . esc_html( (string) ( $model['remaining_capacity'] ?? 0 ) );
		}
		echo '</p>';

		if ( ! empty( $actionability['next_request_sequence'] ) ) {
			echo '<div class="notice notice-info inline" style="margin:12px 0 16px;padding:12px 16px;">';
			echo '<strong>Next in queue:</strong> ';
			echo esc_html( 'Request #' . (string) $actionability['next_request_sequence'] . ' ' . (string) ( $actionability['next_request_vendor'] ?? 'Vendor' ) );
			echo '</div>';
		}

		if ( $can_manage ) {
			echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">';
			echo '<form method="post" style="display:inline-block;margin:0;">';
			wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
			echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
			echo '<input type="hidden" name="aims_event_participation_action" value="open_event_for_requests">';
			$open_disabled = empty( $actionability['can_open_requests'] );
			echo '<button type="submit" class="button button-primary"' . ( $open_disabled ? ' disabled="disabled"' : '' ) . '>Open for requests</button>';
			echo '</form>';

			echo '<form method="post" style="display:inline-block;margin:0;">';
			wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
			echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
			echo '<input type="hidden" name="aims_event_participation_action" value="close_event_requests">';
			$close_disabled = empty( $actionability['can_close_requests'] );
			echo '<button type="submit" class="button"' . ( $close_disabled ? ' disabled="disabled"' : '' ) . '>Close requests</button>';
			echo '</form>';

			echo '<form method="post" style="display:inline-block;margin:0;">';
			wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
			echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
			echo '<input type="hidden" name="aims_event_participation_action" value="approve_next_vendor_request">';
			$approve_disabled = empty( $actionability['can_approve_next'] ) ? ' disabled="disabled"' : '';
			echo '<button type="submit" class="button"' . $approve_disabled . '>Approve next request</button>';
			echo '</form>';
			echo '</div>';
		} else {
			echo '<div class="notice notice-info inline" style="margin:16px 0 0;"><p>State-changing controls are hidden in portal view.</p></div>';
		}

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-top:24px;">';
		$this->render_request_table( $requests );
		$this->render_assignment_table( $authorized, 'Approved and manual assignments' );
		if ( $can_manage ) {
			$this->render_manual_assign_form( $event_id, $vendor_options, $model, $actionability );
		} else {
			$this->render_portal_view_notice( $model, $actionability );
		}
		echo '</div>';
	}

	private function render_request_table( array $requests ): void {
		echo '<div>';
		echo '<h3>Request Queue</h3>';

		if ( empty( $requests ) ) {
			echo '<p>No vendor requests are queued.</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Sequence</th><th>Vendor</th><th>Status</th><th>Commission</th></tr></thead><tbody>';
		foreach ( $requests as $request ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $request['request_sequence'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $request['vendor_name'] ?? $request['vendor_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $request['assignment_status'] ?? 'requested' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $request['commission_rate'] ?? '0.0000' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_assignment_table( array $assignments, string $title ): void {
		echo '<div>';
		echo '<h3>' . esc_html( $title ) . '</h3>';

		if ( empty( $assignments ) ) {
			echo '<p>No approved or manual assignments yet.</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Vendor</th><th>Status</th><th>Commission</th></tr></thead><tbody>';
		foreach ( $assignments as $assignment ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $assignment['vendor_name'] ?? $assignment['vendor_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $assignment['assignment_status'] ?? 'approved' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $assignment['commission_rate'] ?? '0.0000' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_manual_assign_form( int $event_id, array $vendor_options, array $model = array(), array $actionability = array() ): void {
		echo '<div>';
		echo '<h3>Manual Fallback Assignment</h3>';
		echo '<p>Use manual fallback when a vendor must be assigned outside FCFS or when you need to override the request queue for a live event.</p>';
		echo '<p><strong>Current state:</strong> ' . esc_html( (string) ( $model['state_label'] ?? $model['participation_status'] ?? 'draft' ) ) . ' | ';
		echo 'Remaining capacity: ' . esc_html( (string) ( $model['remaining_capacity'] ?? 0 ) ) . ' | ';
		echo 'Queue size: ' . esc_html( (string) ( $actionability['queue_count'] ?? 0 ) ) . ' | ';
		echo esc_html( (string) ( $actionability['manual_assignment_label'] ?? 'Manual fallback allowed' ) ) . '</p>';

		if ( empty( $actionability['can_manual_assign'] ) ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>Manual fallback assignment is not available to this account.</p></div>';
		}

		if ( empty( $vendor_options ) ) {
			echo '<p>No vendors are available for assignment.</p>';
			echo '</div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		echo '<input type="hidden" name="aims_event_participation_action" value="manual_assign_vendor_to_event">';
		echo '<p><label for="aims-event-vendor">Vendor</label><br>';
		echo '<select id="aims-event-vendor" name="vendor_id" required' . ( empty( $actionability['can_manual_assign'] ) ? ' disabled="disabled"' : '' ) . '>';
		echo '<option value="">Choose vendor</option>';
		foreach ( $vendor_options as $option ) {
			echo '<option value="' . esc_attr( (string) ( $option['id'] ?? 0 ) ) . '">' . esc_html( (string) ( $option['label'] ?? 'Vendor' ) ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="aims-event-commission">Commission rate</label><br>';
		echo '<input id="aims-event-commission" name="commission_rate" type="number" min="0" step="0.0001" value="0"' . ( empty( $actionability['can_manual_assign'] ) ? ' disabled="disabled"' : '' ) . '></p>';
		echo '<p><button type="submit" class="button button-primary"' . ( empty( $actionability['can_manual_assign'] ) ? ' disabled="disabled"' : '' ) . '>Save fallback assignment</button></p>';
		echo '</form>';
		echo '</div>';
	}

	private function render_inline_action_buttons( array $row, bool $can_manage ): string {
		$event_id = (int) ( $row['event_id'] ?? 0 );
		if ( ! $can_manage ) {
			return '<span class="description">Portal view</span>';
		}

		$approve_disabled = empty( $row['can_approve_next'] );
		$open_disabled = empty( $row['can_open_requests'] );
		$close_disabled = empty( $row['can_close_requests'] );
		ob_start();
		$this->render_action_form( $event_id, 'open_event_for_requests', 'Open', $open_disabled );
		$this->render_action_form( $event_id, 'close_event_requests', 'Close', $close_disabled );
		$this->render_action_form( $event_id, 'approve_next_vendor_request', 'Approve next', $approve_disabled );
		return (string) ob_get_clean();
	}

	private function render_portal_view_notice( array $model, array $actionability ): void {
		echo '<div class="notice notice-info inline">';
		echo '<p>Portal view is read-only. ' . esc_html( (string) ( $model['state_label'] ?? $model['participation_status'] ?? 'draft' ) ) . ' | ';
		echo esc_html( (string) ( $actionability['request_status_label'] ?? 'Waiting' ) ) . ' | ';
		echo 'Remaining capacity: ' . esc_html( (string) ( $model['remaining_capacity'] ?? 0 ) ) . '</p>';
		echo '</div>';
	}

	private function render_action_form( int $event_id, string $action, string $label, bool $disabled = false ): void {
		echo '<form method="post" style="display:inline-block;margin:0 6px 0 0;">';
		wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		echo '<input type="hidden" name="aims_event_participation_action" value="' . esc_attr( $action ) . '">';
		echo '<button type="submit" class="button button-small"' . ( $disabled ? ' disabled="disabled"' : '' ) . '>' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private function format_status_notice( string $verb, int $event_id, $result ): string {
		if ( empty( $result ) || ! is_array( $result ) ) {
			return 'Unable to ' . $verb . ' requests for this event.';
		}

		$model = $this->event_automation->get_participation_model_for_event( $event_id );
		return 'Event requests ' . $verb . '. Status: ' . (string) ( $model['state_label'] ?? $model['participation_status'] ?? 'draft' ) . '. Queue: ' . (string) ( $model['request_count'] ?? 0 ) . '. Remaining capacity: ' . (string) ( $model['remaining_capacity'] ?? 0 ) . '.';
	}

	private function format_approval_notice( int $event_id, $result ): string {
		if ( empty( $result ) || ! is_array( $result ) ) {
			return 'No request could be approved.';
		}

		$model = $this->event_automation->get_participation_model_for_event( $event_id );
		$sequence = ! empty( $result['request_sequence'] ) ? (int) $result['request_sequence'] : 0;
		$vendor_label = ! empty( $result['vendor_id'] ) ? 'Vendor #' . (int) $result['vendor_id'] : 'Vendor';

		return 'Approved request #' . $sequence . ' for ' . $vendor_label . '. Status: ' . (string) ( $model['state_label'] ?? $model['participation_status'] ?? 'draft' ) . '. Remaining capacity: ' . (string) ( $model['remaining_capacity'] ?? 0 ) . '.';
	}

	private function format_manual_assignment_notice( int $event_id, int $vendor_id, $result ): string {
		if ( empty( $result ) || ! is_array( $result ) ) {
			return 'Unable to save the manual fallback assignment.';
		}

		$model = $this->event_automation->get_participation_model_for_event( $event_id );

		return 'Manual fallback assignment saved for vendor #' . $vendor_id . '. Status: ' . (string) ( $model['state_label'] ?? $model['participation_status'] ?? 'draft' ) . '. Remaining capacity: ' . (string) ( $model['remaining_capacity'] ?? 0 ) . '.';
	}
}
