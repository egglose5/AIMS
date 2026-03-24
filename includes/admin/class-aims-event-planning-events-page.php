<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Events_Page {
	private $data_provider;

	public function __construct( AIMS_Event_Planning_Events_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows = $this->data_provider->get_rows();

		echo '<div class="wrap">';
		echo '<h1>Events &rsaquo; Event Planning</h1>';
		echo '<p>Planner access is scoped to authorized events only. Select an event to review demand, bucket contents, and manual bucket assignments in the workspace.</p>';

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
			echo '<td><a class="button button-primary" href="' . esc_url( (string) ( $row['workspace_url'] ?? '#' ) ) . '">Open planning workspace</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
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
