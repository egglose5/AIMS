<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Action_Service {
	private $assignment_service;
	private $access_service;
	private $assignment_repository;
	private $execution_service;

	public function __construct(
		AIMS_Event_Bucket_Assignment_Service $assignment_service = null,
		$access_service = null,
		AIMS_Event_Bucket_Assignment_Repository $assignment_repository = null,
		AIMS_Event_Execution_Service $execution_service = null
	) {
		$this->assignment_service    = $assignment_service ?: new AIMS_Event_Bucket_Assignment_Service(
			new AIMS_Event_Bucket_Assignment_Repository()
		);
		$this->access_service        = $access_service ?: ( class_exists( 'AIMS_Event_Planning_Access_Service' ) ? new AIMS_Event_Planning_Access_Service() : null );
		$this->assignment_repository = $assignment_repository ?: new AIMS_Event_Bucket_Assignment_Repository();
		$this->execution_service     = $execution_service ?: ( class_exists( 'AIMS_Event_Execution_Service' ) ? new AIMS_Event_Execution_Service() : null );
	}

	public function can_current_user_manage_planning(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( is_object( $this->access_service ) && method_exists( $this->access_service, 'can_access_event_planning' ) ) {
			return (bool) $this->access_service->can_access_event_planning( $user_id );
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_BUCKETS );
	}

	public function can_current_user_mutate_event( int $event_id ): bool {
		$event_id = max( 0, $event_id );

		if ( $event_id <= 0 || ! $this->can_current_user_manage_planning() ) {
			return false;
		}

		$authorized_event_ids = $this->get_authorized_event_ids_for_current_user();
		if ( empty( $authorized_event_ids ) ) {
			return false;
		}

		return in_array( $event_id, $authorized_event_ids, true );
	}

	public function assign_bucket( array $request ): array {
		$event_id          = (int) ( $request['event_id'] ?? 0 );
		$bucket_id         = (int) ( $request['physical_bucket_id'] ?? $request['bucket_id'] ?? 0 );
		$assignment_type   = sanitize_key( $request['assignment_type'] ?? AIMS_Event_Bucket_Assignment_Repository::TYPE_EVENT_STOCK );
		$assignment_status = sanitize_key( $request['assignment_status'] ?? AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT );

		if ( $event_id <= 0 || $bucket_id <= 0 ) {
			return array(
				'success'  => false,
				'message'  => 'A valid event and bucket are required to assign planning inventory.',
				'event_id' => $event_id,
			);
		}

		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success'  => false,
				'message'  => 'You are not authorized to assign buckets to this event.',
				'event_id' => $event_id,
			);
		}

		$assignment_id = (int) $this->assignment_service->assign_bucket_to_event(
			array(
				'event_id'           => $event_id,
				'physical_bucket_id' => $bucket_id,
				'assignment_type'    => '' !== $assignment_type ? $assignment_type : AIMS_Event_Bucket_Assignment_Repository::TYPE_EVENT_STOCK,
				'assignment_status'  => '' !== $assignment_status ? $assignment_status : AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT,
				'assigned_at'        => $request['assigned_at'] ?? current_time( 'mysql' ),
				'assigned_by'        => get_current_user_id(),
				'display_order'      => (int) ( $request['display_order'] ?? 0 ),
				'is_active'          => 1,
				'notes'              => isset( $request['notes'] ) ? wp_kses_post( $request['notes'] ) : '',
			)
		);

		if ( $assignment_id <= 0 ) {
			return array(
				'success'  => false,
				'message'  => 'Bucket assignment could not be saved.',
				'event_id' => $event_id,
			);
		}

		return array(
			'success'       => true,
			'message'       => 'Bucket assigned to event planning.',
			'event_id'      => $event_id,
			'assignment_id' => $assignment_id,
		);
	}

	public function mark_in_transit( array $request ): array {
		return $this->transition_assignment_status_from_request(
			$request,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT,
			'Bucket marked in transit.',
			'You are not authorized to mark this event as in transit.'
		);
	}

	public function vendor_event_check_in( array $request ): array {
		$assignment = $this->load_assignment_from_request( $request );
		if ( ! is_array( $assignment ) ) {
			return array(
				'success'  => false,
				'message'  => 'A valid assignment is required for vendor event check-in.',
				'event_id' => (int) ( $request['event_id'] ?? 0 ),
			);
		}

		$event_id = (int) ( $assignment['event_id'] ?? 0 );
		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success'  => false,
				'message'  => 'You are not authorized to check in this event.',
				'event_id' => $event_id,
			);
		}

		$movement_result = $this->execute_vendor_event_check_in(
			$request,
			$assignment
		);

		if ( is_wp_error( $movement_result ) || ! is_array( $movement_result ) || empty( $movement_result['success'] ) ) {
			return array(
				'success'  => false,
				'message'  => is_wp_error( $movement_result ) ? $movement_result->get_error_message() : (string) ( $movement_result['message'] ?? 'Vendor event check-in could not be recorded.' ),
				'event_id' => $event_id,
			);
		}

		return array(
			'success'       => true,
			'message'       => (string) ( $movement_result['message'] ?? 'Vendor event check-in recorded.' ),
			'event_id'      => $event_id,
			'assignment_id' => (int) ( $assignment['id'] ?? 0 ),
			'result'        => $movement_result,
		);
	}

	public function mark_returned( array $request ): array {
		$assignment = $this->load_assignment_from_request( $request );
		if ( ! is_array( $assignment ) ) {
			return array(
				'success'  => false,
				'message'  => 'A valid assignment is required to mark an event returned.',
				'event_id' => (int) ( $request['event_id'] ?? 0 ),
			);
		}

		$event_id = (int) ( $assignment['event_id'] ?? 0 );
		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success'  => false,
				'message'  => 'You are not authorized to mark this event returned.',
				'event_id' => $event_id,
			);
		}

		$movement_result = $this->execute_event_return(
			$request,
			$assignment
		);

		if ( is_wp_error( $movement_result ) || ! is_array( $movement_result ) || empty( $movement_result['success'] ) ) {
			return array(
				'success'  => false,
				'message'  => is_wp_error( $movement_result ) ? $movement_result->get_error_message() : (string) ( $movement_result['message'] ?? 'Event return could not be recorded.' ),
				'event_id' => $event_id,
			);
		}

		return array(
			'success'       => true,
			'message'       => (string) ( $movement_result['message'] ?? 'Event return recorded.' ),
			'event_id'      => $event_id,
			'assignment_id' => (int) ( $assignment['id'] ?? 0 ),
			'result'        => $movement_result,
		);
	}

	public function release_after_return( array $request ): array {
		$assignment_id = (int) ( $request['assignment_id'] ?? 0 );
		$event_id      = (int) ( $request['event_id'] ?? 0 );

		if ( $assignment_id <= 0 || $event_id <= 0 ) {
			return array(
				'success'  => false,
				'message'  => 'A valid event and assignment_id are required to release event execution inventory.',
				'event_id' => $event_id,
			);
		}

		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success'  => false,
				'message'  => 'You are not authorized to release this event inventory.',
				'event_id' => $event_id,
			);
		}

		$assignment_event_id = $this->get_assignment_event_id( $assignment_id );
		if ( $assignment_event_id <= 0 || $assignment_event_id !== $event_id ) {
			return array(
				'success'  => false,
				'message'  => 'The submitted assignment does not belong to the selected event.',
				'event_id' => $event_id,
			);
		}

		$current_assignment = $this->load_assignment_from_request(
			array(
				'assignment_id' => $assignment_id,
				'event_id'      => $event_id,
			)
		);

		if ( is_array( $current_assignment ) && AIMS_Event_Bucket_Assignment_Repository::STATUS_RETURNED !== (string) ( $current_assignment['assignment_status'] ?? '' ) ) {
			return array(
				'success'  => false,
				'message'  => 'Event inventory can only be released after it has been marked returned.',
				'event_id' => $event_id,
			);
		}

		$released = $this->assignment_service->release_bucket_from_event(
			$assignment_id,
			array(
				'released_at'       => $request['released_at'] ?? current_time( 'mysql' ),
				'released_by'       => get_current_user_id(),
				'assignment_status' => AIMS_Event_Bucket_Assignment_Repository::STATUS_RELEASED,
				'notes'             => isset( $request['notes'] ) ? wp_kses_post( $request['notes'] ) : '',
			)
		);

		if ( ! $released ) {
			return array(
				'success'  => false,
				'message'  => 'Bucket assignment could not be released.',
				'event_id' => $event_id,
			);
		}

		return array(
			'success'       => true,
			'message'       => 'Bucket released from event execution.',
			'event_id'      => $event_id,
			'assignment_id' => $assignment_id,
		);
	}

	public function release_bucket( array $request ): array {
		$assignment = $this->load_assignment_from_request( $request );
		if ( ! is_array( $assignment ) ) {
			return array(
				'success'  => false,
				'message'  => 'A valid assignment is required to release planning inventory.',
				'event_id' => (int) ( $request['event_id'] ?? 0 ),
			);
		}

		$event_id = (int) ( $assignment['event_id'] ?? 0 );
		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success'  => false,
				'message'  => 'You are not authorized to release buckets for this event.',
				'event_id' => $event_id,
			);
		}

		$status = sanitize_key( (string) ( $assignment['assignment_status'] ?? '' ) );
		if ( AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT === $status ) {
			return array(
				'success'  => false,
				'message'  => 'Event inventory must be marked returned before it can be released.',
				'event_id' => $event_id,
			);
		}

		if ( AIMS_Event_Bucket_Assignment_Repository::STATUS_RETURNED === $status ) {
			return $this->release_after_return( $request );
		}

		$released = $this->assignment_service->release_bucket_from_event(
			(int) ( $assignment['id'] ?? 0 ),
			array(
				'released_at'       => $request['released_at'] ?? current_time( 'mysql' ),
				'released_by'       => get_current_user_id(),
				'assignment_status' => AIMS_Event_Bucket_Assignment_Repository::STATUS_RELEASED,
				'notes'             => isset( $request['notes'] ) ? wp_kses_post( $request['notes'] ) : '',
			)
		);

		if ( ! $released ) {
			return array(
				'success'  => false,
				'message'  => 'Bucket assignment could not be released.',
				'event_id' => $event_id,
			);
		}

		return array(
			'success'       => true,
			'message'       => 'Bucket released from event planning.',
			'event_id'      => $event_id,
			'assignment_id' => (int) ( $assignment['id'] ?? 0 ),
		);
	}

	public function transition_assignment_status( int $assignment_id, string $status, array $data = array() ): bool {
		if ( $assignment_id <= 0 || ! method_exists( $this->assignment_service, 'transition_assignment_status' ) ) {
			return false;
		}

		return (bool) $this->assignment_service->transition_assignment_status( $assignment_id, $status, $data );
	}

	private function transition_assignment_status_from_request( array $request, string $status, string $success_message, string $failure_message ): array {
		$assignment = $this->load_assignment_from_request( $request );
		if ( ! is_array( $assignment ) ) {
			return array(
				'success'  => false,
				'message'  => 'A valid assignment is required to update event execution status.',
				'event_id' => (int) ( $request['event_id'] ?? 0 ),
			);
		}

		$event_id = (int) ( $assignment['event_id'] ?? 0 );
		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success'  => false,
				'message'  => $failure_message,
				'event_id' => $event_id,
			);
		}

		if ( ! $this->transition_assignment_status(
			(int) ( $assignment['id'] ?? 0 ),
			$status,
			array(
				'event_id' => $event_id,
			)
		) ) {
			return array(
				'success'  => false,
				'message'  => 'Event execution status could not be updated.',
				'event_id' => $event_id,
			);
		}

		return array(
			'success'       => true,
			'message'       => $success_message,
			'event_id'      => $event_id,
			'assignment_id' => (int) ( $assignment['id'] ?? 0 ),
		);
	}

	private function load_assignment_from_request( array $request ): ?array {
		$assignment_id = (int) ( $request['assignment_id'] ?? 0 );
		$event_id      = (int) ( $request['event_id'] ?? 0 );

		if ( $assignment_id <= 0 || ! is_object( $this->assignment_repository ) || ! method_exists( $this->assignment_repository, 'find' ) ) {
			return null;
		}

		$assignment = $this->assignment_repository->find( $assignment_id );
		if ( ! is_array( $assignment ) ) {
			return null;
		}

		if ( $event_id > 0 && (int) ( $assignment['event_id'] ?? 0 ) !== $event_id ) {
			return null;
		}

		return $assignment;
	}

	private function get_authorized_event_ids_for_current_user(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id <= 0 || ! is_object( $this->access_service ) ) {
			return array();
		}

		foreach ( array( 'get_authorized_event_ids', 'get_authorized_events' ) as $method ) {
			if ( ! method_exists( $this->access_service, $method ) ) {
				continue;
			}

			$result = $this->access_service->{$method}( $user_id );

			if ( ! is_array( $result ) ) {
				continue;
			}

			$event_ids = array();
			foreach ( $result as $item ) {
				if ( is_array( $item ) ) {
					$event_ids[] = (int) ( $item['id'] ?? 0 );
					continue;
				}

				$event_ids[] = (int) $item;
			}

			return array_values( array_filter( array_unique( $event_ids ) ) );
		}

		return array();
	}

	private function get_assignment_event_id( int $assignment_id ): int {
		if ( $assignment_id <= 0 || ! is_object( $this->assignment_repository ) || ! method_exists( $this->assignment_repository, 'find' ) ) {
			return 0;
		}

		$assignment = $this->assignment_repository->find( $assignment_id );
		if ( ! is_array( $assignment ) ) {
			return 0;
		}

		return (int) ( $assignment['event_id'] ?? 0 );
	}

	private function execute_vendor_event_check_in( array $request, array $assignment ) {
		if ( ! is_object( $this->execution_service ) || ! method_exists( $this->execution_service, 'vendor_event_checkin' ) ) {
			return new WP_Error( 'aims_missing_event_execution_service', 'Event execution service is not available.' );
		}

		return $this->execution_service->vendor_event_checkin(
			array(
				'assignment_id' => (int) ( $assignment['id'] ?? 0 ),
				'reference_id'  => isset( $request['reference_id'] ) ? sanitize_text_field( (string) $request['reference_id'] ) : '',
				'applied_by'    => get_current_user_id(),
				'note'          => isset( $request['note'] ) ? sanitize_textarea_field( (string) $request['note'] ) : ( isset( $request['notes'] ) ? sanitize_textarea_field( (string) $request['notes'] ) : '' ),
			)
		);
	}

	private function execute_event_return( array $request, array $assignment ) {
		if ( ! is_object( $this->execution_service ) || ! method_exists( $this->execution_service, 'event_return' ) ) {
			return new WP_Error( 'aims_missing_event_execution_service', 'Event execution service is not available.' );
		}

		return $this->execution_service->event_return(
			array(
				'assignment_id' => (int) ( $assignment['id'] ?? 0 ),
				'reference_id'  => isset( $request['reference_id'] ) ? sanitize_text_field( (string) $request['reference_id'] ) : '',
				'applied_by'    => get_current_user_id(),
				'note'          => isset( $request['note'] ) ? sanitize_textarea_field( (string) $request['note'] ) : ( isset( $request['notes'] ) ? sanitize_textarea_field( (string) $request['notes'] ) : '' ),
			)
		);
	}
}
