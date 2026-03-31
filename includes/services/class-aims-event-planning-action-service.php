<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Action_Service {
	private $assignment_service;
	private $access_service;
	private $assignment_repository;
	private $execution_service;
	private $responsibility_auth;

	public function __construct(
		AIMS_Event_Bucket_Assignment_Service $assignment_service = null,
		$access_service = null,
		AIMS_Event_Bucket_Assignment_Repository $assignment_repository = null,
		AIMS_Event_Execution_Service $execution_service = null,
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null
	) {
		$this->assignment_service    = $assignment_service ?: new AIMS_Event_Bucket_Assignment_Service(
			new AIMS_Event_Bucket_Assignment_Repository()
		);
		$this->access_service        = $access_service;
		$this->assignment_repository = $assignment_repository ?: new AIMS_Event_Bucket_Assignment_Repository();
		$this->execution_service     = $execution_service ?: new AIMS_Event_Execution_Service();
		$this->responsibility_auth   = $responsibility_auth ?: new AIMS_Responsibility_Authorization_Service();
	}

	public function can_current_user_manage_planning(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id <= 0 ) {
			return false;
		}

		return is_object( $this->responsibility_auth ) && $this->responsibility_auth->can_manage_event_planning( $user_id );
	}

	public function can_current_user_mutate_event( int $event_id ): bool {
		$event_id = max( 0, $event_id );
		$user_id  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $event_id <= 0 || ! $this->can_current_user_manage_planning() ) {
			return false;
		}

		return is_object( $this->responsibility_auth ) && $this->responsibility_auth->can_mutate_event( $user_id, $event_id );
	}

	public function assign_bucket( array $request ): array {
		$event_id          = (int) ( $request['event_id'] ?? 0 );
		$bucket_id         = (int) ( $request['physical_bucket_id'] ?? $request['bucket_id'] ?? 0 );
		$assignment_type   = sanitize_key( $request['assignment_type'] ?? AIMS_Event_Bucket_Assignment_Repository::TYPE_EVENT_STOCK );
		$assignment_status = sanitize_key( $request['assignment_status'] ?? AIMS_Event_Bucket_Assignment_Repository::STATUS_STAGED );

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
				'assignment_status'  => '' !== $assignment_status ? $assignment_status : AIMS_Event_Bucket_Assignment_Repository::STATUS_STAGED,
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

	public function assign_buckets_bulk( array $request ): array {
		$event_id          = (int) ( $request['event_id'] ?? 0 );
		$delegated_to_user_id = (int) ( $request['delegated_to_user_id'] ?? 0 );
		$bucket_ids        = array_values(
			array_unique(
				array_filter(
					array_map(
						'intval',
						(array) ( $request['physical_bucket_ids'] ?? array() )
					)
				)
			)
		);

		if ( $event_id <= 0 || empty( $bucket_ids ) ) {
			return array(
				'success'  => false,
				'message'  => 'A valid event and at least one bucket are required for bulk assignment.',
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

		if ( $delegated_to_user_id > 0 && ! $this->can_delegate_to_user( $delegated_to_user_id ) ) {
			return array(
				'success'  => false,
				'message'  => 'Delegation target must have event planning access.',
				'event_id' => $event_id,
			);
		}

		$assigned_count = 0;
		$failed_count   = 0;
		$assignment_ids = array();

		foreach ( $bucket_ids as $bucket_id ) {
			$result = $this->assign_bucket(
				array(
					'event_id'           => $event_id,
					'physical_bucket_id' => $bucket_id,
					'notes'              => $this->merge_notes_with_delegation( (string) ( $request['notes'] ?? '' ), $delegated_to_user_id ),
				)
			);

			if ( ! empty( $result['success'] ) ) {
				++$assigned_count;
				$assignment_ids[] = (int) ( $result['assignment_id'] ?? 0 );
				continue;
			}

			++$failed_count;
		}

		if ( $assigned_count <= 0 ) {
			return array(
				'success'  => false,
				'message'  => 'Bulk assignment failed for all selected buckets.',
				'event_id' => $event_id,
			);
		}

		$message = sprintf( 'Assigned %d bucket(s).', $assigned_count );
		if ( $failed_count > 0 ) {
			$message .= ' ' . sprintf( '%d bucket(s) were skipped or failed.', $failed_count );
		}

		return array(
			'success'        => true,
			'message'        => $message,
			'event_id'       => $event_id,
			'assigned_count' => $assigned_count,
			'failed_count'   => $failed_count,
			'assignment_ids' => array_values( array_filter( $assignment_ids ) ),
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

	public function release_buckets_bulk( array $request ): array {
		$event_id = (int) ( $request['event_id'] ?? 0 );
		$assignment_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						'intval',
						(array) ( $request['assignment_ids'] ?? array() )
					)
				)
			)
		);

		if ( $event_id <= 0 || empty( $assignment_ids ) ) {
			return array(
				'success'  => false,
				'message'  => 'A valid event and at least one assignment are required for bulk release.',
				'event_id' => $event_id,
			);
		}

		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success'  => false,
				'message'  => 'You are not authorized to release buckets for this event.',
				'event_id' => $event_id,
			);
		}

		$released_count = 0;
		$failed_count   = 0;

		foreach ( $assignment_ids as $assignment_id ) {
			$result = $this->release_bucket(
				array(
					'event_id'      => $event_id,
					'assignment_id' => $assignment_id,
				)
			);

			if ( ! empty( $result['success'] ) ) {
				++$released_count;
				continue;
			}

			++$failed_count;
		}

		if ( $released_count <= 0 ) {
			return array(
				'success'  => false,
				'message'  => 'Bulk release failed for all selected assignments.',
				'event_id' => $event_id,
			);
		}

		$message = sprintf( 'Released %d assignment(s).', $released_count );
		if ( $failed_count > 0 ) {
			$message .= ' ' . sprintf( '%d assignment(s) were skipped or failed.', $failed_count );
		}

		return array(
			'success'        => true,
			'message'        => $message,
			'event_id'       => $event_id,
			'released_count' => $released_count,
			'failed_count'   => $failed_count,
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

	private function can_delegate_to_user( int $delegated_to_user_id ): bool {
		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $delegated_to_user_id <= 0 || $current_user_id <= 0 ) {
			return false;
		}

		if ( ! is_object( $this->responsibility_auth ) ) {
			return false;
		}

		return $this->responsibility_auth->can_manage_event_planning( $delegated_to_user_id );
	}

	private function merge_notes_with_delegation( string $notes, int $delegated_to_user_id ): string {
		$notes = trim( $notes );

		if ( $delegated_to_user_id <= 0 ) {
			return $notes;
		}

		$delegation_note = sprintf( 'Delegated to user_id:%d', $delegated_to_user_id );

		if ( '' === $notes ) {
			return $delegation_note;
		}

		return $notes . ' | ' . $delegation_note;
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
