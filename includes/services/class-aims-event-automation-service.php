<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Automation_Service {
	private $events;
	private $sales;
	private $assignments;
	private $financials;

	public function __construct(
		AIMS_Event_Repository $events,
		AIMS_Square_Sale_Repository $sales,
		AIMS_Vendor_Event_Assignment_Repository $assignments,
		AIMS_Event_Financial_Service $financials
	) {
		$this->events      = $events;
		$this->sales       = $sales;
		$this->assignments = $assignments;
		$this->financials  = $financials;
	}

	public function get_participation_model_for_event( int $event_id ): array {
		$event            = $this->get_event_participation_row( $event_id );
		$assignment_model = $this->assignments->get_assignment_model_for_event( $event_id );
		$status           = $this->get_participation_status_for_event( $event_id );
		$eligible_count   = (int) ( $assignment_model['eligible_count'] ?? 0 );
		$capacity         = (int) ( $event['vendor_capacity'] ?? 0 );

		return array_merge(
			$assignment_model,
			array(
				'event'                 => $event,
				'event_id'              => $event_id,
				'participation_status'   => $status,
				'vendor_capacity'       => $capacity,
				'vendor_request_limit'  => (int) ( $event['vendor_request_limit'] ?? 0 ),
				'vendor_request_count'   => (int) ( $event['vendor_request_count'] ?? 0 ),
				'is_open_for_request'   => 'open_for_request' === $status,
				'is_request_closed'     => in_array( $status, array( 'request_closed', 'closed' ), true ),
				'has_capacity_remaining' => 0 === $capacity || $eligible_count < $capacity,
			)
		);
	}

	public function get_participation_status_for_event( int $event_id ): string {
		$event = $this->get_event_participation_row( $event_id );

		return ! empty( $event['participation_status'] )
			? $this->normalize_participation_status( (string) $event['participation_status'] )
			: 'draft';
	}

	public function is_event_open_for_requests( int $event_id ): bool {
		return 'open_for_request' === $this->get_participation_status_for_event( $event_id );
	}

	public function get_request_queue_for_event( int $event_id ): array {
		return $this->assignments->get_requested_for_event( $event_id );
	}

	public function get_authorized_assignments_for_event( int $event_id ): array {
		return $this->assignments->get_authorized_assignments_for_event( $event_id );
	}

	public function get_authorized_vendor_id_for_event( int $event_id ): int {
		return $this->assignments->get_vendor_id_for_event( $event_id );
	}

	public function set_event_participation_controls( int $event_id, array $data = array() ): ?array {
		$changes = array();

		if ( array_key_exists( 'vendor_capacity', $data ) ) {
			$changes['vendor_capacity'] = max( 0, (int) $data['vendor_capacity'] );
		}

		if ( array_key_exists( 'vendor_request_limit', $data ) ) {
			$changes['vendor_request_limit'] = max( 0, (int) $data['vendor_request_limit'] );
		}

		if ( array_key_exists( 'participation_status', $data ) ) {
			$changes['participation_status'] = $this->normalize_participation_status( (string) $data['participation_status'] );
		}

		if ( ! $this->update_event_participation_row( $event_id, $changes ) ) {
			return null;
		}

		return $this->refresh_participation_state( $event_id );
	}

	public function open_event_for_requests( int $event_id, array $data = array() ): ?array {
		return $this->set_event_participation_controls(
			$event_id,
			array_merge(
				$data,
				array(
					'participation_status' => 'open_for_request',
				)
			)
		);
	}

	public function close_event_requests( int $event_id ): ?array {
		if ( ! $this->update_event_participation_row(
			$event_id,
			array(
				'participation_status' => 'request_closed',
			)
		) ) {
			return null;
		}

		return $this->refresh_participation_state( $event_id );
	}

	public function request_vendor_participation( int $event_id, int $vendor_id, array $data = array() ): ?array {
		$current_status = $this->get_participation_status_for_event( $event_id );

		if ( in_array( $current_status, array( 'request_closed', 'fully_assigned' ), true ) ) {
			return null;
		}

		$assignment_id = $this->assignments->request_vendor_participation(
			$event_id,
			$vendor_id,
			$data
		);

		if ( $assignment_id <= 0 ) {
			return null;
		}

		$this->refresh_participation_state( $event_id );

		return $this->assignments->find_by_id( $assignment_id );
	}

	public function approve_next_vendor_request( int $event_id ): ?array {
		$assignment = $this->assignments->approve_next_request_for_event( $event_id );

		if ( empty( $assignment ) ) {
			return null;
		}

		$this->refresh_participation_state( $event_id );
		$this->recalculate_after_assignment( $event_id );

		return $assignment;
	}

	public function manual_assign_vendor_to_event( int $event_id, int $vendor_id, array $data = array() ): ?array {
		$assignment_id = $this->assignments->manual_assign_vendor( $event_id, $vendor_id, $data );

		if ( $assignment_id <= 0 ) {
			return null;
		}

		$this->refresh_participation_state( $event_id );
		$this->recalculate_after_assignment( $event_id );

		return $this->assignments->find_by_id( $assignment_id );
	}

	public function match_sale_to_event( array $sale ): ?array {
		$square_location_id = (string) ( $sale['square_location_id'] ?? '' );
		$sold_at            = (string) ( $sale['sold_at'] ?? '' );

		if ( '' === $square_location_id || '' === $sold_at ) {
			return null;
		}

		return $this->events->find_matching_event( $square_location_id, $sold_at );
	}

	public function assign_sale_to_matching_event( array $sale ): ?array {
		if ( ! empty( $sale['event_id'] ) && (int) $sale['event_id'] > 0 ) {
			return null;
		}

		$matched_event = $this->match_sale_to_event( $sale );

		if ( empty( $matched_event['id'] ) || empty( $sale['id'] ) ) {
			return null;
		}

		if ( ! $this->apply_sale_assignment( $sale, $matched_event, true ) ) {
			return null;
		}

		return $matched_event;
	}

	public function assign_sale_by_id( int $sale_id ): ?array {
		$sale = $this->sales->find_by_id( $sale_id );

		if ( empty( $sale ) ) {
			return null;
		}

		return $this->assign_sale_to_matching_event( $sale );
	}

	public function recalculate_for_event( int $event_id ): array {
		return $this->financials->recalculate_event( $event_id );
	}

	public function get_vendor_linkage_policy_for_event( int $event_id ): string {
		$model = $this->assignments->get_assignment_model_for_event( $event_id );

		return ! empty( $model['policy'] ) ? (string) $model['policy'] : 'request_first';
	}

	public function get_assignment_model_for_event( int $event_id ): array {
		return $this->assignments->get_assignment_model_for_event( $event_id );
	}

	public function refresh_participation_state( int $event_id ): ?array {
		$event = $this->get_event_participation_row( $event_id );

		if ( empty( $event ) ) {
			return null;
		}

		$request_count    = $this->assignments->count_requested_for_event( $event_id );
		$authorized_count = $this->assignments->count_authorized_for_event( $event_id );
		$status           = $this->derive_participation_status( $event, $request_count, $authorized_count );

		$this->update_event_participation_row(
			$event_id,
			array(
				'vendor_request_count' => $request_count,
				'participation_status' => $status,
			)
		);

		return $this->get_participation_model_for_event( $event_id );
	}

	public function process_unassigned_sales_batch( array $sales ): array {
		$results = array(
			'processed' => 0,
			'assigned'  => 0,
			'events'    => array(),
		);

		foreach ( $sales as $sale ) {
			$results['processed']++;

			if ( ! empty( $sale['event_id'] ) && (int) $sale['event_id'] > 0 ) {
				continue;
			}

			$matched_event = $this->match_sale_to_event( $sale );

			if ( empty( $matched_event['id'] ) ) {
				continue;
			}

			if ( $this->apply_sale_assignment( $sale, $matched_event, false ) ) {
				$results['assigned']++;
				$results['events'][ (int) $matched_event['id'] ] = true;
			}
		}

		foreach ( array_keys( $results['events'] ) as $event_id ) {
			$this->recalculate_after_assignment( (int) $event_id );
		}

		$results['events'] = array_keys( $results['events'] );

		return $results;
	}

	public function assign_unassigned_sales_for_location_date( string $square_location_id, string $sold_at ): array {
		$sales = $this->sales->get_unassigned_sales_by_location_and_date( $square_location_id, $sold_at );

		return $this->process_unassigned_sales_batch( $sales );
	}

	public function reconcile_sales_for_event_window( string $square_location_id, string $sold_at ): int {
		$matched_event = $this->events->find_matching_event( $square_location_id, $sold_at );

		if ( empty( $matched_event['id'] ) ) {
			return 0;
		}

		$sales = $this->sales->get_unassigned_sales_by_location_and_date( $square_location_id, $sold_at );
		$assigned_count = 0;

		foreach ( $sales as $sale ) {
			if ( $this->apply_assignment_to_sale( (int) $sale['id'], (int) $matched_event['id'] ) ) {
				$assigned_count++;
			}
		}

		if ( $assigned_count > 0 ) {
			$this->recalculate_after_assignment( (int) $matched_event['id'] );
		}

		return $assigned_count;
	}

	public function recalculate_after_assignment( int $event_id ): array {
		return $this->financials->recalculate_event( $event_id );
	}

	private function apply_sale_assignment( array $sale, array $matched_event, bool $recalculate = true ): bool {
		$assigned = $this->apply_assignment_to_sale( (int) $sale['id'], (int) $matched_event['id'] );

		if ( $assigned && $recalculate ) {
			$this->recalculate_after_assignment( (int) $matched_event['id'] );
		}

		return $assigned;
	}

	private function apply_assignment_to_sale( int $sale_id, int $event_id ): bool {
		$vendor_id = $this->get_authorized_vendor_id_for_event( $event_id );

		return $this->sales->assign_event( $sale_id, $event_id, $vendor_id );
	}

	private function get_event_participation_row( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->events_table_name() . ' WHERE id = %d',
				$event_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function update_event_participation_row( int $event_id, array $changes ): bool {
		global $wpdb;

		$record  = array();
		$formats = array();

		foreach (
			array(
				'participation_status' => '%s',
				'vendor_capacity'      => '%d',
				'vendor_request_limit' => '%d',
				'vendor_request_count' => '%d',
			) as $field => $format
		) {
			if ( array_key_exists( $field, $changes ) ) {
				$record[ $field ] = 'participation_status' === $field
					? $this->normalize_participation_status( (string) $changes[ $field ] )
					: max( 0, (int) $changes[ $field ] );
				$formats[] = $format;
			}
		}

		if ( empty( $record ) ) {
			return false;
		}

		$record['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		$updated = $wpdb->update(
			$this->events_table_name(),
			$record,
			array( 'id' => $event_id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	private function derive_participation_status( array $event, int $request_count, int $authorized_count ): string {
		$current_status = $this->normalize_participation_status( (string) ( $event['participation_status'] ?? 'draft' ) );
		$vendor_capacity = max( 0, (int) ( $event['vendor_capacity'] ?? 0 ) );
		$request_limit   = max( 0, (int) ( $event['vendor_request_limit'] ?? 0 ) );

		if ( in_array( $current_status, array( 'request_closed', 'closed' ), true ) ) {
			return 'request_closed';
		}

		if ( $vendor_capacity > 0 && $authorized_count >= $vendor_capacity ) {
			return 'fully_assigned';
		}

		if ( $authorized_count > 0 ) {
			return 'partially_assigned';
		}

		if ( $request_limit > 0 && $request_count >= $request_limit ) {
			return 'request_closed';
		}

		if ( $request_count > 0 ) {
			return 'open_for_request';
		}

		if ( 'open_for_request' === $current_status ) {
			return 'open_for_request';
		}

		return $current_status;
	}

	private function normalize_participation_status( string $status ): string {
		$status = sanitize_key( $status );

		if ( in_array(
			$status,
			array(
				'draft',
				'open_for_request',
				'request_closed',
				'partially_assigned',
				'fully_assigned',
				'closed',
			),
			true
		) ) {
			return 'closed' === $status ? 'request_closed' : $status;
		}

		return 'draft';
	}

	private function events_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_events';
	}
}
