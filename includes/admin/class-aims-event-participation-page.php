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
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage event participation.', 'ai-man-sys' ) );
		}

		$notice = $this->handle_actions();
		$rows   = $this->data_provider->get_rows();
		$summary = $this->data_provider->get_summary();
		$selected_event_id = ! empty( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : (int) ( $rows[0]['event_id'] ?? 0 );
		$bundle = $selected_event_id > 0 ? $this->data_provider->get_event_bundle( $selected_event_id ) : array();

		echo '<div class="wrap">';
		echo '<h1>Event Participation</h1>';
		echo '<p>Use this screen to open request windows, review FCFS request queues, approve vendors, and apply manual fallback assignments.</p>';

		if ( ! empty( $notice ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( $notice ) . '</p></div>';
		}

		$this->render_summary( $summary );
		$this->render_event_table( $rows );
		$this->render_event_detail( $bundle );
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

		switch ( $action ) {
			case 'open_event_for_requests':
				$result = $this->event_automation->open_event_for_requests( $event_id, array(), $actor_user_id );
				return ! empty( $result ) ? 'Event requests opened.' : 'Unable to open requests for this event.';
			case 'close_event_requests':
				$result = $this->event_automation->close_event_requests( $event_id, $actor_user_id );
				return ! empty( $result ) ? 'Event requests closed.' : 'Unable to close requests for this event.';
			case 'approve_next_vendor_request':
				$result = $this->event_automation->approve_next_vendor_request( $event_id, $actor_user_id );
				return ! empty( $result ) ? 'Approved the next vendor request.' : 'No request could be approved.';
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

				$result = $this->event_automation->manual_assign_vendor_to_event( $event_id, $vendor_id, $assignment_data, $actor_user_id );
				return ! empty( $result ) ? 'Manual fallback assignment saved.' : 'Unable to save the manual fallback assignment.';
		}

		return '';
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

	private function render_event_table( array $rows ): void {
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
		echo '<th>Actions</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$event_id = (int) ( $row['event_id'] ?? 0 );
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['event_name'] ?? 'Event' ) ) . '</strong><br><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'event_id' => $event_id ), admin_url( 'admin.php' ) ) ) . '">View participation</a></td>';
			echo '<td>' . esc_html( (string) ( $row['participation_status'] ?? 'draft' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['request_count'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['authorized_count'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['capacity_label'] ?? 'Unlimited' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['request_window_label'] ?? 'Draft' ) ) . '</td>';
			echo '<td>' . $this->render_inline_action_buttons( $event_id ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_event_detail( array $bundle ): void {
		if ( empty( $bundle['event'] ) ) {
			return;
		}

		$event = $bundle['event'];
		$model = ! empty( $bundle['model'] ) ? $bundle['model'] : array();
		$requests = ! empty( $bundle['request_queue'] ) ? $bundle['request_queue'] : array();
		$authorized = ! empty( $bundle['authorized_assignments'] ) ? $bundle['authorized_assignments'] : array();
		$vendor_options = ! empty( $bundle['vendor_options'] ) ? $bundle['vendor_options'] : array();
		$event_id = (int) ( $event['id'] ?? 0 );

		echo '<hr><h2>' . esc_html( (string) ( $event['event_name'] ?? 'Selected event' ) ) . '</h2>';
		echo '<p>Status: <strong>' . esc_html( (string) ( $model['participation_status'] ?? 'draft' ) ) . '</strong> | ';
		echo 'Requests: <strong>' . esc_html( (string) ( $model['request_count'] ?? 0 ) ) . '</strong> | ';
		echo 'Authorized: <strong>' . esc_html( (string) ( $model['authorized_count'] ?? 0 ) ) . '</strong> | ';
		echo 'Capacity: <strong>' . esc_html( (string) ( $model['capacity_label'] ?? 'Unlimited' ) ) . '</strong></p>';

		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">';
		echo '<form method="post" style="display:inline-block;margin:0;">';
		wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		echo '<input type="hidden" name="aims_event_participation_action" value="open_event_for_requests">';
		echo '<button type="submit" class="button button-primary">Open for requests</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin:0;">';
		wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		echo '<input type="hidden" name="aims_event_participation_action" value="close_event_requests">';
		echo '<button type="submit" class="button">Close requests</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin:0;">';
		wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		echo '<input type="hidden" name="aims_event_participation_action" value="approve_next_vendor_request">';
		echo '<button type="submit" class="button">Approve next request</button>';
		echo '</form>';
		echo '</div>';

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-top:24px;">';
		$this->render_request_table( $requests );
		$this->render_assignment_table( $authorized, 'Approved and manual assignments' );
		$this->render_manual_assign_form( $event_id, $vendor_options );
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

	private function render_manual_assign_form( int $event_id, array $vendor_options ): void {
		echo '<div>';
		echo '<h3>Manual Fallback Assignment</h3>';

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
		echo '<select id="aims-event-vendor" name="vendor_id" required>';
		echo '<option value="">Choose vendor</option>';
		foreach ( $vendor_options as $option ) {
			echo '<option value="' . esc_attr( (string) ( $option['id'] ?? 0 ) ) . '">' . esc_html( (string) ( $option['label'] ?? 'Vendor' ) ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="aims-event-commission">Commission rate</label><br>';
		echo '<input id="aims-event-commission" name="commission_rate" type="number" min="0" step="0.0001" value="0"></p>';
		echo '<p><button type="submit" class="button button-primary">Save fallback assignment</button></p>';
		echo '</form>';
		echo '</div>';
	}

	private function render_inline_action_buttons( int $event_id ): string {
		ob_start();
		$this->render_action_form( $event_id, 'open_event_for_requests', 'Open' );
		$this->render_action_form( $event_id, 'close_event_requests', 'Close' );
		$this->render_action_form( $event_id, 'approve_next_vendor_request', 'Approve next' );
		return (string) ob_get_clean();
	}

	private function render_action_form( int $event_id, string $action, string $label ): void {
		echo '<form method="post" style="display:inline-block;margin:0 6px 0 0;">';
		wp_nonce_field( 'aims_event_participation_action', 'aims_event_participation_nonce' );
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		echo '<input type="hidden" name="aims_event_participation_action" value="' . esc_attr( $action ) . '">';
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}
}
