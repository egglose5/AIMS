<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Event_Checkin_Portal_Service {
	private const CHECKIN_WINDOW_DAYS = 3;

	private $access_service;
	private $events;
	private $vendor_event_assignments;
	private $bucket_assignments;
	private $checkins;
	private $checkin_media;
	private $execution_service;
	private $public_projection;
	private $uploader;

	public function __construct(
		AIMS_Event_Planning_Access_Service $access_service = null,
		AIMS_Event_Repository $events = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		AIMS_Event_Bucket_Assignment_Repository $bucket_assignments = null,
		AIMS_Vendor_Event_Checkin_Repository $checkins = null,
		AIMS_Vendor_Event_Checkin_Media_Repository $checkin_media = null,
		AIMS_Event_Execution_Service $execution_service = null,
		AIMS_Public_Event_Projection_Service $public_projection = null,
		$uploader = null
	) {
		$this->access_service           = $access_service ?: new AIMS_Event_Planning_Access_Service();
		$this->events                   = $events ?: new AIMS_Event_Repository();
		$this->vendor_event_assignments = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->bucket_assignments       = $bucket_assignments ?: new AIMS_Event_Bucket_Assignment_Repository();
		$this->checkins                 = $checkins ?: new AIMS_Vendor_Event_Checkin_Repository();
		$this->checkin_media            = $checkin_media ?: new AIMS_Vendor_Event_Checkin_Media_Repository();
		$this->execution_service        = $execution_service ?: new AIMS_Event_Execution_Service();
		$this->public_projection        = $public_projection ?: new AIMS_Public_Event_Projection_Service(
			new AIMS_Event_Repository(),
			new AIMS_Public_Event_Update_Repository()
		);
		$this->uploader                 = $uploader;
	}

	public function get_page_model( array $request = array() ): array {
		$user_id             = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$vendor_ids          = $this->get_authorized_vendor_ids( $user_id );
		$all_events          = $this->get_authorized_events_for_vendor_ids( $vendor_ids );
		$selected_event_id   = $this->resolve_event_id( $request );
		$selected_event      = $this->find_event_by_id( $selected_event_id, $all_events );
		$selected_assignment = $this->resolve_vendor_event_assignment( $selected_event_id, $vendor_ids );
		$bucket_options      = $this->get_bucket_options_for_event( $selected_event_id );
		$recent_updates      = $this->get_recent_public_updates( $selected_event_id );
		$is_first_checkin    = ! empty( $selected_event ) && ! empty( $selected_assignment )
			? $this->checkins->is_first_checkin( $selected_event_id, (int) ( $selected_assignment['vendor_id'] ?? 0 ) )
			: false;

		return array(
			'logged_in'                         => $user_id > 0,
			'authorized_vendor_ids'            => $vendor_ids,
			'authorized_events'                => $all_events,
			'selected_event_id'                => $selected_event_id,
			'selected_event'                   => $selected_event,
			'selected_vendor_event_assignment' => $selected_assignment,
			'bucket_options'                   => $bucket_options,
			'recent_updates'                   => $recent_updates,
			'can_submit'                       => ! empty( $selected_event ) && ! empty( $selected_assignment ) && ! empty( $bucket_options ),
			'is_first_checkin'                 => $is_first_checkin,
			'status_message'                   => $this->get_status_message( $request ),
			'return_url'                       => $this->get_current_url(),
			'login_url'                        => function_exists( 'wp_login_url' ) ? wp_login_url( $this->get_current_url() ) : '',
		);
	}

	public function submit_checkin( array $request, array $files = array() ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return $this->failure_response( 'You must be logged in to submit vendor check-ins.' );
		}

		$event_id             = (int) ( $request['event_id'] ?? 0 );
		$bucket_assignment_id = (int) ( $request['bucket_assignment_id'] ?? 0 );
		$vendor_ids           = $this->get_authorized_vendor_ids( $user_id );
		$vendor_assignment    = $this->resolve_vendor_event_assignment( $event_id, $vendor_ids );
		$event                = $this->find_event_by_id( $event_id );
		$vendor_id            = (int) ( $vendor_assignment['vendor_id'] ?? 0 );
		$is_first_checkin     = $event_id > 0 && $vendor_id > 0 ? $this->checkins->is_first_checkin( $event_id, $vendor_id ) : false;
		$bucket_assignment    = $this->resolve_bucket_assignment( $event_id, $bucket_assignment_id );
		$checkin_notes        = sanitize_textarea_field( (string) ( $request['checkin_notes'] ?? '' ) );
		$location_notes       = sanitize_textarea_field( (string) ( $request['location_notes'] ?? '' ) );
		$combined_notes       = $this->build_combined_notes( $checkin_notes, $location_notes );

		if ( $event_id <= 0 || empty( $event ) ) {
			return $this->failure_response( 'A valid event is required for vendor check-in.' );
		}

		if ( ! $this->is_event_available_now( $event ) ) {
			return $this->failure_response( 'Vendor check-in is available starting three days before event start.' );
		}

		if ( empty( $vendor_assignment ) ) {
			return $this->failure_response( 'This account is not assigned to the selected event.' );
		}

		if ( $is_first_checkin && empty( $bucket_assignment ) ) {
			return $this->failure_response( 'Select an assigned event bucket before the first vendor check-in.' );
		}

		$selfie_upload = $this->upload_file_from_request( $files, 'selfie_photo' );
		if ( is_wp_error( $selfie_upload ) ) {
			return $this->failure_response( $selfie_upload->get_error_message() );
		}

		$booth_uploads = $this->upload_multiple_files_from_request( $files, 'booth_setup_photos' );
		foreach ( $booth_uploads as $upload_result ) {
			if ( is_wp_error( $upload_result ) ) {
				return $this->failure_response( $upload_result->get_error_message() );
			}
		}

		if ( empty( $booth_uploads ) ) {
			return $this->failure_response( 'At least one booth/setup image is required.' );
		}

		$checkin_id = 0;
		$media_ids  = array();

		if ( $is_first_checkin ) {
			$checkin_id = (int) $this->checkins->save(
				array(
					'event_id'                   => $event_id,
					'vendor_id'                  => $vendor_id,
					'vendor_event_assignment_id' => (int) ( $vendor_assignment['id'] ?? 0 ),
					'physical_bucket_id'         => (int) ( $bucket_assignment['physical_bucket_id'] ?? 0 ),
					'checkin_source'             => 'vendor_portal',
					'checkin_status'             => AIMS_Vendor_Event_Checkin_Repository::STATUS_RECORDED,
					'visibility_status'          => AIMS_Vendor_Event_Checkin_Repository::VISIBILITY_INTERNAL,
					'is_first_checkin'           => 1,
					'movement_applied'           => 0,
					'checkin_notes'              => $combined_notes,
					'checkin_comment'            => $location_notes,
					'mobile_photo_reference'     => (string) ( $selfie_upload['file_url'] ?? '' ),
					'checked_in_by'              => $user_id,
					'checked_in_at'              => current_time( 'mysql' ),
				)
			);

			if ( $checkin_id <= 0 ) {
				return $this->failure_response( 'Vendor check-in could not be saved.' );
			}
		}

		$media_ids = $this->save_media_records(
			$this->build_media_records(
				$checkin_id,
				$event_id,
				$vendor_id,
				$selfie_upload,
				$booth_uploads,
				$combined_notes,
				$is_first_checkin
			)
		);

		if ( $is_first_checkin ) {
			$movement_result = $this->execution_service->vendor_event_checkin(
				array(
					'assignment_id' => (int) ( $bucket_assignment['id'] ?? 0 ),
					'reference_id'  => 'vendor-checkin-' . $checkin_id,
					'applied_by'    => $user_id,
					'note'          => $combined_notes,
				)
			);

			if ( is_wp_error( $movement_result ) || empty( $movement_result['success'] ) ) {
				return $this->failure_response(
					is_wp_error( $movement_result ) ? $movement_result->get_error_message() : (string) ( $movement_result['message'] ?? 'Vendor check-in could not be recorded.' ),
					array(
						'checkin_id' => $checkin_id,
						'event_id'   => $event_id,
					)
				);
			}

			$this->checkins->mark_movement_applied(
				$checkin_id,
				array(
					'movement_reference_type' => 'vendor_event_checkin',
					'movement_reference_id'   => 'vendor-checkin-' . $checkin_id,
					'checkin_status'          => AIMS_Vendor_Event_Checkin_Repository::STATUS_COMPLETED,
				)
			);
		}

		$public_update = $this->save_public_update(
			$event,
			$vendor_assignment,
			$checkin_id,
			$combined_notes,
			$location_notes,
			$selfie_upload,
			$booth_uploads,
			$is_first_checkin
		);

		if ( empty( $public_update['success'] ) || empty( $public_update['update_id'] ) ) {
			return array(
				'success'    => true,
				'message'    => $is_first_checkin
					? 'Vendor check-in recorded, but the public event update could not be published.'
					: 'Vendor update saved internally, but the public event update could not be published.',
				'checkin_id' => $checkin_id,
				'event_id'   => $event_id,
				'update_id'  => 0,
			);
		}

		$public_update_id = (int) $public_update['update_id'];
		$this->link_media_to_public_update( $media_ids, $public_update_id );

		if ( $is_first_checkin && $checkin_id > 0 ) {
			$this->checkins->mark_public_update_created( $checkin_id, $public_update_id );
		}

		return array(
			'success'          => true,
			'message'          => $is_first_checkin ? 'Vendor check-in recorded and live update published.' : 'Vendor update published.',
			'checkin_id'       => $checkin_id,
			'event_id'         => $event_id,
			'update_id'        => $public_update_id,
			'is_first_checkin' => $is_first_checkin,
		);
	}

	public function get_current_user_event_checkins( int $event_id ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || $event_id <= 0 ) {
			return array();
		}

		$vendor_ids = $this->get_authorized_vendor_ids( $user_id );
		if ( empty( $vendor_ids ) ) {
			return array();
		}

		$checkins = array();
		foreach ( $vendor_ids as $vendor_id ) {
			foreach ( (array) $this->checkins->get_for_event_vendor( $event_id, (int) $vendor_id ) as $row ) {
				if ( is_array( $row ) ) {
					$checkins[] = $row;
				}
			}
		}

		return $checkins;
	}

	private function get_authorized_vendor_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( is_object( $this->access_service ) && method_exists( $this->access_service, 'get_authorized_vendor_ids' ) ) {
			return array_values( array_filter( array_map( 'intval', (array) $this->access_service->get_authorized_vendor_ids( $user_id ) ) ) );
		}

		return array();
	}

	private function get_authorized_events_for_vendor_ids( array $vendor_ids ): array {
		$vendor_ids = array_values( array_filter( array_unique( array_map( 'intval', $vendor_ids ) ) ) );
		if ( empty( $vendor_ids ) ) {
			return array();
		}

		$events = array();
		foreach ( (array) $this->events->all() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_id = (int) ( $event['id'] ?? 0 );
			if ( $event_id <= 0 || ! $this->is_event_available_now( $event ) ) {
				continue;
			}

			if ( ! $this->event_is_assigned_to_vendor_ids( $event_id, $vendor_ids ) ) {
				continue;
			}

			$events[] = $this->build_event_row( $event, $vendor_ids );
		}

		usort(
			$events,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['start_date'] ?? '' ), (string) ( $right['start_date'] ?? '' ) );
			}
		);

		return $events;
	}

	private function get_recent_public_updates( int $event_id ): array {
		if ( $event_id <= 0 || ! is_object( $this->public_projection ) || ! method_exists( $this->public_projection, 'get_public_event_updates' ) ) {
			return array();
		}

		return (array) $this->public_projection->get_public_event_updates(
			$event_id,
			array(
				'limit' => 5,
			)
		);
	}

	private function event_is_assigned_to_vendor_ids( int $event_id, array $vendor_ids ): bool {
		if ( $event_id <= 0 || empty( $vendor_ids ) || ! method_exists( $this->vendor_event_assignments, 'get_for_event' ) ) {
			return false;
		}

		foreach ( (array) $this->vendor_event_assignments->get_for_event( $event_id ) as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			if ( in_array( (int) ( $assignment['vendor_id'] ?? 0 ), $vendor_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function build_event_row( array $event, array $vendor_ids ): array {
		$assignment = $this->resolve_vendor_event_assignment( (int) ( $event['id'] ?? 0 ), $vendor_ids );

		return array(
			'id'                         => (int) ( $event['id'] ?? 0 ),
			'event_name'                 => (string) ( $event['event_name'] ?? '' ),
			'start_date'                 => (string) ( $event['start_date'] ?? '' ),
			'end_date'                   => (string) ( $event['end_date'] ?? '' ),
			'location_name'              => (string) ( $event['location_name'] ?? '' ),
			'status'                     => (string) ( $event['status'] ?? '' ),
			'date_range_label'           => $this->build_date_range_label( (string) ( $event['start_date'] ?? '' ), (string) ( $event['end_date'] ?? '' ) ),
			'vendor_event_assignment_id' => (int) ( $assignment['id'] ?? 0 ),
			'vendor_id'                  => (int) ( $assignment['vendor_id'] ?? 0 ),
		);
	}

	private function resolve_vendor_event_assignment( int $event_id, array $vendor_ids ): array {
		if ( $event_id <= 0 || empty( $vendor_ids ) || ! method_exists( $this->vendor_event_assignments, 'get_for_event' ) ) {
			return array();
		}

		foreach ( (array) $this->vendor_event_assignments->get_for_event( $event_id ) as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			if ( in_array( (int) ( $assignment['vendor_id'] ?? 0 ), $vendor_ids, true ) ) {
				return $assignment;
			}
		}

		return array();
	}

	private function resolve_bucket_assignment( int $event_id, int $bucket_assignment_id ): array {
		if ( $event_id <= 0 || ! method_exists( $this->bucket_assignments, 'get_active_for_event' ) ) {
			return array();
		}

		foreach ( (array) $this->bucket_assignments->get_active_for_event( $event_id ) as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			if ( $bucket_assignment_id <= 0 || (int) ( $assignment['id'] ?? 0 ) === $bucket_assignment_id ) {
				return $assignment;
			}
		}

		return array();
	}

	private function get_bucket_options_for_event( int $event_id ): array {
		$options = array();

		foreach ( (array) $this->bucket_assignments->get_active_for_event( $event_id ) as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			$bucket_id = (int) ( $assignment['physical_bucket_id'] ?? 0 );
			$bucket    = $this->find_physical_bucket( $bucket_id );

			$options[] = array(
				'assignment_id'      => (int) ( $assignment['id'] ?? 0 ),
				'physical_bucket_id' => $bucket_id,
				'bucket_label'       => $this->build_bucket_label( $bucket ),
				'assignment_status'  => (string) ( $assignment['assignment_status'] ?? '' ),
				'content_summary'    => $this->build_bucket_content_summary( $bucket_id ),
			);
		}

		return $options;
	}

	private function find_physical_bucket( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! class_exists( 'AIMS_Physical_Bucket_Repository' ) ) {
			return array();
		}

		$repo = new AIMS_Physical_Bucket_Repository();
		if ( method_exists( $repo, 'find' ) ) {
			$bucket = $repo->find( $bucket_id );
			return is_array( $bucket ) ? $bucket : array();
		}

		return array();
	}

	private function build_bucket_label( array $bucket ): string {
		$label = trim( (string) ( $bucket['bucket_label'] ?? '' ) );
		$code  = trim( (string) ( $bucket['bucket_code'] ?? '' ) );

		if ( '' !== $label && '' !== $code ) {
			return $label . ' (' . $code . ')';
		}

		return '' !== $label ? $label : $code;
	}

	private function build_bucket_content_summary( int $bucket_id ): string {
		if ( $bucket_id <= 0 || ! class_exists( 'AIMS_Bucket_Inventory_Position_Repository' ) ) {
			return '';
		}

		$repo     = new AIMS_Bucket_Inventory_Position_Repository();
		$summary  = method_exists( $repo, 'get_bucket_contents_summary' ) ? $repo->get_bucket_contents_summary( $bucket_id ) : array();
		$line_cnt = (int) count( $summary );

		return sprintf( '%d line%s', $line_cnt, 1 === $line_cnt ? '' : 's' );
	}

	private function find_event_by_id( int $event_id, array $known_events = array() ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		foreach ( $known_events as $event ) {
			if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
				return $event;
			}
		}

		if ( method_exists( $this->events, 'find' ) ) {
			$event = $this->events->find( $event_id );
			if ( is_array( $event ) ) {
				return $event;
			}
		}

		foreach ( (array) $this->events->all() as $event ) {
			if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
				return $event;
			}
		}

		return array();
	}

	private function resolve_event_id( array $request ): int {
		return max( 0, (int) ( $request['event_id'] ?? 0 ) );
	}

	private function is_event_available_now( array $event ): bool {
		$start_date = sanitize_text_field( (string) ( $event['start_date'] ?? '' ) );
		if ( '' === $start_date ) {
			return false;
		}

		$window_open = strtotime( $start_date . ' 00:00:00' );
		if ( false === $window_open ) {
			return false;
		}

		$window_open -= self::CHECKIN_WINDOW_DAYS * 24 * 60 * 60;
		$now = strtotime( current_time( 'mysql' ) );

		return false !== $now && $now >= $window_open;
	}

	private function build_date_range_label( string $start_date, string $end_date ): string {
		if ( '' === $start_date ) {
			return '';
		}

		if ( '' !== $end_date && $end_date !== $start_date ) {
			return $start_date . ' to ' . $end_date;
		}

		return $start_date;
	}

	private function build_media_records(
		int $checkin_id,
		int $event_id,
		int $vendor_id,
		array $selfie_upload,
		array $booth_uploads,
		string $combined_notes,
		bool $is_first_checkin
	): array {
		$records    = array();
		$visibility = $is_first_checkin ? AIMS_Vendor_Event_Checkin_Repository::VISIBILITY_INTERNAL : AIMS_Vendor_Event_Checkin_Repository::VISIBILITY_PUBLIC;

		if ( ! empty( $selfie_upload['file_url'] ) ) {
			$records[] = array(
				'checkin_id'        => $checkin_id,
				'event_id'          => $event_id,
				'vendor_id'         => $vendor_id,
				'media_type'        => 'photo',
				'media_source'      => 'mobile_upload',
				'media_reference'   => 'selfie',
				'media_url'         => (string) $selfie_upload['file_url'],
				'attachment_id'     => (int) ( $selfie_upload['attachment_id'] ?? 0 ),
				'caption'           => $combined_notes,
				'alt_text'          => 'Vendor check-in selfie',
				'visibility_status' => $visibility,
				'is_primary'        => empty( $booth_uploads ) ? 1 : 0,
				'sort_order'        => 0,
				'uploaded_at'       => current_time( 'mysql' ),
			);
		}

		$sort_order = 1;
		foreach ( $booth_uploads as $index => $upload ) {
			if ( empty( $upload['file_url'] ) ) {
				continue;
			}

			$records[] = array(
				'checkin_id'        => $checkin_id,
				'event_id'          => $event_id,
				'vendor_id'         => $vendor_id,
				'media_type'        => 'photo',
				'media_source'      => 'mobile_upload',
				'media_reference'   => 'booth_setup_' . ( $index + 1 ),
				'media_url'         => (string) $upload['file_url'],
				'attachment_id'     => (int) ( $upload['attachment_id'] ?? 0 ),
				'caption'           => $combined_notes,
				'alt_text'          => 'Vendor booth/setup image',
				'visibility_status' => $visibility,
				'is_primary'        => 0 === $index ? 1 : 0,
				'sort_order'        => $sort_order,
				'uploaded_at'       => current_time( 'mysql' ),
			);
			++$sort_order;
		}

		return $records;
	}

	private function save_media_records( array $records ): array {
		$media_ids = array();

		foreach ( $records as $record ) {
			$media_id = (int) $this->checkin_media->save( $record );
			if ( $media_id > 0 ) {
				$media_ids[] = $media_id;
			}
		}

		return $media_ids;
	}

	private function save_public_update(
		array $event,
		array $vendor_assignment,
		int $checkin_id,
		string $combined_notes,
		string $location_notes,
		array $selfie_upload,
		array $booth_uploads,
		bool $is_first_checkin
	): array {
		if ( ! is_object( $this->public_projection ) || ! method_exists( $this->public_projection, 'save_public_event_update' ) ) {
			return array(
				'success' => false,
			);
		}

		$hero_reference = $this->resolve_public_cover_media( $booth_uploads, $selfie_upload );
		$event_id       = (int) ( $event['id'] ?? 0 );
		$vendor_id      = (int) ( $vendor_assignment['vendor_id'] ?? 0 );
		$slug_suffix    = $checkin_id > 0 ? 'checkin-' . $checkin_id : 'update-' . $vendor_id . '-' . time();
		$headline       = $this->build_public_update_headline( (string) ( $event['event_name'] ?? '' ), $is_first_checkin );
		$body           = '' !== $combined_notes ? $combined_notes : $location_notes;

		return $this->public_projection->save_public_event_update(
			array(
				'event_id'             => $event_id,
				'update_slug'          => sanitize_title( 'event-' . $event_id . '-' . $slug_suffix ),
				'update_type'          => '' !== $hero_reference ? 'photo' : 'note',
				'update_title'         => $headline,
				'update_summary'       => $location_notes,
				'update_body'          => $body,
				'public_status'        => 'published',
				'hero_image_reference' => $hero_reference,
				'source_label'         => $is_first_checkin ? 'Vendor Check-In' : 'Vendor Live Update',
				'source_reference'     => $checkin_id > 0 ? 'vendor-checkin-' . $checkin_id : 'vendor-update-' . $event_id . '-' . $vendor_id . '-' . time(),
				'published_at'         => current_time( 'mysql' ),
			)
		);
	}

	private function link_media_to_public_update( array $media_ids, int $public_update_id ): void {
		if ( $public_update_id <= 0 || empty( $media_ids ) ) {
			return;
		}

		foreach ( $media_ids as $index => $media_id ) {
			$media_id = (int) $media_id;
			if ( $media_id <= 0 ) {
				continue;
			}

			$this->checkin_media->attach_to_public_event_update( $media_id, $public_update_id );
			if ( 0 === $index ) {
				$this->checkin_media->mark_primary( $media_id );
			}
		}
	}

	private function build_public_update_headline( string $event_name, bool $is_first_checkin ): string {
		$event_name = '' !== trim( $event_name ) ? trim( $event_name ) : 'Event';

		return $is_first_checkin ? $event_name . ' check-in update' : $event_name . ' live update';
	}

	private function resolve_public_cover_media( array $booth_uploads, array $selfie_upload ): string {
		foreach ( $booth_uploads as $upload ) {
			if ( ! empty( $upload['file_url'] ) ) {
				return (string) $upload['file_url'];
			}
		}

		return (string) ( $selfie_upload['file_url'] ?? '' );
	}

	private function build_combined_notes( string $checkin_notes, string $location_notes ): string {
		$parts = array_filter(
			array(
				trim( $checkin_notes ),
				trim( $location_notes ),
			),
			static function ( string $part ): bool {
				return '' !== $part;
			}
		);

		return implode( "\n\n", $parts );
	}

	private function failure_response( string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'success' => false,
				'message' => $message,
			),
			$extra
		);
	}

	private function upload_file_from_request( array $files, string $field_name ) {
		if ( empty( $files[ $field_name ] ) || ! is_array( $files[ $field_name ] ) ) {
			return new WP_Error( 'aims_missing_file', 'A selfie/check-in image is required.' );
		}

		$file = $files[ $field_name ];
		if ( empty( $file['name'] ) ) {
			return new WP_Error( 'aims_missing_file', 'A selfie/check-in image is required.' );
		}

		return $this->upload_file( $file, $field_name );
	}

	private function upload_multiple_files_from_request( array $files, string $field_name ): array {
		if ( empty( $files[ $field_name ] ) ) {
			return array();
		}

		$group = $files[ $field_name ];
		if ( ! is_array( $group ) || empty( $group['name'] ) ) {
			return array();
		}

		$normalized = array();
		$count      = is_array( $group['name'] ) ? count( $group['name'] ) : 0;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( empty( $group['name'][ $i ] ) ) {
				continue;
			}

			$normalized[] = $this->upload_file(
				array(
					'name'     => $group['name'][ $i ],
					'type'     => $group['type'][ $i ] ?? '',
					'tmp_name' => $group['tmp_name'][ $i ] ?? '',
					'error'    => $group['error'][ $i ] ?? 0,
					'size'     => $group['size'][ $i ] ?? 0,
				),
				$field_name
			);
		}

		return $normalized;
	}

	private function upload_file( array $file, string $field_name ) {
		if ( ! empty( $this->uploader ) ) {
			if ( is_callable( $this->uploader ) ) {
				$result = call_user_func( $this->uploader, $file, $field_name );
				return is_array( $result ) ? $result : new WP_Error( 'aims_invalid_upload', 'The uploaded file could not be processed.' );
			}

			if ( is_object( $this->uploader ) && method_exists( $this->uploader, 'upload' ) ) {
				$result = $this->uploader->upload( $file, $field_name );
				return is_array( $result ) ? $result : new WP_Error( 'aims_invalid_upload', 'The uploaded file could not be processed.' );
			}
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			return new WP_Error( 'aims_missing_upload_handler', 'The server cannot process image uploads right now.' );
		}

		$upload = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( empty( $upload['url'] ) ) {
			return new WP_Error( 'aims_invalid_upload', 'The uploaded file could not be processed.' );
		}

		return array(
			'file_url'      => (string) $upload['url'],
			'attachment_id' => 0,
		);
	}

	private function get_current_url(): string {
		$scheme = function_exists( 'is_ssl' ) && is_ssl() ? 'https://' : 'http://';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		return esc_url_raw( $scheme . $host . $uri );
	}

	private function get_status_message( array $request ): array {
		$status  = sanitize_key( wp_unslash( $request['aims_vendor_checkin_status'] ?? '' ) );
		$message = sanitize_text_field( wp_unslash( $request['aims_vendor_checkin_message'] ?? '' ) );

		if ( '' === $status || '' === $message ) {
			return array();
		}

		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
