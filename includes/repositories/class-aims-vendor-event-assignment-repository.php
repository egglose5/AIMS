<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Event_Assignment_Repository {
	public const STATUS_REQUESTED = 'requested';
	public const STATUS_ASSIGNED = 'assigned';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_MANUAL   = 'manual';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendor_event_assignments';
	}

	public function save( array $data, int $assignment_id = 0 ): int {
		global $wpdb;

		$status = $this->normalize_assignment_status( (string) ( $data['assignment_status'] ?? self::STATUS_REQUESTED ) );
		$request_sequence = isset( $data['request_sequence'] ) ? (int) $data['request_sequence'] : null;

		if ( self::STATUS_REQUESTED !== $status ) {
			$request_sequence = null;
		} elseif ( null === $request_sequence || $request_sequence <= 0 ) {
			$request_sequence = $this->next_request_sequence_for_event( (int) ( $data['event_id'] ?? 0 ) );
		}

		$record = array(
			'event_id'          => (int) ( $data['event_id'] ?? 0 ),
			'vendor_id'         => (int) ( $data['vendor_id'] ?? 0 ),
			'assignment_status'  => $status,
			'request_sequence'   => $request_sequence,
			'commission_rate'    => number_format( (float) ( $data['commission_rate'] ?? 0 ), 4, '.', '' ),
			'fulfillment_status' => sanitize_key( $data['fulfillment_status'] ?? 'pending' ),
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $assignment_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $assignment_id ),
			array( '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%s' ),
			array( '%d' )
		);

			return $assignment_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function get_for_event( int $event_id ): array {
		return $this->get_eligible_for_event( $event_id );
	}

	public function get_primary_for_event( int $event_id ): ?array {
		return null;
	}

	public function get_vendor_id_for_event( int $event_id ): int {
		$assignment = $this->get_authorized_for_event( $event_id );

		return ! empty( $assignment['vendor_id'] ) ? (int) $assignment['vendor_id'] : 0;
	}

	public function get_authorized_for_event( int $event_id ): ?array {
		$assignments = $this->get_eligible_for_event( $event_id );

		return 1 === count( $assignments ) ? $assignments[0] : null;
	}

	public function has_authorized_assignment_for_event( int $event_id ): bool {
		return $this->has_eligible_assignment_for_event( $event_id );
	}

	public function has_eligible_assignment_for_event( int $event_id ): bool {
		return ! empty( $this->get_eligible_for_event( $event_id ) );
	}

	public function get_requestable_for_event( int $event_id ): array {
		return $this->get_for_event_by_statuses(
			$event_id,
			array( self::STATUS_REQUESTED )
		);
	}

	public function get_eligible_for_event( int $event_id ): array {
		// Only approved or manual rows count as eligible, because request rows are queue state, not capacity state.
		return $this->get_for_event_by_statuses(
			$event_id,
			array(
				self::STATUS_APPROVED,
				self::STATUS_MANUAL,
			)
		);
	}

	public function get_assignment_model_for_event( int $event_id ): array {
		$requestable = $this->get_requestable_for_event( $event_id );
		$eligible    = $this->get_eligible_for_event( $event_id );
		$approved    = $this->get_for_event_by_statuses( $event_id, array( self::STATUS_APPROVED ) );
		$manual      = $this->get_for_event_by_statuses( $event_id, array( self::STATUS_MANUAL ) );

		// The model keeps the request queue and the eligible set separate so FCFS approval and manual fallback can coexist cleanly.
		return array(
			'event_id'          => $event_id,
			'policy'            => ! empty( $eligible ) ? 'approved_manual_fallback' : 'request_first',
			'request_count'     => count( $requestable ),
			'eligible_count'    => count( $eligible ),
			'approved_count'    => count( $approved ),
			'manual_count'      => count( $manual ),
			'capacity_status'   => $this->derive_capacity_status( $event_id, count( $eligible ), count( $requestable ) ),
			'has_request_queue'  => ! empty( $requestable ),
			'has_eligible_rows'  => ! empty( $eligible ),
			'has_manual_fallback'=> ! empty( $manual ),
		);
	}

	public function get_requested_for_event( int $event_id ): array {
		return $this->get_requestable_for_event( $event_id );
	}

	public function get_authorized_assignments_for_event( int $event_id ): array {
		return $this->get_eligible_for_event( $event_id );
	}

	public function count_requested_for_event( int $event_id ): int {
		return count( $this->get_requestable_for_event( $event_id ) );
	}

	public function count_authorized_for_event( int $event_id ): int {
		return count( $this->get_eligible_for_event( $event_id ) );
	}

	public function request_vendor_participation( int $event_id, int $vendor_id, array $data = array() ): int {
		$sequence = $this->next_request_sequence_for_event( $event_id );

		return $this->save(
			array_merge(
				$data,
				array(
					'event_id'          => $event_id,
					'vendor_id'         => $vendor_id,
					'assignment_status'  => self::STATUS_REQUESTED,
					'request_sequence'   => $sequence,
				)
			)
		);
	}

	public function approve_next_request_for_event( int $event_id ): ?array {
		$request = $this->get_next_request_for_event( $event_id );

		if ( empty( $request['id'] ) ) {
			return null;
		}

		$this->approve_assignment( (int) $request['id'] );

		return $this->find_by_id( (int) $request['id'] );
	}

	public function approve_assignment( int $assignment_id ): bool {
		return $this->update_assignment_status( $assignment_id, self::STATUS_APPROVED );
	}

	public function manual_assign_vendor( int $event_id, int $vendor_id, array $data = array() ): int {
		$assignment_id = (int) ( $data['assignment_id'] ?? 0 );
		$record = array_merge(
			$data,
			array(
				'event_id'         => $event_id,
				'vendor_id'        => $vendor_id,
				'assignment_status' => self::STATUS_MANUAL,
			)
		);

		return $this->save( $record, $assignment_id );
	}

	public function update_assignment_status( int $assignment_id, string $status ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'assignment_status' => $this->normalize_assignment_status( $status ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $assignment_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function find_by_id( int $assignment_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$assignment_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_all_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_for_event_by_statuses( int $event_id, array $statuses ): array {
		global $wpdb;

		$statuses = array_values(
			array_filter(
				array_map( array( $this, 'normalize_assignment_status' ), $statuses )
			)
		);

		if ( empty( $statuses ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = $wpdb->prepare(
			'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND assignment_status IN (' . $placeholders . ') ORDER BY id ASC',
			...array_merge( array( $event_id ), $statuses )
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private function normalize_assignment_status( string $status ): string {
		$status = sanitize_key( $status );

		if ( in_array(
			$status,
			array(
				self::STATUS_REQUESTED,
				self::STATUS_ASSIGNED,
				self::STATUS_APPROVED,
				self::STATUS_MANUAL,
				'pending',
				'request',
				'queued',
			),
			true
		) ) {
			return in_array( $status, array( 'pending', 'request', 'queued' ), true ) ? self::STATUS_REQUESTED : $status;
		}

		return self::STATUS_REQUESTED;
	}

	private function next_request_sequence_for_event( int $event_id ): int {
		global $wpdb;

		$next = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(MAX(request_sequence), 0) + 1 FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND assignment_status = %s',
				$event_id,
				self::STATUS_REQUESTED
			)
		);

		return max( 1, (int) $next );
	}

	private function get_next_request_for_event( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND assignment_status = %s ORDER BY request_sequence ASC, id ASC LIMIT 1',
				$event_id,
				self::STATUS_REQUESTED
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function derive_capacity_status( int $event_id, int $eligible_count, int $request_count ): string {
		if ( $eligible_count > 0 ) {
			return 'partially_assigned';
		}

		if ( $request_count > 0 ) {
			return 'open_for_request';
		}

		return 'open_for_request';
	}
}
