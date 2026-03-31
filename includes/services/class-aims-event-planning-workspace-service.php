<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Workspace_Service {
	private $events;
	private $demand_planning;
	private $bucket_assignments;
	private $physical_buckets;
	private $bucket_positions;
	private $storage_locations;
	private $vendor_event_assignments;
	private $access_service;
	private $bucket_availability_service;
	private $event_context_map = array();

	public function __construct(
		AIMS_Event_Repository $events = null,
		AIMS_Event_Demand_Planning_Service $demand_planning = null,
		AIMS_Event_Bucket_Assignment_Service $bucket_assignments = null,
		AIMS_Physical_Bucket_Repository $physical_buckets = null,
		AIMS_Bucket_Inventory_Position_Repository $bucket_positions = null,
		AIMS_Storage_Location_Repository $storage_locations = null,
		AIMS_Vendor_Event_Assignment_Repository $vendor_event_assignments = null,
		$access_service = null,
		$bucket_availability_service = null
	) {
		$this->events                    = $events ?: new AIMS_Event_Repository();
		$this->demand_planning           = $demand_planning ?: new AIMS_Event_Demand_Planning_Service( new AIMS_Event_Customer_Request_Item_Repository() );
		$this->bucket_assignments        = $bucket_assignments ?: new AIMS_Event_Bucket_Assignment_Service( new AIMS_Event_Bucket_Assignment_Repository() );
		$this->physical_buckets          = $physical_buckets ?: new AIMS_Physical_Bucket_Repository();
		$this->bucket_positions          = $bucket_positions ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->storage_locations         = $storage_locations ?: new AIMS_Storage_Location_Repository();
		$this->vendor_event_assignments  = $vendor_event_assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->access_service            = $access_service ?: ( class_exists( 'AIMS_Event_Planning_Access_Service' ) ? new AIMS_Event_Planning_Access_Service() : null );
		$this->bucket_availability_service = $bucket_availability_service;
	}

	public function get_page_model( array $request = array() ): array {
		$filter_state       = $this->normalize_filter_state( $request );
		$authorized_events  = $this->get_authorized_events( $filter_state );
		$selected_event_id  = $this->resolve_selected_event_id( $request, $authorized_events );
		$selected_event     = $this->find_authorized_event( $selected_event_id, $authorized_events );
		$workspace          = ! empty( $selected_event ) ? $this->build_workspace( $selected_event, $filter_state ) : array();

		return array(
			'authorized_events' => $authorized_events,
			'selected_event_id'  => $selected_event_id,
			'selected_event'     => $selected_event,
			'workspace'          => $workspace,
			'filter_state'       => $filter_state,
			'team_context'       => $this->build_team_context(),
			'selection_message'  => $this->build_selection_message( $selected_event, $authorized_events, $selected_event_id ),
		);
	}

	public function get_authorized_events( array $filter_state = array() ): array {
		$events = array();
		$this->event_context_map = array();
		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( is_object( $this->access_service ) && method_exists( $this->access_service, 'get_authorized_event_contexts' ) ) {
			$this->event_context_map = (array) $this->access_service->get_authorized_event_contexts( $current_user_id );
			$events = $this->load_events_from_context_map( $this->event_context_map );
		}

		if ( empty( $events ) && is_object( $this->access_service ) ) {
			if ( method_exists( $this->access_service, 'get_current_user_authorized_events' ) ) {
				$events = (array) $this->access_service->get_current_user_authorized_events();
			}

			if ( empty( $events ) && method_exists( $this->access_service, 'get_authorized_events' ) ) {
				$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
				$events          = (array) $this->access_service->get_authorized_events( $current_user_id );
			}

			if ( empty( $events ) && method_exists( $this->access_service, 'get_authorized_events_for_current_user' ) ) {
				$events = (array) $this->access_service->get_authorized_events_for_current_user();
			}

			if ( empty( $events ) && method_exists( $this->access_service, 'get_authorized_events_for_user' ) ) {
				$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
				$events          = (array) $this->access_service->get_authorized_events_for_user( $current_user_id );
			}

			if ( empty( $events ) && method_exists( $this->access_service, 'get_visible_events_for_current_user' ) ) {
				$events = (array) $this->access_service->get_visible_events_for_current_user();
			}

			if ( empty( $events ) && method_exists( $this->access_service, 'get_visible_events_for_user' ) ) {
				$events          = (array) $this->access_service->get_visible_events_for_user( $current_user_id );
			}
		}

		$normalized_events = $this->normalize_event_list( $events );

		return $this->apply_event_filters( $normalized_events, $filter_state );
	}

	public function resolve_selected_event_id( array $request, array $authorized_events ): int {
		$requested_event_id = isset( $request['event_id'] ) ? max( 0, (int) $request['event_id'] ) : 0;

		if ( $requested_event_id > 0 && $this->find_authorized_event( $requested_event_id, $authorized_events ) ) {
			return $requested_event_id;
		}

		if ( ! empty( $authorized_events ) ) {
			return (int) ( $authorized_events[0]['id'] ?? 0 );
		}

		return 0;
	}

	public function find_authorized_event( int $event_id, array $authorized_events ): ?array {
		if ( $event_id <= 0 ) {
			return null;
		}

		foreach ( $authorized_events as $event ) {
			if ( (int) ( $event['id'] ?? 0 ) === $event_id ) {
				return $event;
			}
		}

		return null;
	}

	public function build_workspace( array $event, array $filter_state = array() ): array {
		$event_id      = (int) ( $event['id'] ?? 0 );
		$vendor_ids    = $this->get_event_vendor_ids( $event_id );
		$demand_rows   = $this->get_demand_rows( $event_id );
		$assigned      = $this->get_assigned_bucket_rows( $event_id );
		$available     = $this->get_available_bucket_rows( $event_id, $vendor_ids, $assigned );
		$assigned      = $this->filter_assigned_rows_by_planner( $assigned, (int) ( $filter_state['planner_user_id'] ?? 0 ) );
		$assigned      = $this->filter_bucket_rows_by_search( $assigned, (string) ( $filter_state['bucket_search'] ?? '' ) );
		$available     = $this->filter_bucket_rows_by_search( $available, (string) ( $filter_state['bucket_search'] ?? '' ) );
		$summary       = $this->build_workspace_summary( $demand_rows, $assigned, $available );
		$team_activity = $this->build_team_activity_rows( $assigned );
		$timeline_rows = $this->build_assignment_timeline_rows( $assigned );

		return array(
			'event'           => $event,
			'event_vendor_ids' => $vendor_ids,
			'demand_rows'     => $demand_rows,
			'assigned_buckets' => $assigned,
			'available_buckets' => $available,
			'summary'         => $summary,
			'team_activity'   => $team_activity,
			'assignment_timeline' => $timeline_rows,
		);
	}

	private function build_workspace_summary( array $demand_rows, array $assigned_rows, array $available_rows ): array {
		$requested_quantity = 0.0;
		$open_quantity      = 0.0;
		$assigned_available = 0.0;
		$available_pool     = 0.0;
		$staged_count          = 0;
		$at_event_count        = 0;
		$staged_over_24h_count = 0;
		$open_over_8h_count    = 0;

		foreach ( $demand_rows as $demand_row ) {
			if ( ! is_array( $demand_row ) ) {
				continue;
			}

			$requested_quantity += (float) ( $demand_row['quantity_requested'] ?? $demand_row['demand_quantity'] ?? $demand_row['total_quantity_requested'] ?? 0 );
			$open_quantity      += (float) ( $demand_row['open_quantity'] ?? 0 );
		}

		foreach ( $assigned_rows as $assigned_row ) {
			if ( ! is_array( $assigned_row ) ) {
				continue;
			}

			$status = sanitize_key( (string) ( $assigned_row['assignment_status'] ?? '' ) );
			$age_hours = $this->calculate_assignment_age_hours( (string) ( $assigned_row['assigned_at'] ?? '' ) );
			if ( 'staged' === $status ) {
				++$staged_count;
				if ( $age_hours >= 24.0 ) {
					++$staged_over_24h_count;
				}
			}

			if ( 'at_event' === $status ) {
				++$at_event_count;
			}

			if ( in_array( $status, array( 'assigned', 'staged', 'in_transit' ), true ) && $age_hours >= 8.0 ) {
				++$open_over_8h_count;
			}

			$summary            = (array) ( $assigned_row['content_summary'] ?? array() );
			$assigned_available += (float) ( $summary['total_available_quantity'] ?? 0 );
		}

		foreach ( $available_rows as $available_row ) {
			if ( ! is_array( $available_row ) ) {
				continue;
			}

			$summary         = (array) ( $available_row['content_summary'] ?? array() );
			$available_pool += (float) ( $summary['total_available_quantity'] ?? 0 );
		}

		return array(
			'demand_requested_quantity'      => $requested_quantity,
			'demand_open_quantity'           => $open_quantity,
			'assigned_bucket_count'          => count( $assigned_rows ),
			'assigned_staged_bucket_count' => $staged_count,
			'staged_over_24h_count'        => $staged_over_24h_count,
			'assigned_at_event_bucket_count' => $at_event_count,
			'open_over_8h_count'           => $open_over_8h_count,
			'available_bucket_count'         => count( $available_rows ),
			'assigned_available_quantity'    => $assigned_available,
			'available_pool_quantity'        => $available_pool,
		);
	}

	private function build_team_activity_rows( array $assigned_rows ): array {
		$activity = array();

		foreach ( $assigned_rows as $assigned_row ) {
			if ( ! is_array( $assigned_row ) ) {
				continue;
			}

			$user_id = (int) ( $assigned_row['assigned_by'] ?? 0 );
			if ( $user_id <= 0 ) {
				continue;
			}

			if ( ! isset( $activity[ $user_id ] ) ) {
				$activity[ $user_id ] = array(
					'user_id'          => $user_id,
					'display_name'     => (string) ( $assigned_row['assigned_by_label'] ?? '' ),
					'assigned_count'      => 0,
					'staged_count'        => 0,
					'staged_over_24h_count' => 0,
					'at_event_count'      => 0,
					'last_assigned_at' => '',
				);
			}

			++$activity[ $user_id ]['assigned_count'];
			$status = sanitize_key( (string) ( $assigned_row['assignment_status'] ?? '' ) );
			if ( 'staged' === $status ) {
				++$activity[ $user_id ]['staged_count'];
				if ( $this->calculate_assignment_age_hours( (string) ( $assigned_row['assigned_at'] ?? '' ) ) >= 24.0 ) {
					$activity[ $user_id ]['staged_over_24h_count'] = (int) ( $activity[ $user_id ]['staged_over_24h_count'] ?? 0 ) + 1;
				}
			}

			if ( 'at_event' === $status ) {
				++$activity[ $user_id ]['at_event_count'];
			}

			$assigned_at = sanitize_text_field( (string) ( $assigned_row['assigned_at'] ?? '' ) );
			if ( '' !== $assigned_at && ( '' === $activity[ $user_id ]['last_assigned_at'] || $assigned_at > $activity[ $user_id ]['last_assigned_at'] ) ) {
				$activity[ $user_id ]['last_assigned_at'] = $assigned_at;
			}
		}

		$rows = array_values( $activity );

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				if ( (int) ( $left['assigned_count'] ?? 0 ) !== (int) ( $right['assigned_count'] ?? 0 ) ) {
					return (int) ( $right['assigned_count'] ?? 0 ) <=> (int) ( $left['assigned_count'] ?? 0 );
				}

				return strcasecmp( (string) ( $left['display_name'] ?? '' ), (string) ( $right['display_name'] ?? '' ) );
			}
		);

		return $rows;
	}

	private function build_assignment_timeline_rows( array $assigned_rows ): array {
		$rows = array();

		foreach ( $assigned_rows as $assigned_row ) {
			if ( ! is_array( $assigned_row ) ) {
				continue;
			}

			$assigned_at = sanitize_text_field( (string) ( $assigned_row['assigned_at'] ?? '' ) );
			$loaded_at   = sanitize_text_field( (string) ( $assigned_row['loaded_at'] ?? '' ) );
			$in_transit_at = sanitize_text_field( (string) ( $assigned_row['in_transit_at'] ?? '' ) );
			$status      = sanitize_key( (string) ( $assigned_row['assignment_status'] ?? '' ) );
			$age_hours   = $this->calculate_assignment_age_hours( $assigned_at );

			$rows[] = array(
				'assignment_id'    => (int) ( $assigned_row['assignment_id'] ?? 0 ),
				'physical_bucket_id' => (int) ( $assigned_row['physical_bucket_id'] ?? 0 ),
				'bucket_code'      => sanitize_text_field( (string) ( $assigned_row['bucket_code'] ?? '' ) ),
				'bucket_label'     => sanitize_text_field( (string) ( $assigned_row['bucket_label'] ?? '' ) ),
				'assignment_status' => $status,
				'assignment_label' => sanitize_text_field( (string) ( $assigned_row['assignment_label'] ?? '' ) ),
				'assigned_by'      => (int) ( $assigned_row['assigned_by'] ?? 0 ),
				'assigned_by_label' => sanitize_text_field( (string) ( $assigned_row['assigned_by_label'] ?? '' ) ),
				'assigned_at'      => $assigned_at,
				'loaded_at'        => $loaded_at,
				'in_transit_at'    => $in_transit_at,
				'age_hours'        => $age_hours,
				'age_band'         => $this->build_assignment_age_band( $status, $age_hours ),
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$left_assigned_at = (string) ( $left['assigned_at'] ?? '' );
				$right_assigned_at = (string) ( $right['assigned_at'] ?? '' );
				if ( $left_assigned_at !== $right_assigned_at ) {
					return strcmp( $right_assigned_at, $left_assigned_at );
				}

				return (int) ( $right['assignment_id'] ?? 0 ) <=> (int) ( $left['assignment_id'] ?? 0 );
			}
		);

		return array_slice( $rows, 0, 25 );
	}

	private function calculate_assignment_age_hours( string $assigned_at ): float {
		$assigned_timestamp = strtotime( $assigned_at );
		$now_timestamp      = $this->resolve_now_timestamp();

		if ( false === $assigned_timestamp || $assigned_timestamp <= 0 || $now_timestamp <= 0 || $assigned_timestamp >= $now_timestamp ) {
			return 0.0;
		}

		$seconds = $now_timestamp - $assigned_timestamp;

		return round( $seconds / 3600, 1 );
	}

	private function build_assignment_age_band( string $status, float $age_hours ): string {
		if ( in_array( $status, array( 'at_event', 'returned', 'released', 'cancelled' ), true ) ) {
			return '—';
		}

		if ( $age_hours >= 24.0 ) {
			return '> 24h';
		}

		if ( $age_hours >= 8.0 ) {
			return '8–24h';
		}

		return '< 8h';
	}

	private function resolve_now_timestamp(): int {
		$now       = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$timestamp = strtotime( $now );

		return false === $timestamp ? time() : (int) $timestamp;
	}

	private function get_demand_rows( int $event_id ): array {
		if ( $event_id <= 0 || ! is_object( $this->demand_planning ) || ! method_exists( $this->demand_planning, 'summarize_event_demand' ) ) {
			return array();
		}

		return array_values( array_filter( (array) $this->demand_planning->summarize_event_demand( $event_id ) ) );
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

	private function get_assigned_bucket_rows( int $event_id ): array {
		if ( $event_id <= 0 || ! is_object( $this->bucket_assignments ) || ! method_exists( $this->bucket_assignments, 'get_active_buckets_for_event' ) ) {
			return array();
		}

		$rows = array();

		foreach ( (array) $this->bucket_assignments->get_active_buckets_for_event( $event_id ) as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			$rows[] = $this->build_bucket_assignment_row( $assignment, true );
		}

		return $rows;
	}

	private function get_available_bucket_rows( int $event_id, array $vendor_ids, array $assigned_rows ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		$assigned_bucket_ids = array();
		foreach ( $assigned_rows as $assignment_row ) {
			$assigned_bucket_ids[] = (int) ( $assignment_row['physical_bucket_id'] ?? 0 );
		}
		$assigned_bucket_ids = array_values( array_unique( array_filter( $assigned_bucket_ids ) ) );

		if ( is_object( $this->bucket_availability_service ) ) {
			foreach ( array( 'get_available_buckets_for_event', 'get_available_buckets' ) as $method ) {
				if ( method_exists( $this->bucket_availability_service, $method ) ) {
					$payload = 'get_available_buckets_for_event' === $method
						? $this->bucket_availability_service->{$method}( $event_id, array(
							'vendor_ids' => $vendor_ids,
							'assigned_bucket_ids' => $assigned_bucket_ids,
						) )
						: $this->bucket_availability_service->{$method}( array(
							'event_id' => $event_id,
							'vendor_ids' => $vendor_ids,
							'assigned_bucket_ids' => $assigned_bucket_ids,
						) );

					return $this->normalize_bucket_selection_rows( (array) $payload, $event_id, $assigned_bucket_ids );
				}
			}
		}

		return $this->build_fallback_available_bucket_rows( $event_id, $vendor_ids, $assigned_bucket_ids );
	}

	private function build_fallback_available_bucket_rows( int $event_id, array $vendor_ids, array $assigned_bucket_ids ): array {
		$rows    = array();
		$seen_ids = array();

		foreach ( $vendor_ids as $vendor_id ) {
			if ( $vendor_id <= 0 || ! is_object( $this->physical_buckets ) || ! method_exists( $this->physical_buckets, 'get_for_vendor' ) ) {
				continue;
			}

			foreach ( (array) $this->physical_buckets->get_for_vendor( $vendor_id ) as $bucket ) {
				if ( ! is_array( $bucket ) ) {
					continue;
				}

				$bucket_id = (int) ( $bucket['id'] ?? 0 );
				if ( $bucket_id <= 0 || isset( $seen_ids[ $bucket_id ] ) ) {
					continue;
				}

				$seen_ids[ $bucket_id ] = true;

				$active_assignment = $this->get_active_assignment_for_bucket( $bucket_id );
				if ( ! empty( $active_assignment ) ) {
					continue;
				}

				$rows[] = $this->build_bucket_selection_row( $bucket, false, $active_assignment );
			}
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return strcasecmp( (string) ( $left['bucket_label'] ?? '' ), (string) ( $right['bucket_label'] ?? '' ) );
			}
		);

		return $rows;
	}

	private function normalize_bucket_selection_rows( array $rows, int $event_id, array $assigned_bucket_ids ): array {
		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$bucket_id = (int) ( $row['physical_bucket_id'] ?? $row['bucket_id'] ?? 0 );
			if ( $bucket_id <= 0 ) {
				continue;
			}

			if ( in_array( $bucket_id, $assigned_bucket_ids, true ) ) {
				continue;
			}

			$active_assignment = $this->get_active_assignment_for_bucket( $bucket_id );
			if ( ! empty( $active_assignment ) ) {
				continue;
			}

			$normalized[] = $this->build_bucket_selection_row( $row, ! empty( $active_assignment ), $active_assignment );
		}

		return $normalized;
	}

	private function build_bucket_assignment_row( array $assignment, bool $is_assigned = false ): array {
		$bucket_id = (int) ( $assignment['physical_bucket_id'] ?? $assignment['bucket_id'] ?? 0 );
		$bucket    = $bucket_id > 0 ? $this->find_bucket( $bucket_id ) : array();
		$contents  = $bucket_id > 0 ? $this->get_bucket_contents( $bucket_id ) : array();
		$summary   = $this->summarize_contents( $contents );

		return array_merge(
			$this->build_bucket_selection_row( $bucket, $is_assigned, $assignment ),
			array(
				'physical_bucket_id' => $bucket_id,
				'assignment_id'    => (int) ( $assignment['id'] ?? 0 ),
				'event_id'         => (int) ( $assignment['event_id'] ?? 0 ),
				'assignment_status' => sanitize_key( (string) ( $assignment['assignment_status'] ?? '' ) ),
				'assignment_type'  => sanitize_key( (string) ( $assignment['assignment_type'] ?? '' ) ),
				'is_active'        => ! empty( $assignment['is_active'] ),
				'assigned_at'      => sanitize_text_field( (string) ( $assignment['assigned_at'] ?? '' ) ),
				'loaded_at'        => sanitize_text_field( (string) ( $assignment['loaded_at'] ?? '' ) ),
				'in_transit_at'    => sanitize_text_field( (string) ( $assignment['in_transit_at'] ?? '' ) ),
				'released_at'      => sanitize_text_field( (string) ( $assignment['released_at'] ?? '' ) ),
				'assigned_by'      => (int) ( $assignment['assigned_by'] ?? 0 ),
				'released_by'      => (int) ( $assignment['released_by'] ?? 0 ),
				'assigned_by_label' => $this->resolve_user_display_name( (int) ( $assignment['assigned_by'] ?? 0 ) ),
				'released_by_label' => $this->resolve_user_display_name( (int) ( $assignment['released_by'] ?? 0 ) ),
				'display_order'    => (int) ( $assignment['display_order'] ?? 0 ),
				'contents'         => $contents,
				'content_summary'  => $summary,
				'assignment_label' => $this->build_assignment_label( $assignment ),
			)
		);
	}

	private function build_bucket_selection_row( array $bucket, bool $has_active_assignment = false, ?array $assignment = null ): array {
		$bucket_id = (int) ( $bucket['id'] ?? 0 );
		$assignment = is_array( $assignment ) ? $assignment : array();

		return array(
			'physical_bucket_id'         => $bucket_id,
			'bucket_code'                => sanitize_text_field( (string) ( $bucket['bucket_code'] ?? '' ) ),
			'bucket_label'               => sanitize_text_field( (string) ( $bucket['bucket_label'] ?? '' ) ),
			'bucket_type'                => sanitize_key( (string) ( $bucket['bucket_type'] ?? '' ) ),
			'status'                     => sanitize_key( (string) ( $bucket['status'] ?? '' ) ),
			'vendor_id'                  => (int) ( $bucket['vendor_id'] ?? 0 ),
			'barcode_value'              => sanitize_text_field( (string) ( $bucket['barcode_value'] ?? '' ) ),
			'current_storage_location_id' => (int) ( $bucket['current_storage_location_id'] ?? 0 ),
			'home_storage_location_id'    => (int) ( $bucket['home_storage_location_id'] ?? 0 ),
			'storage'                    => $this->build_storage_context( $bucket ),
			'contents'                   => $this->get_bucket_contents( $bucket_id ),
			'content_summary'            => $this->summarize_contents( $this->get_bucket_contents( $bucket_id ) ),
			'is_active_assignment'       => $has_active_assignment,
			'active_assignment'          => $assignment,
		);
	}

	private function get_bucket_contents( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! is_object( $this->bucket_positions ) || ! method_exists( $this->bucket_positions, 'get_for_bucket' ) ) {
			return array();
		}

		$rows = array();
		foreach ( (array) $this->bucket_positions->get_for_bucket( $bucket_id ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = $this->normalize_bucket_content_row( $row );
		}

		return $rows;
	}

	private function normalize_bucket_content_row( array $row ): array {
		$product_id  = (int) ( $row['product_id'] ?? 0 );
		$product     = $this->find_product( $product_id );
		$product_sku = '';
		$product_name = '';

		if ( is_object( $product ) ) {
			if ( method_exists( $product, 'get_sku' ) ) {
				$product_sku = sanitize_text_field( (string) $product->get_sku() );
			}

			if ( method_exists( $product, 'get_name' ) ) {
				$product_name = sanitize_text_field( (string) $product->get_name() );
			}
		}

		return array(
			'position_id'        => (int) ( $row['id'] ?? 0 ),
			'bucket_id'          => (int) ( $row['bucket_id'] ?? 0 ),
			'vendor_id'          => (int) ( $row['vendor_id'] ?? 0 ),
			'product_id'         => $product_id,
			'product_sku'        => $product_sku,
			'product_name'       => $product_name,
			'quantity'           => (float) ( $row['quantity'] ?? 0 ),
			'reserved_quantity'  => (float) ( $row['reserved_quantity'] ?? 0 ),
			'available_quantity' => max( 0, (float) ( $row['quantity'] ?? 0 ) - (float) ( $row['reserved_quantity'] ?? 0 ) ),
			'position_status'    => sanitize_key( (string) ( $row['position_status'] ?? '' ) ),
			'last_counted_at'    => sanitize_text_field( (string) ( $row['last_counted_at'] ?? '' ) ),
		);
	}

	private function summarize_contents( array $contents ): array {
		$total_quantity          = 0.0;
		$total_reserved_quantity  = 0.0;
		$total_available_quantity = 0.0;

		foreach ( $contents as $content ) {
			if ( ! is_array( $content ) ) {
				continue;
			}

			$total_quantity          += (float) ( $content['quantity'] ?? 0 );
			$total_reserved_quantity  += (float) ( $content['reserved_quantity'] ?? 0 );
			$total_available_quantity += (float) ( $content['available_quantity'] ?? 0 );
		}

		return array(
			'line_count'             => count( $contents ),
			'total_quantity'         => $total_quantity,
			'total_reserved_quantity' => $total_reserved_quantity,
			'total_available_quantity' => $total_available_quantity,
		);
	}

	private function build_storage_context( array $bucket ): array {
		$current_location_id = (int) ( $bucket['current_storage_location_id'] ?? 0 );
		$home_location_id    = (int) ( $bucket['home_storage_location_id'] ?? 0 );

		return array(
			'current' => $this->resolve_storage_location( $current_location_id ),
			'home'    => $this->resolve_storage_location( $home_location_id ),
		);
	}

	private function resolve_storage_location( int $location_id ): array {
		if ( $location_id <= 0 || ! is_object( $this->storage_locations ) || ! method_exists( $this->storage_locations, 'find' ) ) {
			return array(
				'id'    => $location_id,
				'label' => '',
			);
		}

		$location = $this->storage_locations->find( $location_id );

		if ( ! is_array( $location ) ) {
			return array(
				'id'    => $location_id,
				'label' => '',
			);
		}

		return array(
			'id'    => $location_id,
			'label' => sanitize_text_field( (string) ( $location['location_name'] ?? $location['location_code'] ?? '' ) ),
			'code'  => sanitize_text_field( (string) ( $location['location_code'] ?? '' ) ),
			'type'  => sanitize_key( (string) ( $location['location_type'] ?? '' ) ),
			'status' => sanitize_key( (string) ( $location['status'] ?? '' ) ),
		);
	}

	private function find_bucket( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! is_object( $this->physical_buckets ) || ! method_exists( $this->physical_buckets, 'find' ) ) {
			return array();
		}

		$bucket = $this->physical_buckets->find( $bucket_id );

		return is_array( $bucket ) ? $bucket : array();
	}

	private function find_product( int $product_id ) {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		return wc_get_product( $product_id );
	}

	private function get_active_assignment_for_bucket( int $bucket_id ): ?array {
		if ( $bucket_id <= 0 || ! is_object( $this->bucket_assignments ) || ! method_exists( $this->bucket_assignments, 'get_active_for_bucket' ) ) {
			return null;
		}

		$assignment = $this->bucket_assignments->get_active_for_bucket( $bucket_id );

		return is_array( $assignment ) ? $assignment : null;
	}

	private function build_assignment_label( array $assignment ): string {
		$status = sanitize_key( (string) ( $assignment['assignment_status'] ?? '' ) );

		switch ( $status ) {
			case 'assigned':
				return 'Assigned';
			case 'staged':
				return 'Staged';
			case 'in_transit':
				return 'In Transit';
			case 'at_event':
				return 'At Event';
			case 'returned':
				return 'Returned';
			case 'released':
				return 'Released';
			default:
				return '' !== $status ? ucfirst( str_replace( '_', ' ', $status ) ) : 'Assigned';
		}
	}

	private function normalize_event_list( array $events ): array {
		$normalized = array();
		$seen_ids   = array();

		foreach ( $events as $event ) {
			$record = array();

			if ( is_numeric( $event ) ) {
				$record = $this->find_event_by_id( (int) $event );
			} elseif ( is_array( $event ) ) {
				$record = $event;
			}

			$normalized_event = $this->normalize_event_record( $record );
			$event_id         = (int) ( $normalized_event['id'] ?? 0 );

			if ( $event_id <= 0 || isset( $seen_ids[ $event_id ] ) ) {
				continue;
			}

			$seen_ids[ $event_id ] = true;
			$normalized[]          = $normalized_event;
		}

		usort(
			$normalized,
			static function ( array $left, array $right ): int {
				$left_date  = (string) ( $left['start_date'] ?? '' );
				$right_date = (string) ( $right['start_date'] ?? '' );

				if ( '' !== $left_date || '' !== $right_date ) {
					$result = strcmp( $right_date, $left_date );
					if ( 0 !== $result ) {
						return $result;
					}
				}

				return (int) ( $right['id'] ?? 0 ) <=> (int) ( $left['id'] ?? 0 );
			}
		);

		return $normalized;
	}

	private function load_events_by_ids( array $event_ids ): array {
		$event_ids = array_values( array_unique( array_filter( array_map( 'intval', $event_ids ) ) ) );

		if ( empty( $event_ids ) || ! is_object( $this->events ) ) {
			return array();
		}

		if ( method_exists( $this->events, 'get_table_name' ) ) {
			global $wpdb;

			$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
			$sql = 'SELECT * FROM ' . $this->events->get_table_name() . ' WHERE id IN (' . $placeholders . ') ORDER BY start_date DESC, id DESC';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $event_ids ), ARRAY_A );

			if ( is_array( $rows ) && ! empty( $rows ) ) {
				return $rows;
			}
		}

		$rows = array();
		foreach ( $event_ids as $event_id ) {
			$event = $this->find_event_by_id( $event_id );
			if ( ! empty( $event ) ) {
				$rows[] = $event;
			}
		}

		return $rows;
	}

	private function find_event_by_id( int $event_id ): array {
		if ( $event_id <= 0 || ! is_object( $this->events ) ) {
			return array();
		}

		foreach ( array( 'find', 'get', 'get_event' ) as $method ) {
			if ( method_exists( $this->events, $method ) ) {
				$event = $this->events->{$method}( $event_id );
				if ( is_array( $event ) ) {
					return $event;
				}
			}
		}

		if ( method_exists( $this->events, 'all' ) ) {
			foreach ( (array) $this->events->all() as $event ) {
				if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
					return $event;
				}
			}
		}

		return array();
	}

	private function normalize_event_record( array $event ): array {
		$start_date = sanitize_text_field( (string) ( $event['start_date'] ?? '' ) );
		$end_date   = sanitize_text_field( (string) ( $event['end_date'] ?? '' ) );
		$event_id   = (int) ( $event['id'] ?? 0 );

		return array(
			'id'                => $event_id,
			'event_code'        => sanitize_text_field( (string) ( $event['event_code'] ?? '' ) ),
			'event_name'        => sanitize_text_field( (string) ( $event['event_name'] ?? '' ) ),
			'status'            => sanitize_key( (string) ( $event['status'] ?? '' ) ),
			'start_date'        => $start_date,
			'end_date'          => $end_date,
			'location_name'     => sanitize_text_field( (string) ( $event['location_name'] ?? '' ) ),
			'square_location_id' => sanitize_text_field( (string) ( $event['square_location_id'] ?? '' ) ),
			'visibility_source'  => $this->get_event_visibility_source( $event_id ),
			'date_range_label'   => $this->format_date_range( $start_date, $end_date ),
		);
	}

	private function format_date_range( string $start_date, string $end_date ): string {
		$start = strtotime( $start_date );
		$end   = strtotime( $end_date );

		if ( ! $start && ! $end ) {
			return '';
		}

		if ( $start && $end && gmdate( 'Y-m-d', $start ) === gmdate( 'Y-m-d', $end ) ) {
			return gmdate( 'F j, Y', $start );
		}

		if ( $start && $end ) {
			return gmdate( 'F j, Y', $start ) . ' - ' . gmdate( 'F j, Y', $end );
		}

		return $start ? gmdate( 'F j, Y', $start ) : gmdate( 'F j, Y', $end );
	}

	private function normalize_filter_state( array $request ): array {
		$event_scope = sanitize_key( (string) ( $request['event_scope'] ?? 'all' ) );
		if ( ! in_array( $event_scope, array( 'all', 'my', 'team' ), true ) ) {
			$event_scope = 'all';
		}

		$planner_user_id = isset( $request['planner_user_id'] ) ? (int) $request['planner_user_id'] : 0;
		if ( $planner_user_id < 0 ) {
			$planner_user_id = 0;
		}

		return array(
			'event_scope'   => $event_scope,
			'event_search'  => sanitize_text_field( (string) ( $request['event_search'] ?? '' ) ),
			'bucket_search' => sanitize_text_field( (string) ( $request['bucket_search'] ?? '' ) ),
			'planner_user_id' => $planner_user_id,
		);
	}

	private function filter_assigned_rows_by_planner( array $rows, int $planner_user_id ): array {
		if ( $planner_user_id <= 0 ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $planner_user_id ): bool {
					return (int) ( $row['assigned_by'] ?? 0 ) === $planner_user_id;
				}
			)
		);
	}

	private function apply_event_filters( array $events, array $filter_state ): array {
		$scope  = (string) ( $filter_state['event_scope'] ?? 'all' );
		$search = strtolower( trim( (string) ( $filter_state['event_search'] ?? '' ) ) );

		$filtered = array_values(
			array_filter(
				$events,
				function ( array $event ) use ( $scope, $search ): bool {
					$source = (string) ( $event['visibility_source'] ?? 'self' );

					if ( 'my' === $scope && 'self' !== $source ) {
						return false;
					}

					if ( 'team' === $scope && 'team' !== $source ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								(string) ( $event['event_name'] ?? '' ),
								(string) ( $event['event_code'] ?? '' ),
								(string) ( $event['location_name'] ?? '' ),
								(string) ( $event['date_range_label'] ?? '' ),
							)
						)
					);

					return false !== strpos( $haystack, $search );
				}
			)
		);

		return $filtered;
	}

	private function filter_bucket_rows_by_search( array $rows, string $search ): array {
		$search = strtolower( trim( $search ) );

		if ( '' === $search ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $search ): bool {
					$haystack = strtolower(
						implode(
							' ',
							array(
								(string) ( $row['bucket_label'] ?? '' ),
								(string) ( $row['bucket_code'] ?? '' ),
								(string) ( $row['barcode_value'] ?? '' ),
								(string) ( $row['status'] ?? '' ),
							)
						)
					);

					return false !== strpos( $haystack, $search );
				}
			)
		);
	}

	private function load_events_from_context_map( array $context_map ): array {
		$event_ids = array();
		foreach ( $context_map as $context ) {
			if ( ! is_array( $context ) ) {
				continue;
			}

			$event_id = (int) ( $context['event_id'] ?? 0 );
			if ( $event_id > 0 ) {
				$event_ids[] = $event_id;
			}
		}

		if ( empty( $event_ids ) ) {
			return array();
		}

		return $this->load_events_by_ids( $event_ids );
	}

	private function build_team_context(): array {
		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$subordinates    = array();

		if ( is_object( $this->access_service ) && method_exists( $this->access_service, 'get_subordinate_user_ids' ) ) {
			$subordinates = (array) $this->access_service->get_subordinate_user_ids( $current_user_id );
		}

		$team_members = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'intval', $subordinates ) ) ) ) as $user_id ) {
			$team_members[] = array(
				'user_id'      => $user_id,
				'display_name' => $this->resolve_user_display_name( $user_id ),
			);
		}

		return array(
			'current_user_id'    => $current_user_id,
			'current_user_label' => $this->resolve_user_display_name( $current_user_id ),
			'subordinates'       => $team_members,
			'is_supervisor'      => ! empty( $team_members ),
		);
	}

	private function resolve_user_display_name( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_user_by' ) ) {
			return '';
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! is_object( $user ) ) {
			return '';
		}

		foreach ( array( 'display_name', 'user_login', 'user_email' ) as $field ) {
			if ( isset( $user->{$field} ) && '' !== (string) $user->{$field} ) {
				return sanitize_text_field( (string) $user->{$field} );
			}
		}

		return '';
	}

	private function get_event_visibility_source( int $event_id ): string {
		$context = $this->event_context_map[ $event_id ] ?? array();

		if ( ! is_array( $context ) ) {
			return 'self';
		}

		$source = sanitize_key( (string) ( $context['source'] ?? 'self' ) );

		return in_array( $source, array( 'self', 'team', 'all' ), true ) ? $source : 'self';
	}

	private function build_selection_message( ?array $selected_event, array $authorized_events, int $selected_event_id ): string {
		if ( empty( $authorized_events ) ) {
			return 'No authorized events are available for planning.';
		}

		if ( empty( $selected_event ) ) {
			if ( $selected_event_id > 0 ) {
				return 'The requested event is not available to this planner.';
			}

			return 'Select an event to review demand and assign buckets.';
		}

		return '';
	}
}
