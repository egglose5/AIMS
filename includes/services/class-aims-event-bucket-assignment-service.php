<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Bucket_Assignment_Service {
	private $assignments;
	private $vendor_event_assignments;
	private $physical_buckets;

	public function __construct( $assignments, $vendor_event_assignments = null, $physical_buckets = null ) {
		$this->assignments = $assignments;
		$this->vendor_event_assignments = $vendor_event_assignments ?: ( class_exists( 'AIMS_Vendor_Event_Assignment_Repository' ) ? new AIMS_Vendor_Event_Assignment_Repository() : null );
		$this->physical_buckets = $physical_buckets ?: ( class_exists( 'AIMS_Physical_Bucket_Repository' ) ? new AIMS_Physical_Bucket_Repository() : null );
	}

	public function assign_bucket_to_event( array $data ): int {
		if ( ! method_exists( $this->assignments, 'save' ) ) {
			return 0;
		}

		$event_id  = (int) ( $data['event_id'] ?? 0 );
		$bucket_id = (int) ( $data['physical_bucket_id'] ?? $data['bucket_id'] ?? 0 );
		if ( ! $this->is_bucket_vendor_allowed_for_event( $event_id, $bucket_id ) ) {
			return 0;
		}

		$record = array(
			'event_id'           => $event_id,
			'physical_bucket_id' => $bucket_id,
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

	private function is_bucket_vendor_allowed_for_event( int $event_id, int $bucket_id ): bool {
		if ( $event_id <= 0 || $bucket_id <= 0 ) {
			return false;
		}

		$event_vendor_ids = $this->get_event_vendor_ids( $event_id );
		if ( empty( $event_vendor_ids ) ) {
			return true;
		}

		$bucket_vendor_id = $this->get_bucket_vendor_id( $bucket_id );
		if ( $bucket_vendor_id <= 0 ) {
			// Ambiguous attribution is only blocked when multiple vendors exist on the event.
			return count( $event_vendor_ids ) <= 1;
		}

		return in_array( $bucket_vendor_id, $event_vendor_ids, true );
	}

	private function get_event_vendor_ids( int $event_id ): array {
		if ( $event_id <= 0 || ! is_object( $this->vendor_event_assignments ) || ! method_exists( $this->vendor_event_assignments, 'get_for_event' ) ) {
			return array();
		}

		$vendor_ids = array();
		foreach ( (array) $this->vendor_event_assignments->get_for_event( $event_id ) as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			$vendor_id = (int) ( $assignment['vendor_id'] ?? 0 );
			if ( $vendor_id > 0 ) {
				$vendor_ids[] = $vendor_id;
			}
		}

		return array_values( array_unique( $vendor_ids ) );
	}

	private function get_bucket_vendor_id( int $bucket_id ): int {
		if ( $bucket_id <= 0 || ! is_object( $this->physical_buckets ) || ! method_exists( $this->physical_buckets, 'find' ) ) {
			return 0;
		}

		$bucket = $this->physical_buckets->find( $bucket_id );
		if ( ! is_array( $bucket ) ) {
			return 0;
		}

		return (int) ( $bucket['vendor_id'] ?? 0 );
	}
}
