<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Workspace_Page {
	public const PAGE_SLUG = 'aims-event-planning-workspace';

	private $data_provider;

	public function __construct( AIMS_Event_Planning_Workspace_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$model            = $this->data_provider->get_page_model();
		$events           = (array) ( $model['authorized_events'] ?? array() );
		$selected_event   = is_array( $model['selected_event'] ?? null ) ? $model['selected_event'] : array();
		$workspace        = (array) ( $model['workspace'] ?? array() );
		$selection_message = (string) ( $model['selection_message'] ?? '' );
		$selected_event_id = (int) ( $model['selected_event_id'] ?? 0 );

		echo '<div class="wrap aims-event-planning-workspace">';
		echo '<h1>Events &rsaquo; Planning</h1>';
		echo '<p>Manual bucket planning for managers and supervisors. Demand is the signal, bucket assignment is the commitment, and planning does not move inventory. Planning assignments show as <strong>In Transit</strong>, but physical movement does not happen until <code>vendor_event_checkin</code>.</p>';
		$this->render_status_notice();

		$this->render_event_selector( $events, $selected_event_id );

		if ( '' !== $selection_message ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( $selection_message ) . '</p></div>';
		}

		if ( empty( $selected_event ) ) {
			echo '</div>';
			return;
		}

		$this->render_event_header( $selected_event );
		$this->render_demand_panel( (array) ( $workspace['demand_rows'] ?? array() ) );
		$this->render_assigned_buckets_panel( $selected_event_id, (array) ( $workspace['assigned_buckets'] ?? array() ) );
		$this->render_available_buckets_panel( $selected_event_id, (array) ( $workspace['available_buckets'] ?? array() ) );

		echo '</div>';
	}

	private function render_event_selector( array $events, int $selected_event_id ): void {
		if ( empty( $events ) ) {
			echo '<div class="notice notice-warning inline"><p>No authorized events are available for planning.</p></div>';
			return;
		}

		echo '<p><strong>Authorized Events:</strong> ';

		$links = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_id = (int) ( $event['id'] ?? 0 );
			if ( $event_id <= 0 ) {
				continue;
			}

			$label = $this->build_event_label( $event );
			if ( $event_id === $selected_event_id ) {
				$links[] = '<strong>' . esc_html( $label ) . '</strong>';
				continue;
			}

			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'event_id' => $event_id ), admin_url( 'admin.php' ) ) ),
				esc_html( $label )
			);
		}

		echo implode( ' | ', $links );
		echo '</p>';
	}

	private function render_event_header( array $event ): void {
		$event_name = (string) ( $event['event_name'] ?? '' );
		$event_id   = (int) ( $event['id'] ?? 0 );
		$venue_name  = (string) ( $event['location_name'] ?? '' );
		$status     = (string) ( $event['status'] ?? '' );
		$date_range = (string) ( $event['date_range_label'] ?? '' );
		$square_location_id = (string) ( $event['square_location_id'] ?? '' );

		echo '<div class="notice notice-info inline"><p>';
		echo '<strong>Selected Event:</strong> ' . esc_html( $event_name ) . ' #' . esc_html( (string) $event_id );
		if ( '' !== $date_range ) {
			echo ' | ' . esc_html( $date_range );
		}
		if ( '' !== $venue_name ) {
			echo ' | ' . esc_html( $venue_name );
		}
		if ( '' !== $square_location_id ) {
			echo ' | Square Location: ' . esc_html( $square_location_id );
		}
		if ( '' !== $status ) {
			echo ' | Status: ' . esc_html( $status );
		}
		echo '</p></div>';
	}

	private function render_demand_panel( array $rows ): void {
		echo '<h2>Demand by SKU</h2>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No demand summary rows are currently available for this event.</p></div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>SKU</th>';
		echo '<th>Product</th>';
		echo '<th>Demand</th>';
		echo '<th>Fulfilled</th>';
		echo '<th>Open</th>';
		echo '<th>Sources</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) ( $row['product_sku'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $row['product_name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['quantity_requested'] ?? $row['demand_quantity'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['approved_quantity'] ?? $row['fulfilled_quantity'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['open_quantity'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) ( $row['sources'] ?? array() ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_assigned_buckets_panel( int $event_id, array $rows ): void {
		echo '<h2>Assigned Buckets</h2>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No buckets are currently assigned to this event.</p></div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Bucket</th>';
		echo '<th>Planning State</th>';
		echo '<th>Execution State</th>';
		echo '<th>Contents</th>';
		echo '<th>Assigned</th>';
		echo '<th>Action</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$assignment_id = (int) ( $row['assignment_id'] ?? 0 );
			$assignment_status = sanitize_key( (string) ( $row['assignment_status'] ?? '' ) );
			echo '<tr>';
			echo '<td>' . esc_html( $this->build_bucket_label( $row ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['assignment_label'] ?? $row['assignment_status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->build_execution_state_label( $assignment_status ) ) . '</td>';
			echo '<td>' . esc_html( $this->build_content_summary_label( (array) ( $row['content_summary'] ?? array() ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['assigned_at'] ?? '' ) ) . '</td>';
			echo '<td>' . $this->render_assigned_bucket_actions( $event_id, $row ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_available_buckets_panel( int $event_id, array $rows ): void {
		echo '<h2>Available Buckets</h2>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No additional buckets are currently available for manual assignment.</p></div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Bucket</th>';
		echo '<th>Status</th>';
		echo '<th>Contents</th>';
		echo '<th>Storage</th>';
		echo '<th>Action</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$bucket_id = (int) ( $row['physical_bucket_id'] ?? 0 );
			echo '<tr>';
			echo '<td>' . esc_html( $this->build_bucket_label( $row ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->build_content_summary_label( (array) ( $row['content_summary'] ?? array() ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->build_storage_label( (array) ( $row['storage'] ?? array() ) ) ) . '</td>';
			echo '<td>' . $this->render_assign_form( $event_id, $bucket_id ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_assign_form( int $event_id, int $bucket_id ): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aims_event_planning_assign_bucket">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<input type="hidden" name="physical_bucket_id" value="<?php echo esc_attr( (string) $bucket_id ); ?>">
			<input type="hidden" name="return_url" value="<?php echo esc_attr( $this->build_return_url( $event_id ) ); ?>">
			<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'aims_event_planning_assign_bucket', '_aims_event_planning_assign_nonce' ); } ?>
			<button type="submit" class="button button-primary">Assign</button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	private function render_release_form( int $event_id, int $assignment_id ): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aims_event_planning_release_bucket">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $assignment_id ); ?>">
			<input type="hidden" name="return_url" value="<?php echo esc_attr( $this->build_return_url( $event_id ) ); ?>">
			<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'aims_event_planning_release_bucket', '_aims_event_planning_release_nonce' ); } ?>
			<button type="submit" class="button">Release Planning</button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	private function render_assigned_bucket_actions( int $event_id, array $row ): string {
		$actions = array();
		$assignment_id = (int) ( $row['assignment_id'] ?? 0 );
		$bucket_id = (int) ( $row['physical_bucket_id'] ?? 0 );
		$status = sanitize_key( (string) ( $row['assignment_status'] ?? '' ) );

		if ( in_array( $status, array( 'assigned', 'staged', 'in_transit' ), true ) ) {
			$actions[] = $this->render_execution_action_form(
				'aims_event_planning_vendor_event_check_in',
				'Check In',
				$event_id,
				$assignment_id,
				$bucket_id,
				'aims_event_planning_vendor_event_check_in',
				'_aims_event_planning_vendor_event_check_in_nonce'
			);
			$actions[] = $this->render_release_form( $event_id, $assignment_id );
		} elseif ( 'at_event' === $status ) {
			$actions[] = $this->render_execution_action_form(
				'aims_event_planning_mark_returned',
				'Mark Returned',
				$event_id,
				$assignment_id,
				$bucket_id,
				'aims_event_planning_mark_returned',
				'_aims_event_planning_mark_returned_nonce'
			);
		} elseif ( 'returned' === $status ) {
			$actions[] = $this->render_execution_action_form(
				'aims_event_planning_release_after_return',
				'Release',
				$event_id,
				$assignment_id,
				$bucket_id,
				'aims_event_planning_release_after_return',
				'_aims_event_planning_release_after_return_nonce'
			);
		} elseif ( ! in_array( $status, array( 'released', 'cancelled' ), true ) ) {
			$actions[] = $this->render_release_form( $event_id, $assignment_id );
		}

		return implode( '', array_map( static function ( string $html ): string {
			return '<div style="margin-bottom:6px;">' . $html . '</div>';
		}, array_filter( $actions ) ) );
	}

	private function render_execution_action_form( string $action, string $label, int $event_id, int $assignment_id, int $bucket_id, string $nonce_action, string $nonce_name ): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $assignment_id ); ?>">
			<input type="hidden" name="physical_bucket_id" value="<?php echo esc_attr( (string) $bucket_id ); ?>">
			<input type="hidden" name="return_url" value="<?php echo esc_attr( $this->build_return_url( $event_id ) ); ?>">
			<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( $nonce_action, $nonce_name ); } ?>
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	private function render_status_notice(): void {
		$status  = isset( $_GET['aims_event_planning_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_event_planning_status'] ) ) : '';
		$message = isset( $_GET['aims_event_planning_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_event_planning_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$notice_class = 'success' === $status ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function build_return_url( int $event_id ): string {
		return add_query_arg(
			array(
				'page'     => self::PAGE_SLUG,
				'event_id' => $event_id,
			),
			admin_url( 'admin.php' )
		);
	}

	private function build_event_label( array $event ): string {
		$label = sanitize_text_field( (string) ( $event['event_name'] ?? '' ) );
		$date_range = sanitize_text_field( (string) ( $event['date_range_label'] ?? '' ) );

		if ( '' !== $date_range ) {
			$label .= ' - ' . $date_range;
		}

		return $label;
	}

	private function build_bucket_label( array $row ): string {
		$bucket_label = sanitize_text_field( (string) ( $row['bucket_label'] ?? '' ) );
		$bucket_code  = sanitize_text_field( (string) ( $row['bucket_code'] ?? '' ) );

		if ( '' !== $bucket_label && '' !== $bucket_code ) {
			return $bucket_label . ' (' . $bucket_code . ')';
		}

		return '' !== $bucket_label ? $bucket_label : $bucket_code;
	}

	private function build_content_summary_label( array $summary ): string {
		$line_count = (int) ( $summary['line_count'] ?? 0 );
		$available  = (float) ( $summary['total_available_quantity'] ?? 0 );
		$total      = (float) ( $summary['total_quantity'] ?? 0 );

		return sprintf( '%d lines, %s available of %s total', $line_count, $this->format_quantity( $available ), $this->format_quantity( $total ) );
	}

	private function build_storage_label( array $storage ): string {
		$current = (array) ( $storage['current'] ?? array() );
		$home    = (array) ( $storage['home'] ?? array() );

		$parts = array();
		if ( '' !== (string) ( $current['label'] ?? '' ) ) {
			$parts[] = 'Current: ' . (string) $current['label'];
		}
		if ( '' !== (string) ( $home['label'] ?? '' ) ) {
			$parts[] = 'Home: ' . (string) $home['label'];
		}

		return empty( $parts ) ? 'Not assigned' : implode( ' | ', $parts );
	}

	private function build_execution_state_label( string $status ): string {
		switch ( $status ) {
			case 'assigned':
				return 'Awaiting check-in';
			case 'staged':
				return 'Ready for check-in';
			case 'in_transit':
				return 'In transit';
			case 'at_event':
				return 'Checked in';
			case 'returned':
				return 'Returned';
			case 'released':
				return 'Released';
			case 'cancelled':
				return 'Cancelled';
			default:
				return '' !== $status ? ucfirst( str_replace( '_', ' ', $status ) ) : 'Not started';
		}
	}

	private function format_quantity( float $quantity ): string {
		return rtrim( rtrim( number_format( $quantity, 4, '.', '' ), '0' ), '.' );
	}
}
