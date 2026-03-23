<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Assignment_Service {
	private $events;
	private $event_locations;
	private $assignments;
	private $runtime_assignments;

	public function __construct(
		?AIMS_Event_Repository $events = null,
		?AIMS_Event_Square_Location_Repository $event_locations = null,
		?AIMS_Vendor_Event_Assignment_Repository $assignments = null,
		?AIMS_Runtime_Assignment_Repository $runtime_assignments = null
	) {
		$this->events              = $events;
		$this->event_locations     = $event_locations;
		$this->assignments         = $assignments;
		$this->runtime_assignments  = $runtime_assignments;
	}

	public function resolve_event_context( string $square_location_id, string $sold_at, ?array $event = null ): array {
		$square_location_id = sanitize_text_field( $square_location_id );
		$sold_at            = sanitize_text_field( $sold_at );
		$matched_event      = is_array( $event ) ? $event : null;
		$match_source       = null;

		if ( null === $matched_event ) {
			$matched_event = $this->find_matching_event_by_location_and_date( $square_location_id, $sold_at );
			$match_source  = ! empty( $matched_event ) ? 'repository' : null;
		}

		if ( empty( $matched_event ) ) {
			return array(
				'event_found'        => false,
				'match_source'       => null,
				'square_location_id' => $square_location_id,
				'sold_at'            => $sold_at,
				'sold_date'          => $this->normalize_date( $sold_at ),
				'event'              => null,
				'reasons'            => array( 'No matching event was found for the Square location and sale date.' ),
			);
		}

		$normalized_event = $this->normalize_event_record( $matched_event );

		return array(
			'event_found'        => true,
			'match_source'       => null === $event ? ( $match_source ?: 'provided' ) : 'provided',
			'square_location_id' => $square_location_id,
			'sold_at'            => $sold_at,
			'sold_date'          => $this->normalize_date( $sold_at ),
			'event'              => $normalized_event,
			'reasons'            => array( 'Square location and sold_at resolved to a matching event window.' ),
		);
	}

	public function resolve_assignment_window( array $event_context, array $assignments = array(), string $sold_at = '' ): array {
		$sold_at = sanitize_text_field( $sold_at ?: (string) ( $event_context['sold_at'] ?? '' ) );

		if ( empty( $event_context['event_found'] ) || empty( $event_context['event']['id'] ) ) {
			return array(
				'window_status'              => 'no_event',
				'event_id'                   => 0,
				'square_location_id'         => (string) ( $event_context['square_location_id'] ?? '' ),
				'sold_at'                    => $sold_at,
				'selected_assignment'        => null,
				'selected_vendor_id'         => 0,
				'approved_assignments'       => array(),
				'manual_fallback'            => null,
				'requires_manual_assignment' => true,
				'reasons'                    => array( 'No event context available for runtime assignment resolution.' ),
			);
		}

		$event = $event_context['event'];

		if ( empty( $assignments ) ) {
			$assignments = $this->load_assignments_for_event( (int) $event['id'], $event_context );
		}

		$normalized_assignments = array();

		foreach ( $assignments as $assignment ) {
			$normalized_assignments[] = $this->normalize_assignment_record( $assignment, $sold_at );
		}

		$approved_assignments = array_values(
			array_filter(
				$normalized_assignments,
				static function ( array $assignment ): bool {
					return in_array( $assignment['assignment_state'], array( 'approved', 'assigned', 'active' ), true ) && $assignment['within_window'];
				}
			)
		);

		usort( $approved_assignments, array( $this, 'compare_assignment_priority' ) );

		$selected_assignment = ! empty( $approved_assignments ) ? $approved_assignments[0] : null;
		$manual_fallback     = $this->find_manual_fallback_assignment( $normalized_assignments );
		$selection_mode      = 'none';
		$window_status       = 'unassigned';
		$selected_vendor_id  = 0;
		$reasons             = array();

		if ( ! empty( $selected_assignment ) ) {
			$selection_mode     = 'fcfs';
			$window_status      = 'resolved';
			$selected_vendor_id = (int) $selected_assignment['vendor_id'];
			$reasons[]          = 'Approved assignment selected using FCFS ordering.';
		} elseif ( ! empty( $manual_fallback ) ) {
			$selection_mode     = 'manual';
			$window_status      = 'manual_fallback';
			$selected_vendor_id = (int) $manual_fallback['vendor_id'];
			$selected_assignment = $manual_fallback;
			$reasons[]          = 'Manual assignment fallback was selected because no approved assignment was active.';
		} else {
			$reasons[] = 'Event resolved, but no approved or manual fallback assignment was available.';
		}

		return array(
			'window_status'              => $window_status,
			'event_id'                   => (int) $event['id'],
			'square_location_id'         => (string) $event['square_location_id'],
			'sold_at'                    => $sold_at,
			'sold_date'                  => (string) ( $event_context['sold_date'] ?? '' ),
			'event'                      => $event,
			'approved_assignments'       => $approved_assignments,
			'manual_fallback'            => $manual_fallback,
			'selected_assignment'        => $selected_assignment,
			'selected_vendor_id'         => $selected_vendor_id,
			'selection_mode'             => $selection_mode,
			'requires_manual_assignment' => empty( $selected_assignment ) && empty( $manual_fallback ),
			'reasons'                    => $reasons,
		);
	}

	public function resolve_vendor_assignment_status( array $assignment, string $sold_at = '' ): array {
		$normalized = $this->normalize_assignment_record( $assignment, $sold_at );
		$sold_date  = $this->normalize_date( $sold_at );

		return array(
			'assignment_id'    => (int) $normalized['assignment_id'],
			'event_id'         => (int) $normalized['event_id'],
			'vendor_id'        => (int) $normalized['vendor_id'],
			'assignment_state' => $normalized['assignment_state'],
			'within_window'    => $normalized['within_window'],
			'window_status'    => $normalized['within_window'] ? 'active' : 'inactive',
			'sold_date'        => $sold_date,
			'manual_fallback'  => $normalized['manual_fallback'],
			'reasons'          => $normalized['reasons'],
			'raw_assignment'   => $normalized['raw_assignment'],
		);
	}

	public function resolve_sale_assignment( array $sale, array $assignments = array() ): array {
		$square_location_id = (string) ( $sale['square_location_id'] ?? '' );
		$sold_at            = (string) ( $sale['sold_at'] ?? '' );
		$event_context      = $this->resolve_event_context( $square_location_id, $sold_at );
		$window_context     = $this->resolve_assignment_window( $event_context, $assignments, $sold_at );

		return array(
			'sale_id'                    => (int) ( $sale['id'] ?? 0 ),
			'square_location_id'         => $square_location_id,
			'sold_at'                    => $sold_at,
			'event_context'              => $event_context,
			'assignment_window'          => $window_context,
			'event_id'                   => (int) ( $window_context['event_id'] ?? 0 ),
			'vendor_id'                  => (int) ( $window_context['selected_vendor_id'] ?? 0 ),
			'selection_mode'             => (string) ( $window_context['selection_mode'] ?? 'none' ),
			'decision_status'            => empty( $window_context['selected_vendor_id'] ) ? 'unassigned' : 'assigned',
			'requires_manual_assignment' => ! empty( $window_context['requires_manual_assignment'] ),
			'reasons'                    => array_merge(
				(array) ( $event_context['reasons'] ?? array() ),
				(array) ( $window_context['reasons'] ?? array() )
			),
		);
	}

	private function find_matching_event_by_location_and_date( string $square_location_id, string $sold_at ): ?array {
		if ( null !== $this->event_locations && method_exists( $this->event_locations, 'find_matching_event' ) ) {
			$event = $this->event_locations->find_matching_event( $square_location_id, $sold_at );

			if ( ! empty( $event ) ) {
				return $event;
			}
		}

		if ( null !== $this->events && method_exists( $this->events, 'find_matching_event' ) ) {
			$event = $this->events->find_matching_event( $square_location_id, $sold_at );

			if ( ! empty( $event ) ) {
				return $event;
			}
		}

		return null;
	}

	private function load_assignments_for_event( int $event_id, array $event_context ): array {
		if ( null !== $this->runtime_assignments ) {
			if ( method_exists( $this->runtime_assignments, 'get_for_event' ) ) {
				$assignments = $this->runtime_assignments->get_for_event( $event_id );

				if ( is_array( $assignments ) && ! empty( $assignments ) ) {
					return $assignments;
				}
			}

			if (
				method_exists( $this->runtime_assignments, 'get_active_for_location_and_date' ) &&
				! empty( $event_context['square_location_id'] ) &&
				! empty( $event_context['sold_at'] )
			) {
				$assignments = $this->runtime_assignments->get_active_for_location_and_date(
					(string) $event_context['square_location_id'],
					(string) $event_context['sold_at']
				);

				if ( is_array( $assignments ) && ! empty( $assignments ) ) {
					return $assignments;
				}
			}
		}

		if ( null !== $this->assignments && method_exists( $this->assignments, 'get_for_event' ) ) {
			return $this->assignments->get_for_event( $event_id );
		}

		return array();
	}

	private function normalize_event_record( array $event ): array {
		return array(
			'id'                 => (int) ( $event['id'] ?? 0 ),
			'event_code'         => sanitize_text_field( $event['event_code'] ?? '' ),
			'event_name'         => sanitize_text_field( $event['event_name'] ?? '' ),
			'start_date'         => sanitize_text_field( $event['start_date'] ?? '' ),
			'end_date'           => sanitize_text_field( $event['end_date'] ?? '' ),
			'square_location_id' => sanitize_text_field( $event['square_location_id'] ?? $event['location_id'] ?? '' ),
			'status'             => sanitize_key( $event['status'] ?? '' ),
		);
	}

	private function normalize_assignment_record( array $assignment, string $sold_at = '' ): array {
		$assignment_state = sanitize_key( $assignment['assignment_status'] ?? $assignment['status'] ?? 'assigned' );
		$sold_date        = $this->normalize_date( $sold_at );
		$starts_at        = $this->normalize_date( $assignment['starts_at'] ?? $assignment['approved_at'] ?? $assignment['active_from'] ?? '' );
		$ends_at          = $this->normalize_date( $assignment['ends_at'] ?? $assignment['active_to'] ?? '' );
		$manual_fallback  = ! empty( $assignment['manual_fallback'] ) || 'manual' === $assignment_state || 'manual' === sanitize_key( $assignment['assignment_mode'] ?? '' );
		$within_window    = $this->is_assignment_within_window( $sold_date, $starts_at, $ends_at );

		return array(
			'assignment_id'        => (int) ( $assignment['id'] ?? $assignment['assignment_id'] ?? 0 ),
			'event_id'             => (int) ( $assignment['event_id'] ?? 0 ),
			'vendor_id'            => (int) ( $assignment['vendor_id'] ?? 0 ),
			'square_team_member_id' => (string) ( $assignment['square_team_member_id'] ?? '' ),
			'square_location_id'   => (string) ( $assignment['square_location_id'] ?? '' ),
			'request_id'           => (int) ( $assignment['request_id'] ?? 0 ),
			'source_assignment_id' => (int) ( $assignment['source_assignment_id'] ?? 0 ),
			'assignment_state'     => $this->normalize_assignment_state( $assignment_state ),
			'assignment_mode'      => sanitize_key( $assignment['assignment_mode'] ?? '' ),
			'created_at'           => sanitize_text_field( $assignment['created_at'] ?? '' ),
			'starts_at'            => $starts_at,
			'ends_at'              => $ends_at,
			'priority'             => (int) ( $assignment['priority'] ?? 0 ),
			'commission_rate'      => $this->normalize_rate( $assignment['commission_rate'] ?? 0 ),
			'active_for_import'    => ! empty( $assignment['active_for_import'] ),
			'within_window'        => $within_window,
			'manual_fallback'      => $manual_fallback,
			'reasons'              => array(
				$within_window ? 'Assignment is inside the runtime window.' : 'Assignment is outside the runtime window.',
			),
			'raw_assignment'       => $assignment,
		);
	}

	private function normalize_assignment_state( string $state ): string {
		$state = sanitize_key( $state );

		if ( in_array( $state, array( 'approved', 'assigned', 'active', 'manual', 'pending', 'rejected', 'cancelled' ), true ) ) {
			return $state;
		}

		return 'assigned';
	}

	private function is_assignment_within_window( string $sold_date, string $starts_at, string $ends_at ): bool {
		if ( '' === $sold_date ) {
			return false;
		}

		if ( '' !== $starts_at && $sold_date < $starts_at ) {
			return false;
		}

		if ( '' !== $ends_at && $sold_date > $ends_at ) {
			return false;
		}

		return true;
	}

	private function find_manual_fallback_assignment( array $assignments ): ?array {
		foreach ( $assignments as $assignment ) {
			if ( ! empty( $assignment['manual_fallback'] ) || 'manual' === $assignment['assignment_state'] ) {
				return $assignment;
			}
		}

		return null;
	}

	private function compare_assignment_priority( array $left, array $right ): int {
		$left_priority  = (int) ( $left['priority'] ?? 0 );
		$right_priority = (int) ( $right['priority'] ?? 0 );

		if ( $left_priority !== $right_priority ) {
			return $left_priority <=> $right_priority;
		}

		$left_created  = sanitize_text_field( $left['created_at'] ?? '' );
		$right_created = sanitize_text_field( $right['created_at'] ?? '' );

		if ( '' !== $left_created || '' !== $right_created ) {
			$result = strcmp( $left_created, $right_created );

			if ( 0 !== $result ) {
				return $result;
			}
		}

		return (int) $left['assignment_id'] <=> (int) $right['assignment_id'];
	}

	private function normalize_date( string $value ): string {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return '';
		}

		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d', $time ) : $value;
	}

	private function normalize_rate( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		return round( (float) $value, 4 );
	}
}
