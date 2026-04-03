<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Location_Push_Policy_Service {
	/** @var AIMS_Event_Repository */
	private $events;

	/** @var callable|null */
	private $clock;

	public function __construct( AIMS_Event_Repository $events = null, callable $clock = null ) {
		$this->events = $events ?: new AIMS_Event_Repository();
		$this->clock  = $clock;
	}

	public function get_manifest_sync_gate(): array {
		$today         = substr( $this->current_time(), 0, 10 );
		$active_events = array();

		foreach ( $this->events->all() as $event ) {
			if ( ! is_array( $event ) || ! $this->is_live_square_window( $event, $today ) ) {
				continue;
			}

			$active_events[] = array(
				'id'                 => (int) ( $event['id'] ?? 0 ),
				'event_name'         => $this->event_label( $event ),
				'start_date'         => sanitize_text_field( (string) ( $event['start_date'] ?? '' ) ),
				'end_date'           => sanitize_text_field( (string) ( $event['end_date'] ?? '' ) ),
				'square_location_id' => sanitize_text_field( (string) ( $event['square_location_id'] ?? '' ) ),
			);
		}

		$allowed = empty( $active_events );

		return array(
			'allowed'       => $allowed,
			'active_events' => $active_events,
			'message'       => $allowed
				? 'Square location pushes stay manual on purpose. Run them after planning so the dock work is faster and cleaner, not while the show is live.'
				: 'Square location pushes are locked while a live event window is active. Keep working locally in AIMS during the show and push again after the event window closes.',
		);
	}

	private function is_live_square_window( array $event, string $today ): bool {
		$square_location_id = trim( (string) ( $event['square_location_id'] ?? '' ) );
		$start_date         = sanitize_text_field( (string) ( $event['start_date'] ?? '' ) );
		$end_date           = sanitize_text_field( (string) ( $event['end_date'] ?? '' ) );

		if ( '' === $square_location_id || '' === $start_date || '' === $end_date ) {
			return false;
		}

		return $today >= $start_date && $today <= $end_date;
	}

	private function event_label( array $event ): string {
		$event_name = sanitize_text_field( (string) ( $event['event_name'] ?? '' ) );
		if ( '' !== $event_name ) {
			return $event_name;
		}

		$event_code = sanitize_text_field( (string) ( $event['event_code'] ?? '' ) );
		if ( '' !== $event_code ) {
			return $event_code;
		}

		return 'Event #' . (int) ( $event['id'] ?? 0 );
	}

	private function current_time(): string {
		if ( is_callable( $this->clock ) ) {
			return (string) call_user_func( $this->clock );
		}

		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'mysql' );
		}

		return gmdate( 'Y-m-d H:i:s' );
	}
}
