<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Action_Service {
	private $assignment_service;
	private $access_service;
	private $assignment_repository;

	public function __construct(
		AIMS_Event_Bucket_Assignment_Service $assignment_service = null,
		$access_service = null,
		AIMS_Event_Bucket_Assignment_Repository $assignment_repository = null
	) {
		$this->assignment_service = $assignment_service ?: new AIMS_Event_Bucket_Assignment_Service(
			new AIMS_Event_Bucket_Assignment_Repository()
		);
		$this->access_service     = $access_service ?: ( class_exists( 'AIMS_Event_Planning_Access_Service' ) ? new AIMS_Event_Planning_Access_Service() : null );
		$this->assignment_repository = $assignment_repository ?: new AIMS_Event_Bucket_Assignment_Repository();
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
		$event_id     = (int) ( $request['event_id'] ?? 0 );
		$bucket_id    = (int) ( $request['physical_bucket_id'] ?? $request['bucket_id'] ?? 0 );
		$assignment_type = sanitize_key( $request['assignment_type'] ?? AIMS_Event_Bucket_Assignment_Repository::TYPE_EVENT_STOCK );
		$assignment_status = sanitize_key( $request['assignment_status'] ?? AIMS_Event_Bucket_Assignment_Repository::STATUS_ASSIGNED );

		if ( $event_id <= 0 || $bucket_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'A valid event and bucket are required to assign planning inventory.',
				'event_id' => $event_id,
			);
		}

		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success' => false,
				'message' => 'You are not authorized to assign buckets to this event.',
				'event_id' => $event_id,
			);
		}

		$assignment_id = (int) $this->assignment_service->assign_bucket_to_event(
			array(
				'event_id'           => $event_id,
				'physical_bucket_id' => $bucket_id,
				'assignment_type'    => '' !== $assignment_type ? $assignment_type : AIMS_Event_Bucket_Assignment_Repository::TYPE_EVENT_STOCK,
				'assignment_status'  => '' !== $assignment_status ? $assignment_status : AIMS_Event_Bucket_Assignment_Repository::STATUS_ASSIGNED,
				'assigned_at'        => $request['assigned_at'] ?? current_time( 'mysql' ),
				'assigned_by'        => get_current_user_id(),
				'display_order'      => (int) ( $request['display_order'] ?? 0 ),
				'is_active'          => 1,
				'notes'              => isset( $request['notes'] ) ? wp_kses_post( $request['notes'] ) : '',
			)
		);

		if ( $assignment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'Bucket assignment could not be saved.',
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

	public function release_bucket( array $request ): array {
		$assignment_id = (int) ( $request['assignment_id'] ?? 0 );
		$event_id      = (int) ( $request['event_id'] ?? 0 );

		if ( $assignment_id <= 0 || $event_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'A valid event and assignment_id are required to release planning inventory.',
				'event_id' => $event_id,
			);
		}

		if ( ! $this->can_current_user_mutate_event( $event_id ) ) {
			return array(
				'success' => false,
				'message' => 'You are not authorized to release buckets for this event.',
				'event_id' => $event_id,
			);
		}

		$assignment_event_id = $this->get_assignment_event_id( $assignment_id );
		if ( $assignment_event_id <= 0 || $assignment_event_id !== $event_id ) {
			return array(
				'success' => false,
				'message' => 'The submitted assignment does not belong to the selected event.',
				'event_id' => $event_id,
			);
		}

		$released = $this->assignment_service->release_bucket_from_event(
			$assignment_id,
			array(
				'released_at' => $request['released_at'] ?? current_time( 'mysql' ),
				'released_by' => get_current_user_id(),
				'notes'       => isset( $request['notes'] ) ? wp_kses_post( $request['notes'] ) : '',
			)
		);

		if ( ! $released ) {
			return array(
				'success' => false,
				'message' => 'Bucket assignment could not be released.',
				'event_id' => $event_id,
			);
		}

		return array(
			'success'       => true,
			'message'       => 'Bucket released from event planning.',
			'event_id'      => $event_id,
			'assignment_id' => $assignment_id,
		);
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
}
