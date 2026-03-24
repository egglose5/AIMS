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
		$authorized_events  = $this->get_authorized_events();
		$selected_event_id  = $this->resolve_selected_event_id( $request, $authorized_events );
		$selected_event     = $this->find_authorized_event( $selected_event_id, $authorized_events );
		$workspace          = ! empty( $selected_event ) ? $this->build_workspace( $selected_event ) : array();

		return array(
			'authorized_events' => $authorized_events,
			'selected_event_id'  => $selected_event_id,
			'selected_event'     => $selected_event,
			'workspace'          => $workspace,
			'selection_message'  => $this->build_selection_message( $selected_event, $authorized_events, $selected_event_id ),
		);
	}

	public function get_authorized_events(): array {
		$events = array();

		if ( is_object( $this->access_service ) ) {
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
				$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
				$events          = (array) $this->access_service->get_visible_events_for_user( $current_user_id );
			}
		}

		return $this->normalize_event_list( $events );
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

	public function build_workspace( array $event ): array {
		$event_id      = (int) ( $event['id'] ?? 0 );
		$vendor_ids    = $this->get_event_vendor_ids( $event_id );
		$demand_rows   = $this->get_demand_rows( $event_id );
		$assigned      = $this->get_assigned_bucket_rows( $event_id );
		$available     = $this->get_available_bucket_rows( $event_id, $vendor_ids, $assigned );

		return array(
			'event'           => $event,
			'event_vendor_ids' => $vendor_ids,
			'demand_rows'     => $demand_rows,
			'assigned_buckets' => $assigned,
			'available_buckets' => $available,
		);
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
				'assigned_at'      => sanitize_text_field( (string) ( $assignment['assigned_at'] ?? '' ) ),
				'released_at'      => sanitize_text_field( (string) ( $assignment['released_at'] ?? '' ) ),
				'assigned_by'      => (int) ( $assignment['assigned_by'] ?? 0 ),
				'released_by'      => (int) ( $assignment['released_by'] ?? 0 ),
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

		return array(
			'id'                => (int) ( $event['id'] ?? 0 ),
			'event_code'        => sanitize_text_field( (string) ( $event['event_code'] ?? '' ) ),
			'event_name'        => sanitize_text_field( (string) ( $event['event_name'] ?? '' ) ),
			'status'            => sanitize_key( (string) ( $event['status'] ?? '' ) ),
			'start_date'        => $start_date,
			'end_date'          => $end_date,
			'location_name'     => sanitize_text_field( (string) ( $event['location_name'] ?? '' ) ),
			'square_location_id' => sanitize_text_field( (string) ( $event['square_location_id'] ?? '' ) ),
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
