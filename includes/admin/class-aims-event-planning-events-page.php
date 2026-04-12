<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Events_Page {
	private $data_provider;
	private $events;

	public function __construct( AIMS_Event_Planning_Events_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
		$this->events        = new AIMS_Event_Repository();
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();
		$edit_event_id = isset( $_GET['event_id'] ) ? max( 0, (int) wp_unslash( $_GET['event_id'] ) ) : 0;
		$is_authorized_edit = $edit_event_id > 0 ? $this->data_provider->is_event_authorized( $edit_event_id ) : true;
		$editing_event = ( $edit_event_id > 0 && $is_authorized_edit ) ? $this->events->find( $edit_event_id ) : null;

		echo '<div class="wrap">';
		echo '<h1>Events &rsaquo; Event Planning</h1>';
		echo '<p>Planner access is scoped to authorized events only. Select an event to review demand, bucket contents, and manual bucket assignments in the workspace.</p>';
		$this->render_status_notice();

		if ( $edit_event_id > 0 && ! $is_authorized_edit ) {
			echo '<div class="notice notice-error"><p>You are not authorized to edit that event.</p></div>';
		}

		$this->render_event_form( $editing_event );

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( $this->data_provider->get_empty_message() ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Event</th>';
		echo '<th>Code</th>';
		echo '<th>Date Range</th>';
		echo '<th>Location</th>';
		echo '<th>Status</th>';
		echo '<th>Workspace</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['event_name'] ?? '' ) ) . '</strong></td>';
			echo '<td><code>' . esc_html( (string) ( $row['event_code'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( $this->format_date_range( (string) ( $row['start_date'] ?? '' ), (string) ( $row['end_date'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( trim( (string) ( $row['location_name'] ?? '' ) . ' ' . (string) ( $row['square_location_id'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) ( $row['status'] ?? 'draft' ) ) ) . '</td>';
			echo '<td>' . $this->render_actions_cell( $row ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_status_notice(): void {
		$status  = isset( $_GET['aims_event_manage_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_event_manage_status'] ) ) : '';
		$message = isset( $_GET['aims_event_manage_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_event_manage_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_event_form( ?array $event ): void {
		$is_edit = is_array( $event );

		echo '<h2>' . esc_html( $is_edit ? 'Edit Event' : 'Create Event' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aims_event_save' );
		echo '<input type="hidden" name="action" value="aims_event_save" />';
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) ( $event['id'] ?? 0 ) ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_input( 'event_name', 'Event Name', (string) ( $event['event_name'] ?? '' ), true );
		$this->render_text_input( 'event_code', 'Event Code', (string) ( $event['event_code'] ?? '' ), false );
		$this->render_text_input( 'start_date', 'Start Date (YYYY-MM-DD)', (string) ( $event['start_date'] ?? '' ), true );
		$this->render_text_input( 'end_date', 'End Date (YYYY-MM-DD)', (string) ( $event['end_date'] ?? '' ), true );
		$this->render_text_input( 'location_name', 'Location Name', (string) ( $event['location_name'] ?? '' ), false );
		   // Square Location ID select (from vendor meta)
		   $vendor_service = class_exists('AIMS_Vendor_User_Metadata_Service') ? new AIMS_Vendor_User_Metadata_Service() : null;
		   $vendor_ids = $vendor_service ? $vendor_service->get_all_vendors('active') : array();
		   $location_options = array();
		   if ($vendor_service) {
			   foreach ($vendor_ids as $vid) {
				   $loc_id = $vendor_service->get_square_location_id($vid);
				   if ($loc_id !== '') {
					   $vendor_name = $vendor_service->get_vendor_name($vid);
					   $location_options[$loc_id] = $vendor_name . ' (' . $loc_id . ')';
				   }
			   }
		   }
		   $selected_loc = (string) ( $event['square_location_id'] ?? '' );
		   echo '<tr><th scope="row"><label for="square_location_id">Square Location ID</label></th><td><select id="square_location_id" name="square_location_id" class="regular-text">';
		   echo '<option value="">Select a vendor location...</option>';
		   foreach ($location_options as $loc_id => $label) {
			   $selected = ($loc_id === $selected_loc) ? ' selected' : '';
			   echo '<option value="' . esc_attr($loc_id) . '"' . $selected . '>' . esc_html($label) . '</option>';
		   }
		   echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="status">Status</label></th><td><select id="status" name="status">';
		foreach ( array( 'draft', 'active', 'completed', 'archived' ) as $status ) {
			$selected = $status === (string) ( $event['status'] ?? 'draft' ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $status ) . '"' . $selected . '>' . esc_html( ucfirst( $status ) ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $is_edit ? 'Update Event' : 'Create Event' ) . '</button>';
		if ( $is_edit ) {
			$cancel_url = add_query_arg( array( 'page' => AIMS_Event_Planning_Events_Data_Provider::LANDING_PAGE_SLUG ), admin_url( 'admin.php' ) );
			echo ' <a class="button" href="' . esc_url( $cancel_url ) . '">Cancel Edit</a>';
		}
		echo '</p>';
		echo '</form><hr />';
	}

	private function render_text_input( string $name, string $label, string $value, bool $required ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input class="regular-text" type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ' /></td></tr>';
	}

	private function render_actions_cell( array $row ): string {
		$event_id = (int) ( $row['id'] ?? 0 );
		$workspace_url = (string) ( $row['workspace_url'] ?? '#' );
		$edit_url = add_query_arg(
			array(
				'page'     => AIMS_Event_Planning_Events_Data_Provider::LANDING_PAGE_SLUG,
				'event_id' => $event_id,
			),
			admin_url( 'admin.php' )
		);

		ob_start();
		echo '<a class="button button-primary" href="' . esc_url( $workspace_url ) . '">Open planning workspace</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( $edit_url ) . '">Edit</a>';

		if ( 'archived' !== (string) ( $row['status'] ?? '' ) ) {
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;" onsubmit="return confirm(\'Archive this event?\');">';
			wp_nonce_field( 'aims_event_archive' );
			echo '<input type="hidden" name="action" value="aims_event_archive" />';
			echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '" />';
			echo '<button type="submit" class="button">Archive</button></form>';
		}

		return (string) ob_get_clean();
	}

	private function format_date_range( string $start_date, string $end_date ): string {
		$start = strtotime( $start_date );
		$end   = strtotime( $end_date );

		if ( ! $start && ! $end ) {
			return '';
		}

		if ( $start && $end && gmdate( 'Y-m-d', $start ) === gmdate( 'Y-m-d', $end ) ) {
			return gmdate( 'F j, Y', $start );
		}

		if ( $start && $end ) {
			return gmdate( 'F j, Y', $start ) . ' - ' . gmdate( 'F j, Y', $end );
		}

		return $start ? gmdate( 'F j, Y', $start ) : gmdate( 'F j, Y', $end );
	}
}
