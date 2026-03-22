<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Workflow_Service {
	private $jobs;
	private $audit;
	private $auth_context;

	public function __construct(
		AIMS_Stitch_Job_Repository $jobs = null,
		AIMS_Audit_Service $audit = null,
		AIMS_Auth_Context_Service $auth_context = null
	) {
		$this->jobs  = $jobs ?: new AIMS_Stitch_Job_Repository();
		$this->audit = $audit ?: new AIMS_Audit_Service();
		$this->auth_context = $auth_context ?: new AIMS_Auth_Context_Service();
	}

	public function get_queue_rows( array $filters = array() ): array {
		$rows = array();

		foreach ( $this->jobs->get_queue_rows( $filters ) as $job ) {
			$rows[] = $this->normalize_job_row( $job );
		}

		return $rows;
	}

	public function get_summary(): array {
		$summary = $this->jobs->get_summary_counts();

		return array(
			'total'            => (int) ( $summary['total'] ?? 0 ),
			'queued'           => (int) ( $summary['queued'] ?? 0 ),
			'received'         => (int) ( $summary['received'] ?? 0 ),
			'in_progress'      => (int) ( $summary['in_progress'] ?? 0 ),
			'ready_for_pickup' => (int) ( $summary['ready_for_pickup'] ?? 0 ),
			'closed'           => (int) ( $summary['closed'] ?? 0 ),
			'open'             => (int) ( $summary['open'] ?? 0 ),
		);
	}

	public function get_status_options(): array {
		return $this->jobs->get_status_options();
	}

	public function transition_job_status( int $job_id, string $target_status, int $actor_user_id = 0, array $context = array() ) {
		$actor_user_id = $this->normalize_actor_user_id( $actor_user_id );
		$job = $this->jobs->find_by_id( $job_id );
		if ( empty( $job ) ) {
			return new WP_Error( 'aims_stitch_job_missing', 'The stitch job could not be found.' );
		}

		if ( ! $this->auth_context->can_user( $actor_user_id, AIMS_Capabilities::CAP_MANAGE_STITCH ) ) {
			return new WP_Error( 'aims_stitch_access_denied', 'The current user cannot manage stitch jobs.' );
		}

		$current_status = $this->jobs->normalize_status( (string) ( $job['status'] ?? '' ) );
		$target_status  = $this->jobs->normalize_status( $target_status );

		$allowed = $this->allowed_transitions();
		if ( empty( $allowed[ $current_status ] ) || ! in_array( $target_status, $allowed[ $current_status ], true ) ) {
			return new WP_Error( 'aims_stitch_invalid_transition', 'That stitch transition is not allowed from the current state.' );
		}

		$update = array(
			'notes' => $this->build_transition_note( $current_status, $target_status, $context ),
		);

		if ( empty( $job['assigned_user_id'] ) && $actor_user_id > 0 && AIMS_Stitch_Job_Repository::STATUS_CLOSED !== $target_status ) {
			$update['assigned_user_id'] = $actor_user_id;
		}

		if ( array_key_exists( 'priority', $context ) ) {
			$update['priority'] = (string) $context['priority'];
		}

		if ( array_key_exists( 'due_at', $context ) ) {
			$update['due_at'] = (string) $context['due_at'];
		}

		$updated = $this->jobs->update_status( $job_id, $target_status, $update );
		if ( ! $updated ) {
			return new WP_Error( 'aims_stitch_transition_failed', 'The stitch job could not be updated.' );
		}

		$this->audit->record(
			'stitch_transition',
			array(
				'actor_id'   => $actor_user_id,
				'scope_type' => 'stitch_job',
				'scope_id'   => $job_id,
				'entity_type'=> 'stitch_job',
				'entity_id'  => $job_id,
				'reason'     => 'Stitch job status changed.',
				'details'    => array(
					'previous_status' => $current_status,
					'target_status'   => $target_status,
					'job_code'        => (string) ( $job['job_code'] ?? '' ),
				),
			)
		);

		$refreshed = $this->jobs->find_by_id( $job_id );

		return array(
			'job_id'         => $job_id,
			'previous_status' => $current_status,
			'target_status'   => $target_status,
			'job'            => $this->normalize_job_row( ! empty( $refreshed ) ? $refreshed : $job ),
			'message'        => $this->build_transition_message( $current_status, $target_status, $job ),
		);
	}

	public function receive_job( int $job_id, int $actor_user_id = 0, array $context = array() ) {
		return $this->transition_job_status( $job_id, AIMS_Stitch_Job_Repository::STATUS_RECEIVED, $actor_user_id, $context );
	}

	public function start_job( int $job_id, int $actor_user_id = 0, array $context = array() ) {
		return $this->transition_job_status( $job_id, AIMS_Stitch_Job_Repository::STATUS_IN_PROGRESS, $actor_user_id, $context );
	}

	public function mark_ready_for_pickup( int $job_id, int $actor_user_id = 0, array $context = array() ) {
		return $this->transition_job_status( $job_id, AIMS_Stitch_Job_Repository::STATUS_READY_FOR_PICKUP, $actor_user_id, $context );
	}

	public function close_job( int $job_id, int $actor_user_id = 0, array $context = array() ) {
		return $this->transition_job_status( $job_id, AIMS_Stitch_Job_Repository::STATUS_CLOSED, $actor_user_id, $context );
	}

	public function get_available_transition( array $job ): array {
		$status = $this->jobs->normalize_status( (string) ( $job['status'] ?? '' ) );
		$map = $this->available_transition_map();
		$transition = ! empty( $map[ $status ] ) ? $map[ $status ] : array();

		return array(
			'next_status' => ! empty( $transition['status'] ) ? (string) $transition['status'] : '',
			'label'       => ! empty( $transition['label'] ) ? (string) $transition['label'] : '',
			'available'   => ! empty( $transition ),
		);
	}

	private function normalize_job_row( array $job ): array {
		$status = $this->jobs->normalize_status( (string) ( $job['status'] ?? '' ) );
		$priority = $this->jobs->normalize_priority( (string) ( $job['priority'] ?? 'normal' ) );
		$due_at = ! empty( $job['due_at'] ) ? (string) $job['due_at'] : '';
		$next_transition = $this->get_available_transition( $job );

		return array(
			'id'               => (int) ( $job['id'] ?? 0 ),
			'job_code'         => ! empty( $job['job_code'] ) ? (string) $job['job_code'] : 'Stitch job',
			'vendor_id'        => (int) ( $job['vendor_id'] ?? 0 ),
			'event_id'         => (int) ( $job['event_id'] ?? 0 ),
			'assigned_user_id'  => (int) ( $job['assigned_user_id'] ?? 0 ),
			'status'           => $status,
			'status_label'     => $this->build_status_label( $status ),
			'priority'         => $priority,
			'priority_label'   => $this->build_priority_label( $priority ),
			'due_at'           => $due_at,
			'due_label'        => $this->build_due_label( $due_at ),
			'is_overdue'       => $this->is_overdue( $due_at, $status ),
			'notes'            => ! empty( $job['notes'] ) ? (string) $job['notes'] : '',
			'created_at'       => ! empty( $job['created_at'] ) ? (string) $job['created_at'] : '',
			'updated_at'       => ! empty( $job['updated_at'] ) ? (string) $job['updated_at'] : '',
			'next_transition'  => $next_transition,
		);
	}

	private function build_status_label( string $status ): string {
		$labels = $this->jobs->get_status_options();

		return ! empty( $labels[ $status ] ) ? (string) $labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
	}

	private function build_priority_label( string $priority ): string {
		return ucfirst( str_replace( '_', ' ', $priority ) );
	}

	private function build_due_label( string $due_at ): string {
		if ( '' === $due_at ) {
			return 'No due date';
		}

		$timestamp = strtotime( $due_at );
		if ( false === $timestamp ) {
			return $due_at;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private function is_overdue( string $due_at, string $status ): bool {
		if ( '' === $due_at || AIMS_Stitch_Job_Repository::STATUS_CLOSED === $status ) {
			return false;
		}

		$due_timestamp = strtotime( $due_at );
		return false !== $due_timestamp && $due_timestamp < current_time( 'timestamp' );
	}

	private function allowed_transitions(): array {
		return array(
			AIMS_Stitch_Job_Repository::STATUS_QUEUED => array( AIMS_Stitch_Job_Repository::STATUS_RECEIVED ),
			AIMS_Stitch_Job_Repository::STATUS_RECEIVED => array( AIMS_Stitch_Job_Repository::STATUS_IN_PROGRESS ),
			AIMS_Stitch_Job_Repository::STATUS_IN_PROGRESS => array( AIMS_Stitch_Job_Repository::STATUS_READY_FOR_PICKUP ),
			AIMS_Stitch_Job_Repository::STATUS_READY_FOR_PICKUP => array( AIMS_Stitch_Job_Repository::STATUS_CLOSED ),
			AIMS_Stitch_Job_Repository::STATUS_CLOSED => array(),
		);
	}

	private function available_transition_map(): array {
		return array(
			AIMS_Stitch_Job_Repository::STATUS_QUEUED => array(
				'status' => AIMS_Stitch_Job_Repository::STATUS_RECEIVED,
				'label'  => 'Mark received',
			),
			AIMS_Stitch_Job_Repository::STATUS_RECEIVED => array(
				'status' => AIMS_Stitch_Job_Repository::STATUS_IN_PROGRESS,
				'label'  => 'Start work',
			),
			AIMS_Stitch_Job_Repository::STATUS_IN_PROGRESS => array(
				'status' => AIMS_Stitch_Job_Repository::STATUS_READY_FOR_PICKUP,
				'label'  => 'Mark ready for pickup',
			),
			AIMS_Stitch_Job_Repository::STATUS_READY_FOR_PICKUP => array(
				'status' => AIMS_Stitch_Job_Repository::STATUS_CLOSED,
				'label'  => 'Close job',
			),
		);
	}

	private function build_transition_note( string $current_status, string $target_status, array $context ): string {
		$note = ! empty( $context['note'] ) ? sanitize_textarea_field( (string) $context['note'] ) : '';
		$base = sprintf(
			'Transitioned from %s to %s.',
			$this->build_status_label( $current_status ),
			$this->build_status_label( $target_status )
		);

		return '' !== $note ? $base . ' ' . $note : $base;
	}

	private function build_transition_message( string $current_status, string $target_status, array $job ): string {
		$job_code = ! empty( $job['job_code'] ) ? (string) $job['job_code'] : 'Stitch job';

		return sprintf(
			'%s moved from %s to %s.',
			$job_code,
			$this->build_status_label( $current_status ),
			$this->build_status_label( $target_status )
		);
	}

	private function normalize_actor_user_id( int $actor_user_id ): int {
		return $this->auth_context->normalize_actor_user_id( $actor_user_id );
	}
}
