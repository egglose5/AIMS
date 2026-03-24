<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Bucket_Assignment_Service {
	private $assignments;

	public function __construct( $assignments ) {
		$this->assignments = $assignments;
	}

	public function assign_bucket_to_event( array $data ): int {
		if ( ! method_exists( $this->assignments, 'save' ) ) {
			return 0;
		}

		$record = array(
			'event_id'           => (int) ( $data['event_id'] ?? 0 ),
			'physical_bucket_id' => (int) ( $data['physical_bucket_id'] ?? $data['bucket_id'] ?? 0 ),
			'assignment_status'  => sanitize_key( $data['assignment_status'] ?? AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT ),
			'assignment_type'    => sanitize_key( $data['assignment_type'] ?? AIMS_Event_Bucket_Assignment_Repository::TYPE_EVENT_STOCK ),
			'assigned_at'        => $data['assigned_at'] ?? current_time( 'mysql' ),
			'assigned_by'        => (int) ( $data['assigned_by'] ?? get_current_user_id() ),
			'display_order'      => (int) ( $data['display_order'] ?? 0 ),
			'is_active'          => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
		);

		return (int) $this->assignments->save( $record, (int) ( $data['id'] ?? 0 ) );
	}

	public function release_bucket_from_event( int $assignment_id, array $data = array() ): bool {
		if ( $assignment_id <= 0 ) {
			return false;
		}

		$payload = array(
			'assignment_status' => sanitize_key( $data['assignment_status'] ?? 'released' ),
			'released_at'       => $data['released_at'] ?? current_time( 'mysql' ),
			'released_by'       => (int) ( $data['released_by'] ?? get_current_user_id() ),
			'is_active'         => 0,
			'notes'             => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
		);

		if ( method_exists( $this->assignments, 'release' ) ) {
			return (bool) $this->assignments->release( $assignment_id, $payload );
		}

		if ( ! method_exists( $this->assignments, 'find' ) || ! method_exists( $this->assignments, 'save' ) ) {
			return false;
		}

		$current = $this->assignments->find( $assignment_id );
		if ( ! is_array( $current ) ) {
			return false;
		}

		$this->assignments->save( array_merge( $current, $payload ), $assignment_id );

		return true;
	}

	public function get_active_buckets_for_event( int $event_id ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		if ( method_exists( $this->assignments, 'get_active_for_event' ) ) {
			return (array) $this->assignments->get_active_for_event( $event_id );
		}

		if ( method_exists( $this->assignments, 'get_for_event' ) ) {
			return array_values(
				array_filter(
					(array) $this->assignments->get_for_event( $event_id ),
					static function ( $assignment ) {
						return is_array( $assignment ) && ! empty( $assignment['is_active'] );
					}
				)
			);
		}

		return array();
	}

	public function get_active_event_for_bucket( int $bucket_id ): ?array {
		if ( $bucket_id <= 0 || ! method_exists( $this->assignments, 'get_active_for_bucket' ) ) {
			return null;
		}

		$assignment = $this->assignments->get_active_for_bucket( $bucket_id );

		return is_array( $assignment ) ? $assignment : null;
	}

	public function transition_assignment_status( int $assignment_id, string $status, array $data = array() ): bool {
		if ( $assignment_id <= 0 || ! method_exists( $this->assignments, 'find' ) || ! method_exists( $this->assignments, 'save' ) ) {
			return false;
		}

		$current = $this->assignments->find( $assignment_id );
		if ( ! is_array( $current ) ) {
			return false;
		}

		$record = array_merge(
			$current,
			$data,
			array(
				'assignment_status' => $this->normalize_transition_status( $status ),
			)
		);

		$this->assignments->save( $record, $assignment_id );

		return true;
	}

	private function normalize_transition_status( string $status ): string {
		$status = sanitize_key( $status );
		$allowed = array(
			AIMS_Event_Bucket_Assignment_Repository::STATUS_ASSIGNED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_STAGED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_RETURNED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_RELEASED,
			AIMS_Event_Bucket_Assignment_Repository::STATUS_CANCELLED,
		);

		return in_array( $status, $allowed, true ) ? $status : AIMS_Event_Bucket_Assignment_Repository::STATUS_ASSIGNED;
	}
}
