<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Queue_Data_Provider {
	private $workflow_service;
	private $scope_resolver;

	public function __construct(
		AIMS_Stitch_Workflow_Service $workflow_service = null,
		AIMS_Admin_Scope_Resolver $scope_resolver = null
	) {
		$this->workflow_service = $workflow_service ?: new AIMS_Stitch_Workflow_Service(
			new AIMS_Stitch_Job_Repository(),
			new AIMS_Audit_Service()
		);
		$this->scope_resolver = $scope_resolver ?: new AIMS_Admin_Scope_Resolver();
	}

	public function get_rows(): array {
		$rows = $this->workflow_service->get_queue_rows(
			array(
				'limit' => 50,
			),
			(int) get_current_user_id()
		);

		return $this->filter_rows_by_scope( $rows );
	}

	public function user_can_manage_stitch_jobs(): bool {
		return $this->workflow_service->user_can_manage_stitch_jobs( (int) get_current_user_id() );
	}

	public function get_summary(): array {
		$rows = $this->get_rows();

		$summary = array(
			'total'            => 0,
			'queued'           => 0,
			'received'         => 0,
			'in_progress'      => 0,
			'ready_for_pickup' => 0,
			'closed'           => 0,
			'open'             => 0,
		);

		foreach ( $rows as $row ) {
			$status = ! empty( $row['status'] ) ? (string) $row['status'] : AIMS_Stitch_Job_Repository::STATUS_QUEUED;
			$summary['total']++;

			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}

			if ( AIMS_Stitch_Job_Repository::STATUS_CLOSED !== $status ) {
				$summary['open']++;
			}
		}

		return $summary;
	}

	public function get_status_options(): array {
		return $this->workflow_service->get_status_options();
	}

	public function get_workflow_service(): AIMS_Stitch_Workflow_Service {
		return $this->workflow_service;
	}

	private function filter_rows_by_scope( array $rows ): array {
		$scope = $this->scope_resolver->get_accessible_scope_ids( (int) get_current_user_id() );
		if ( ! empty( $scope['all'] ) ) {
			return $rows;
		}

		$vendor_ids = ! empty( $scope['vendor_ids'] ) ? array_map( 'intval', (array) $scope['vendor_ids'] ) : array();
		$event_ids  = ! empty( $scope['event_ids'] ) ? array_map( 'intval', (array) $scope['event_ids'] ) : array();

		if ( empty( $vendor_ids ) && empty( $event_ids ) ) {
			return array();
		}

		$vendor_lookup = ! empty( $vendor_ids ) ? array_fill_keys( $vendor_ids, true ) : array();
		$event_lookup  = ! empty( $event_ids ) ? array_fill_keys( $event_ids, true ) : array();
		$filtered      = array();

		foreach ( $rows as $row ) {
			$vendor_id = (int) ( $row['vendor_id'] ?? 0 );
			$event_id  = (int) ( $row['event_id'] ?? 0 );

			if ( ( $vendor_id > 0 && isset( $vendor_lookup[ $vendor_id ] ) ) || ( $event_id > 0 && isset( $event_lookup[ $event_id ] ) ) ) {
				$filtered[] = $row;
			}
		}

		return $filtered;
	}
}
