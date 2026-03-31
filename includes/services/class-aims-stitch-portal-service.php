<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Portal_Service {
	private $stitch_jobs;
	private $physical_buckets;
	private $bucket_positions;
	private $events;
	private $person_identity;

	public function __construct(
		AIMS_Stitch_Job_Repository $stitch_jobs = null,
		AIMS_Physical_Bucket_Repository $physical_buckets = null,
		AIMS_Bucket_Inventory_Position_Repository $bucket_positions = null,
		AIMS_Event_Repository $events = null,
		AIMS_Person_Identity_Service $person_identity = null
	) {
		$this->stitch_jobs      = $stitch_jobs ?: new AIMS_Stitch_Job_Repository();
		$this->physical_buckets  = $physical_buckets ?: new AIMS_Physical_Bucket_Repository();
		$this->bucket_positions  = $bucket_positions ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->events            = $events ?: new AIMS_Event_Repository();
		$this->person_identity   = $person_identity ?: new AIMS_Person_Identity_Service();
	}

	public function get_page_model( array $request = array() ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$logged_in = $user_id > 0;
		$can_view = $this->can_view_portal( $user_id );

		return array(
			'logged_in'        => $logged_in,
			'can_view'         => $can_view,
			'current_user_id'  => $user_id,
			'stitcher_name'    => $this->resolve_stitcher_name( $user_id ),
			'stitcher_buckets' => $can_view ? $this->get_custody_buckets( $user_id ) : array(),
			'open_jobs'        => $can_view ? $this->get_open_jobs( $user_id ) : array(),
			'selected_job_id'  => $this->resolve_job_id( $request ),
			'status_message'   => $this->get_status_message( $request ),
			'return_url'       => $this->get_current_url(),
			'login_url'        => function_exists( 'wp_login_url' ) ? wp_login_url( $this->get_current_url() ) : '',
		);
	}

	public function complete_job( array $request = array() ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! $this->can_view_portal( $user_id ) ) {
			return $this->failure_response( 'You must be signed in as a stitcher to complete work items.' );
		}

		$job_id = $this->resolve_job_id( $request );
		if ( $job_id <= 0 ) {
			return $this->failure_response( 'Select a stitch work item to complete.' );
		}

		$job = $this->stitch_jobs->find( $job_id );
		if ( empty( $job ) || ! is_array( $job ) ) {
			return $this->failure_response( 'Select a valid stitch work item.' );
		}

		if ( (int) ( $job['assigned_user_id'] ?? 0 ) !== $user_id ) {
			return $this->failure_response( 'This stitch work item is not assigned to your account.' );
		}

		$status = sanitize_key( (string) ( $job['status'] ?? '' ) );
		if ( in_array( $status, array( AIMS_Stitch_Job_Repository::STATUS_IN_TRANSIT_BACK, AIMS_Stitch_Job_Repository::STATUS_COMPLETED, AIMS_Stitch_Job_Repository::STATUS_RETURNED ), true ) ) {
			return $this->failure_response( 'This stitch work item is already complete.' );
		}

		$notes = sanitize_textarea_field( (string) ( $request['completion_notes'] ?? '' ) );
		$updated = $this->stitch_jobs->mark_complete_and_in_transit_back( $job_id, $user_id, $notes );

		if ( ! $updated ) {
			return $this->failure_response( 'The stitch work item could not be updated.' );
		}

		return array(
			'success'   => true,
			'message'   => 'Stitch work item marked complete and in transit back.',
			'job_id'    => $job_id,
			'status'    => AIMS_Stitch_Job_Repository::STATUS_IN_TRANSIT_BACK,
		);
	}

	private function can_view_portal( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL ) ) {
			return false;
		}

		return is_object( $this->person_identity ) && $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_STITCH );
	}

	private function get_custody_buckets( int $user_id ): array {
		if ( $user_id <= 0 || ! method_exists( $this->physical_buckets, 'get_for_vendor' ) ) {
			return array();
		}

		$buckets = array();
		foreach ( (array) $this->physical_buckets->get_for_vendor( $user_id ) as $bucket ) {
			if ( ! is_array( $bucket ) ) {
				continue;
			}

			if ( sanitize_key( (string) ( $bucket['bucket_type'] ?? '' ) ) !== AIMS_Physical_Bucket_Types::STITCHER ) {
				continue;
			}

			$bucket_id = (int) ( $bucket['id'] ?? 0 );
			$summary   = $this->build_bucket_contents( $bucket_id );
			$buckets[] = array_merge(
				$bucket,
				array(
					'bucket_id'            => $bucket_id,
					'contents_summary'      => $summary,
					'contents_line_count'   => count( $summary ),
					'contents_total_qty'    => $this->sum_bucket_contents( $summary, 'quantity' ),
					'contents_reserved_qty' => $this->sum_bucket_contents( $summary, 'reserved_quantity' ),
					'display_label'         => $this->build_bucket_label( $bucket ),
				)
			);
		}

		return $buckets;
	}

	private function get_open_jobs( int $user_id ): array {
		if ( $user_id <= 0 || ! method_exists( $this->stitch_jobs, 'get_open_for_user' ) ) {
			return array();
		}

		$jobs = array();
		foreach ( (array) $this->stitch_jobs->get_open_for_user( $user_id ) as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			$event_id = (int) ( $job['event_id'] ?? 0 );
			$jobs[]   = array_merge(
				$job,
				array(
					'event_name'      => $this->resolve_event_name( $event_id ),
					'job_status_label' => $this->build_job_status_label( (string) ( $job['status'] ?? '' ) ),
					'due_at_label'     => $this->format_datetime( (string) ( $job['due_at'] ?? '' ) ),
				)
			);
		}

		return $jobs;
	}

	private function build_bucket_contents( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! method_exists( $this->bucket_positions, 'get_bucket_contents_summary' ) ) {
			return array();
		}

		$summary = array();
		foreach ( (array) $this->bucket_positions->get_bucket_contents_summary( $bucket_id ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = (int) ( $row['product_id'] ?? 0 );
			$summary[]  = array_merge(
				$row,
				array(
					'product_label' => $this->resolve_product_label( $product_id ),
					'product_code'  => $product_id > 0 ? 'SKU #' . $product_id : '',
				)
			);
		}

		return $summary;
	}

	private function resolve_product_label( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$label = trim( (string) $product->get_name() );
				$sku   = trim( (string) $product->get_sku() );

				if ( '' !== $label && '' !== $sku ) {
					return $label . ' (' . $sku . ')';
				}

				if ( '' !== $label ) {
					return $label;
				}

				if ( '' !== $sku ) {
					return $sku;
				}
			}
		}

		return 'Product #' . $product_id;
	}

	private function build_bucket_label( array $bucket ): string {
		$label = trim( (string) ( $bucket['bucket_label'] ?? '' ) );
		$code  = trim( (string) ( $bucket['bucket_code'] ?? '' ) );

		if ( '' !== $label && '' !== $code ) {
			return $label . ' (' . $code . ')';
		}

		return '' !== $label ? $label : $code;
	}

	private function build_job_status_label( string $status ): string {
		$status = sanitize_key( $status );

		$labels = array(
			AIMS_Stitch_Job_Repository::STATUS_QUEUED          => 'Queued',
			AIMS_Stitch_Job_Repository::STATUS_ASSIGNED        => 'Assigned',
			AIMS_Stitch_Job_Repository::STATUS_IN_PROGRESS     => 'In Progress',
			AIMS_Stitch_Job_Repository::STATUS_STITCHING       => 'Stitching',
			AIMS_Stitch_Job_Repository::STATUS_IN_TRANSIT_BACK  => 'In Transit Back',
			AIMS_Stitch_Job_Repository::STATUS_COMPLETED       => 'Completed',
			AIMS_Stitch_Job_Repository::STATUS_RETURNED        => 'Returned',
			AIMS_Stitch_Job_Repository::STATUS_CANCELLED       => 'Cancelled',
		);

		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}

	private function resolve_event_name( int $event_id ): string {
		if ( $event_id <= 0 || ! method_exists( $this->events, 'find' ) ) {
			return '';
		}

		$event = $this->events->find( $event_id );

		return is_array( $event ) ? (string) ( $event['event_name'] ?? '' ) : '';
	}

	private function resolve_stitcher_name( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_user_by' ) ) {
			return '';
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! is_object( $user ) ) {
			return '';
		}

		return trim( (string) ( $user->display_name ?? $user->user_login ?? '' ) );
	}

	private function resolve_job_id( array $request ): int {
		return max( 0, (int) ( $request['stitch_job_id'] ?? 0 ) );
	}

	private function get_status_message( array $request ): array {
		$status  = isset( $request['stitch_status'] ) ? sanitize_key( (string) $request['stitch_status'] ) : '';
		$message = isset( $request['stitch_message'] ) ? sanitize_text_field( (string) $request['stitch_message'] ) : '';

		return array(
			'status'  => $status,
			'message' => $message,
		);
	}

	private function get_current_url(): string {
		if ( function_exists( 'home_url' ) ) {
			return home_url( '/' );
		}

		return '';
	}

	private function format_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$time = strtotime( $value );
		if ( false === $time ) {
			return $value;
		}

		return gmdate( 'Y-m-d H:i', $time );
	}

	private function sum_bucket_contents( array $summary, string $field ): float {
		$total = 0.0;

		foreach ( $summary as $row ) {
			$total += (float) ( $row[ $field ] ?? 0 );
		}

		return $total;
	}

	private function failure_response( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
