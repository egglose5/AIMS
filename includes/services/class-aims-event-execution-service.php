<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Execution_Service {
	private $assignment_service;
	private $assignment_repository;
	private $bucket_positions;
	private $bucket_movement_service;
	private $vendor_event_assignments;
	private $physical_buckets;
	private $headless_execution_mirror;
	private $vendor_bucket_square_sync;

	public function __construct(
		AIMS_Event_Bucket_Assignment_Service $assignment_service = null,
		AIMS_Event_Bucket_Assignment_Repository $assignment_repository = null,
		AIMS_Bucket_Inventory_Position_Repository $bucket_positions = null,
		AIMS_Bucket_Movement_Service $bucket_movement_service = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Physical_Bucket_Repository $physical_buckets = null,
		AIMS_Headless_Execution_Mirror_Service $headless_execution_mirror = null,
		AIMS_Vendor_Bucket_Square_Sync_Service $vendor_bucket_square_sync = null
	) {
		$this->assignment_service    = $assignment_service ?: new AIMS_Event_Bucket_Assignment_Service( new AIMS_Event_Bucket_Assignment_Repository() );
		$this->assignment_repository = $assignment_repository ?: new AIMS_Event_Bucket_Assignment_Repository();
		$this->bucket_positions      = $bucket_positions ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->bucket_movement_service = $bucket_movement_service ?: new AIMS_Bucket_Movement_Service(
			new AIMS_Bucket_Inventory_Movement_Repository(),
			new AIMS_Bucket_Inventory_Position_Repository()
		);
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->physical_buckets         = $physical_buckets ?: new AIMS_Physical_Bucket_Repository();
		$this->headless_execution_mirror = $headless_execution_mirror ?: new AIMS_Headless_Execution_Mirror_Service();
		$this->vendor_bucket_square_sync = $vendor_bucket_square_sync ?: ( class_exists( 'AIMS_Vendor_Bucket_Square_Sync_Service' ) ? new AIMS_Vendor_Bucket_Square_Sync_Service() : null );
	}

	public function get_planning_default_status(): string {
		return AIMS_Event_Bucket_Assignment_Repository::STATUS_STAGED;
	}

	public function get_execution_statuses(): array {
		return array(
			AIMS_Event_Bucket_Assignment_Repository::STATUS_ASSIGNED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_STAGED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_RETURNED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_RELEASED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_CANCELLED,
		);
	}

	public function is_execution_status( string $status ): bool {
		return in_array( sanitize_key( $status ), $this->get_execution_statuses(), true );
	}

	public function transition_assignment_status( int $assignment_id, string $status, array $data = array() ): bool {
		if ( $assignment_id <= 0 || ! is_object( $this->assignment_service ) || ! method_exists( $this->assignment_service, 'transition_assignment_status' ) ) {
			return false;
		}

		$status = sanitize_key( $status );
		if ( ! $this->is_execution_status( $status ) ) {
			$status = $this->get_planning_default_status();
		}

		return (bool) $this->assignment_service->transition_assignment_status( $assignment_id, $status, $data );
	}

	public function mark_assignment_in_transit( int $assignment_id, array $data = array() ): bool {
		return $this->transition_assignment_status( $assignment_id, AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT, $data );
	}

	public function mark_assignment_staged( int $assignment_id, array $data = array() ): bool {
		return $this->transition_assignment_status( $assignment_id, AIMS_Event_Bucket_Assignment_Repository::STATUS_STAGED, $data );
	}

	public function mark_assignment_at_event( int $assignment_id, array $data = array() ): bool {
		return $this->transition_assignment_status( $assignment_id, AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT, $data );
	}

	public function mark_assignment_returned( int $assignment_id, array $data = array() ): bool {
		return $this->transition_assignment_status( $assignment_id, AIMS_Event_Bucket_Assignment_Repository::STATUS_RETURNED, $data );
	}

	public function vendor_event_checkin( array $data ): array {
		$result = $this->apply_event_execution_movement(
			$data,
			array(
				'status'         => AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT,
				'reference_type' => 'vendor_event_checkin',
				'movement_type'  => 'event_load_out',
				'quantity_delta' => -1,
				'message'        => 'Vendor event check-in recorded.',
			)
		);

		if ( ! empty( $result['success'] ) && ! empty( $result['movement_triggered'] ) ) {
			do_action( 'aims_after_vendor_event_checkin', $result );
		}

		return $result;
	}

	public function event_return( array $data ): array {
		$result = $this->apply_event_execution_movement(
			$data,
			array(
				'status'         => AIMS_Event_Bucket_Assignment_Repository::STATUS_RETURNED,
				'reference_type' => 'vendor_event_return',
				'movement_type'  => 'event_return',
				'quantity_delta' => 1,
				'message'        => 'Event return recorded.',
			)
		);

		if ( ! empty( $result['success'] ) && ! empty( $result['movement_triggered'] ) ) {
			do_action( 'aims_after_event_return', $result );
		}

		return $result;
	}

	public function update_bucket_sealed_state( int $bucket_id, bool $is_sealed ): bool {
		if ( $bucket_id <= 0 || ! is_object( $this->physical_buckets ) || ! method_exists( $this->physical_buckets, 'update_sealed_state' ) ) {
			return false;
		}

		return (bool) $this->physical_buckets->update_sealed_state( $bucket_id, $is_sealed );
	}

	private function apply_event_execution_movement( array $data, array $config ): array {
		$assignment_id = (int) ( $data['assignment_id'] ?? 0 );
		$assignment    = $this->get_assignment( $assignment_id );

		if ( $assignment_id <= 0 || empty( $assignment ) ) {
			return $this->failure_response( $config['message'], 0, 0 );
		}

		$event_id = (int) ( $assignment['event_id'] ?? 0 );
		if ( $event_id <= 0 ) {
			return $this->failure_response( $config['message'], $assignment_id, 0 );
		}

		$bucket_id = (int) ( $assignment['physical_bucket_id'] ?? $assignment['bucket_id'] ?? 0 );
		if ( $bucket_id <= 0 ) {
			return $this->failure_response( $config['message'], $assignment_id, $event_id );
		}

		$current_status = sanitize_key( (string) ( $assignment['assignment_status'] ?? '' ) );
		if ( in_array( $current_status, array( AIMS_Event_Bucket_Assignment_Repository::STATUS_RELEASED, AIMS_Event_Bucket_Assignment_Repository::STATUS_CANCELLED ), true ) ) {
			return $this->failure_response( 'The selected bucket assignment is no longer active.', $assignment_id, $event_id );
		}

		$reference_id = sanitize_text_field(
			(string) ( $data['reference_id'] ?? ( 'assignment-' . $assignment_id . '-' . $config['reference_type'] ) )
		);
		$applied_by = (int) ( $data['applied_by'] ?? get_current_user_id() );
		$note       = isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '';
		$sealed_state = $this->resolve_sealed_state( $data );
		$bucket_context = $this->get_bucket_context( $bucket_id );
		$square_location_id = sanitize_text_field( (string) ( $bucket_context['square_location_id'] ?? '' ) );
		$occurred_at = current_time( 'mysql' );

		$movement_triggered = true;
		$movement_message   = (string) $config['message'];

		if ( 'vendor_event_checkin' === sanitize_key( (string) ( $config['reference_type'] ?? '' ) ) && ! $this->is_primary_vendor_checkin( $event_id, $assignment, $bucket_id ) ) {
			$movement_triggered = false;
			$movement_message   = 'Check-in recorded. Inventory movement and ledger posting will run when the primary vendor checks in.';
		}

		$positions = $movement_triggered ? $this->get_bucket_positions( $bucket_id ) : array();
		$movements  = array();
		$errors     = array();
		$headless_mirror = array(
			'attempted' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'results'   => array(),
		);

		foreach ( $positions as $position ) {
			$product_id = (int) ( $position['product_id'] ?? 0 );
			$vendor_id   = (int) ( $position['vendor_id'] ?? 0 );
			$quantity    = (float) ( $position['quantity'] ?? 0 );

			if ( $product_id <= 0 || $vendor_id <= 0 || 0.0 === $quantity ) {
				continue;
			}

			$movement = $this->record_execution_movement(
				array(
					'reference_type' => $config['reference_type'],
					'reference_id'   => $reference_id,
					'vendor_id'      => $vendor_id,
					'event_id'       => $event_id,
					'product_id'     => $product_id,
					'bucket_id'      => $bucket_id,
					'square_location_id' => $square_location_id,
					'movement_type'  => $config['movement_type'],
					'quantity_delta' => abs( $quantity ) * (float) $config['quantity_delta'],
					'sealed_state'   => null !== $sealed_state ? ( $sealed_state ? 1 : 0 ) : 0,
					'applied_by'     => $applied_by,
					'occurred_at'    => $occurred_at,
					'note'           => $note,
				)
			);

			if ( is_wp_error( $movement ) ) {
				if ( 'aims_duplicate_bucket_movement' === $movement->get_error_code() ) {
					$movements[] = array(
						'product_id'  => $product_id,
						'vendor_id'   => $vendor_id,
						'duplicate'   => true,
					);
					continue;
				}

				$errors[] = $movement;
				break;
			}

			$movements[] = array_merge(
				$movement,
				array(
					'product_id'  => $product_id,
					'vendor_id'   => $vendor_id,
					'duplicate'   => false,
				)
			);

			$mirror_result = $this->mirror_execution_movement_to_headless(
				array(
					'product_id'      => $product_id,
					'quantity_delta'  => abs( $quantity ) * (float) $config['quantity_delta'],
					'movement_type'   => (string) $config['movement_type'],
					'applied_by'      => $applied_by,
				),
				array(
					'event_id'       => $event_id,
					'show_id'        => (string) $event_id,
					'reference_type' => (string) ( $config['reference_type'] ?? '' ),
					'bucket'         => $bucket_context,
					'occurred_at'    => $occurred_at,
				)
			);

			$headless_mirror['results'][] = array_merge(
				array(
					'product_id' => $product_id,
					'vendor_id'  => $vendor_id,
				),
				$mirror_result
			);

			if ( ! empty( $mirror_result['attempted'] ) ) {
				++$headless_mirror['attempted'];
			}

			if ( ! empty( $mirror_result['success'] ) ) {
				++$headless_mirror['succeeded'];
			} elseif ( ! empty( $mirror_result['skipped'] ) ) {
				++$headless_mirror['skipped'];
			} else {
				++$headless_mirror['failed'];
			}
		}

		if ( ! empty( $errors ) ) {
			return $this->failure_response(
				$errors[0]->get_error_message(),
				$assignment_id,
				$event_id,
				array(
					'error_code' => $errors[0]->get_error_code(),
				)
			);
		}

		if ( ! $this->transition_assignment_status(
			$assignment_id,
			$config['status'],
			array(
				'notes'         => $note,
				'updated_by'    => $applied_by,
				'updated_at'    => current_time( 'mysql' ),
			)
		) ) {
			return $this->failure_response( 'The assignment status could not be updated.', $assignment_id, $event_id );
		}

		if ( null !== $sealed_state ) {
			$this->update_bucket_sealed_state( $bucket_id, $sealed_state );
		}

		$square_inventory_sync = $this->sync_bucket_inventory_to_vendor_square_location(
			$config,
			$assignment,
			$bucket_id,
			$event_id,
			$reference_id,
			$applied_by,
			$note,
			$square_location_id,
			$movement_triggered
		);

		return array(
			'success'            => true,
			'message'            => $movement_message,
			'assignment_id'      => $assignment_id,
			'event_id'           => $event_id,
			'physical_bucket_id' => $bucket_id,
			'status'             => $config['status'],
			'movement_triggered' => $movement_triggered,
			'square_location_id' => $square_location_id,
			'sealed_state'       => null !== $sealed_state ? ( $sealed_state ? 1 : 0 ) : null,
			'headless_mirror'    => $headless_mirror,
			'square_inventory_sync' => $square_inventory_sync,
			'movements'          => $movements,
			'movements_applied'  => count(
				array_filter(
					$movements,
					static function ( array $movement ): bool {
						return empty( $movement['duplicate'] );
					}
				)
			),
			'duplicate_count'    => count(
				array_filter(
					$movements,
					static function ( array $movement ): bool {
						return ! empty( $movement['duplicate'] );
					}
				)
			),
		);
	}

	private function is_primary_vendor_checkin( int $event_id, array $assignment, int $bucket_id ): bool {
		$primary_vendor_id = $this->get_primary_vendor_id_for_event( $event_id );
		$assignment_vendor_id = $this->get_assignment_vendor_id( $assignment, $bucket_id );

		if ( $primary_vendor_id <= 0 || $assignment_vendor_id <= 0 ) {
			return false;
		}

		return $primary_vendor_id === $assignment_vendor_id;
	}

	private function get_primary_vendor_id_for_event( int $event_id ): int {
		if ( $event_id <= 0 || ! is_object( $this->vendor_event_assignments ) || ! method_exists( $this->vendor_event_assignments, 'get_primary_for_event' ) ) {
			return 0;
		}

		$assignment = $this->vendor_event_assignments->get_primary_for_event( $event_id );
		if ( ! is_array( $assignment ) ) {
			return 0;
		}

		return (int) ( $assignment['vendor_id'] ?? 0 );
	}

	private function get_assignment_vendor_id( array $assignment, int $bucket_id ): int {
		$vendor_id = (int) ( $assignment['vendor_id'] ?? 0 );
		if ( $vendor_id > 0 ) {
			return $vendor_id;
		}

		$bucket = $this->get_bucket_context( $bucket_id );

		return (int) ( $bucket['vendor_id'] ?? 0 );
	}

	private function record_execution_movement( array $data ) {
		if ( ! is_object( $this->bucket_movement_service ) ) {
			return new WP_Error( 'aims_missing_bucket_movement_service', 'Bucket movement service is unavailable.' );
		}

		$movement_type = sanitize_key( $data['movement_type'] ?? '' );
		if ( 'event_return' === $movement_type && method_exists( $this->bucket_movement_service, 'record_event_return' ) ) {
			return $this->bucket_movement_service->record_event_return( $data );
		}

		if ( method_exists( $this->bucket_movement_service, 'record_event_load_out' ) ) {
			return $this->bucket_movement_service->record_event_load_out( $data );
		}

		if ( method_exists( $this->bucket_movement_service, 'record_movement' ) ) {
			return $this->bucket_movement_service->record_movement( $data );
		}

		return new WP_Error( 'aims_invalid_bucket_movement_service', 'Bucket movement service cannot record execution movements.' );
	}

	private function sync_bucket_inventory_to_vendor_square_location(
		array $config,
		array $assignment,
		int $bucket_id,
		int $event_id,
		string $reference_id,
		int $applied_by,
		string $note,
		string $square_location_id,
		bool $movement_triggered
	): array {
		if ( 'vendor_event_checkin' !== sanitize_key( (string) ( $config['reference_type'] ?? '' ) ) ) {
			return array(
				'success'   => false,
				'attempted' => false,
				'skipped'   => true,
				'reason'    => 'not_applicable',
			);
		}

		if ( ! $movement_triggered ) {
			return array(
				'success'   => false,
				'attempted' => false,
				'skipped'   => true,
				'reason'    => 'movement_not_triggered',
			);
		}

		if ( $bucket_id <= 0 || ! is_object( $this->vendor_bucket_square_sync ) || ! method_exists( $this->vendor_bucket_square_sync, 'sync_bucket_to_vendor_location' ) ) {
			return array(
				'success'   => false,
				'attempted' => false,
				'skipped'   => true,
				'reason'    => 'square_sync_unavailable',
			);
		}

		$vendor_id = $this->get_assignment_vendor_id( $assignment, $bucket_id );
		if ( $vendor_id <= 0 ) {
			return array(
				'success'   => false,
				'attempted' => false,
				'skipped'   => true,
				'reason'    => 'missing_vendor',
			);
		}

		return (array) $this->vendor_bucket_square_sync->sync_bucket_to_vendor_location(
			$bucket_id,
			$vendor_id,
			array(
				'event_id'           => $event_id,
				'reference_id'       => $reference_id,
				'reference_type'     => (string) ( $config['reference_type'] ?? '' ),
				'applied_by'         => $applied_by,
				'note'               => $note,
				'square_location_id' => $square_location_id,
			)
		);
	}

	private function resolve_sealed_state( array $data ): ?bool {
		if ( ! array_key_exists( 'sealed_state', $data ) ) {
			return null;
		}

		$value = $data['sealed_state'];
		if ( is_bool( $value ) ) {
			return $value;
		}

		$normalized = sanitize_key( (string) $value );
		if ( in_array( $normalized, array( '1', 'true', 'sealed', 'yes' ), true ) ) {
			return true;
		}

		if ( in_array( $normalized, array( '0', 'false', 'unsealed', 'no' ), true ) ) {
			return false;
		}

		return null;
	}

	private function resolve_bucket_square_location_id( int $bucket_id ): string {
		$bucket = $this->get_bucket_context( $bucket_id );

		return sanitize_text_field( (string) ( $bucket['square_location_id'] ?? '' ) );
	}

	private function get_bucket_context( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! is_object( $this->physical_buckets ) ) {
			return array();
		}

		if ( method_exists( $this->physical_buckets, 'find_with_context' ) ) {
			$bucket = $this->physical_buckets->find_with_context( $bucket_id );
			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		if ( method_exists( $this->physical_buckets, 'find' ) ) {
			$bucket = $this->physical_buckets->find( $bucket_id );
			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		return array();
	}

	private function get_assignment( int $assignment_id ): array {
		if ( $assignment_id <= 0 || ! is_object( $this->assignment_repository ) || ! method_exists( $this->assignment_repository, 'find' ) ) {
			return array();
		}

		$assignment = $this->assignment_repository->find( $assignment_id );

		return is_array( $assignment ) ? $assignment : array();
	}

	private function get_bucket_positions( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! is_object( $this->bucket_positions ) || ! method_exists( $this->bucket_positions, 'get_for_bucket' ) ) {
			return array();
		}

		$rows = array();
		foreach ( (array) $this->bucket_positions->get_for_bucket( $bucket_id ) as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	private function mirror_execution_movement_to_headless( array $movement, array $context ): array {
		if ( ! is_object( $this->headless_execution_mirror ) || ! method_exists( $this->headless_execution_mirror, 'mirror_event_execution_movement' ) ) {
			return array(
				'attempted' => false,
				'success'   => false,
				'skipped'   => true,
				'reason'    => 'mirror_unavailable',
			);
		}

		$result = $this->headless_execution_mirror->mirror_event_execution_movement( $movement, $context );

		return is_array( $result ) ? $result : array(
			'attempted' => false,
			'success'   => false,
			'skipped'   => true,
			'reason'    => 'mirror_invalid_response',
		);
	}

	private function failure_response( string $message, int $assignment_id, int $event_id, array $extra = array() ): array {
		return array_merge(
			array(
				'success'       => false,
				'message'       => $message,
				'assignment_id' => $assignment_id,
				'event_id'      => $event_id,
			),
			$extra
		);
	}
}
