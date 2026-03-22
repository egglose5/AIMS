<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Supervisor_Inventory_Data_Provider {
	private $bucket_repository;
	private $vendor_repository;
	private $event_repository;
	private $scope_resolver;
	private $inventory_service;

	public function __construct(
		AIMS_Inventory_Bucket_Repository $bucket_repository = null,
		AIMS_Vendor_Repository $vendor_repository = null,
		AIMS_Event_Repository $event_repository = null,
		AIMS_Admin_Scope_Resolver $scope_resolver = null,
		AIMS_Inventory_Service $inventory_service = null
	) {
		$this->bucket_repository = $bucket_repository ?: new AIMS_Inventory_Bucket_Repository();
		$this->vendor_repository  = $vendor_repository ?: new AIMS_Vendor_Repository();
		$this->event_repository   = $event_repository ?: new AIMS_Event_Repository();
		$this->scope_resolver     = $scope_resolver ?: new AIMS_Admin_Scope_Resolver();
		$this->inventory_service  = $inventory_service ?: new AIMS_Inventory_Service(
			$this->bucket_repository,
			new AIMS_Inventory_Movement_Repository()
		);
	}

	public function get_rows(): array {
		$buckets = $this->get_bucket_rows();

		return array_map(
			array( $this, 'normalize_row' ),
			$buckets
		);
	}

	public function get_summary(): array {
		$rows = $this->get_rows();

		$summary = array(
			'total'     => 0,
			'event'     => 0,
			'vendor'    => 0,
			'warehouse' => 0,
		);

		foreach ( $rows as $row ) {
			$summary['total']++;
			$type = ! empty( $row['bucket_type'] ) ? (string) $row['bucket_type'] : 'warehouse';
			if ( isset( $summary[ $type ] ) ) {
				$summary[ $type ]++;
			}
		}

		return $summary;
	}

	public function get_event_transfer_rows(): array {
		return $this->inventory_service->get_event_transfer_operator_rows(
			array(
				'limit' => 25,
			)
		);
	}

	public function get_event_transfer_summary(): array {
		$rows = $this->get_event_transfer_rows();

		$summary = array(
			'ready_to_transfer' => 0,
			'at_show'          => 0,
			'show_complete'    => 0,
			'partially_returned' => 0,
		);

		foreach ( $rows as $row ) {
			$state = ! empty( $row['operator_state'] ) ? (string) $row['operator_state'] : '';
			if ( isset( $summary[ $state ] ) ) {
				$summary[ $state ]++;
			}
		}

		return $summary;
	}

	private function get_bucket_rows(): array {
		$rows = $this->scope_resolver->get_accessible_buckets();
		return is_array( $rows ) ? $rows : array();
	}

	private function normalize_row( array $bucket ): array {
		$quantity  = (float) ( $bucket['quantity'] ?? 0 );
		$reserved  = (float) ( $bucket['reserved_quantity'] ?? 0 );
		$available = max( 0, $quantity - $reserved );
		$type      = ! empty( $bucket['bucket_type'] ) ? (string) $bucket['bucket_type'] : 'warehouse';

		return array(
			'bucket_label'       => ! empty( $bucket['bucket_label'] ) ? (string) $bucket['bucket_label'] : 'Unlabeled bucket',
			'scope_label'        => $this->build_scope_label( $bucket ),
			'bucket_type'        => $type,
			'access_label'       => $this->build_access_label(),
			'quantity'           => number_format( $quantity, 4, '.', '' ),
			'reserved_quantity'  => number_format( $reserved, 4, '.', '' ),
			'available_quantity' => number_format( $available, 4, '.', '' ),
			'updated_at'         => ! empty( $bucket['updated_at'] ) ? (string) $bucket['updated_at'] : current_time( 'mysql' ),
		);
	}

	private function build_scope_label( array $bucket ): string {
		$bucket_type = ! empty( $bucket['bucket_type'] ) ? (string) $bucket['bucket_type'] : '';
		$bucket_id   = ! empty( $bucket['owner_entity_id'] ) ? (int) $bucket['owner_entity_id'] : 0;
		$vendor_id   = ! empty( $bucket['vendor_id'] ) ? (int) $bucket['vendor_id'] : 0;

		if ( 'event' === $bucket_type ) {
			$event = $this->find_event_name( $bucket_id );
			if ( '' !== $event ) {
				return $event;
			}
		}

		if ( 0 !== $vendor_id ) {
			$vendor = $this->find_vendor_name( $vendor_id );
			if ( '' !== $vendor ) {
				return $vendor;
			}
		}

		return ! empty( $bucket['bucket_label'] ) ? (string) $bucket['bucket_label'] : 'Unassigned scope';
	}

	private function build_access_label(): string {
		return $this->scope_resolver->can_manage_all() ? 'Full access' : 'Bucket-scoped';
	}

	private function find_vendor_name( int $vendor_id ): string {
		$vendor = $this->vendor_repository->find( $vendor_id );

		return ! empty( $vendor['vendor_name'] ) ? (string) $vendor['vendor_name'] : '';
	}

	private function find_event_name( int $event_id ): string {
		$events = $this->event_repository->all();

		foreach ( $events as $event ) {
			if ( ! empty( $event['id'] ) && (int) $event['id'] === $event_id ) {
				return ! empty( $event['event_name'] ) ? (string) $event['event_name'] : '';
			}
		}

		return '';
	}
}
