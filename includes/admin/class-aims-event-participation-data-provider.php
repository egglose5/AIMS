<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Participation_Data_Provider {
	private $event_automation;
	private $events;
	private $assignments;
	private $vendors;
	private $vendor_access;
	private $scope_resolver;
	private $audit;
	private $auth_context;

	public function __construct(
		AIMS_Event_Automation_Service $event_automation = null,
		AIMS_Event_Repository $events = null,
		AIMS_Vendor_Event_Assignment_Repository $assignments = null,
		AIMS_Vendor_Repository $vendors = null,
		AIMS_Admin_Scope_Resolver $scope_resolver = null,
		AIMS_Vendor_Access_Service $vendor_access = null,
		AIMS_Audit_Service $audit = null,
		AIMS_Auth_Context_Service $auth_context = null
	) {
		$this->audit = $audit ?: new AIMS_Audit_Service();
		$this->auth_context = $auth_context ?: new AIMS_Auth_Context_Service();
		$vendor_repository = $vendors ?: new AIMS_Vendor_Repository();
		$this->vendor_access = $vendor_access ?: new AIMS_Vendor_Access_Service(
			new AIMS_Vendor_User_Access_Repository(),
			$vendor_repository,
			$this->audit,
			$this->auth_context
		);
		$this->event_automation = $event_automation ?: new AIMS_Event_Automation_Service(
			new AIMS_Event_Repository(),
			new AIMS_Square_Sale_Repository(),
			new AIMS_Vendor_Event_Assignment_Repository(),
			new AIMS_Event_Financial_Service(
				new AIMS_Event_Repository(),
				new AIMS_Square_Sale_Repository(),
				new AIMS_Event_Expense_Repository(),
				new AIMS_Vendor_Event_Assignment_Repository(),
				new AIMS_Product_Cost_Service(
					new AIMS_Product_Cost_Rule_Repository()
				)
			),
			$this->vendor_access,
			$this->audit,
			$this->auth_context
		);
		$this->events       = $events ?: new AIMS_Event_Repository();
		$this->assignments  = $assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->vendors      = $vendor_repository;
		$this->scope_resolver = $scope_resolver ?: new AIMS_Admin_Scope_Resolver(
			new AIMS_Bucket_Access_Repository(),
			new AIMS_Inventory_Bucket_Repository(),
			$this->vendor_access,
			$this->vendors,
			$this->auth_context
		);
	}

	public function get_rows(): array {
		$rows = array();
		$visible_event_ids = $this->get_visible_event_ids();

		if ( empty( $visible_event_ids ) ) {
			return array();
		}

		foreach ( $this->events->all() as $event ) {
			$event_id = (int) ( $event['id'] ?? 0 );
			if ( $event_id <= 0 || ! $this->event_is_visible( $event_id, $visible_event_ids ) ) {
				continue;
			}

			$model = $this->event_automation->get_participation_model_for_event( $event_id );
			$rows[] = $this->merge_event_model( $event, $model );
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$left_key  = (string) ( $left['start_date'] ?? '' ) . '|' . (string) ( $left['event_name'] ?? '' );
				$right_key = (string) ( $right['start_date'] ?? '' ) . '|' . (string) ( $right['event_name'] ?? '' );

				return strcmp( $left_key, $right_key );
			}
		);

		return $rows;
	}

	public function get_summary(): array {
		$summary = array(
			'open_for_request'  => 0,
			'partially_assigned' => 0,
			'request_closed'    => 0,
			'fully_assigned'    => 0,
			'draft'             => 0,
		);

		foreach ( $this->get_rows() as $row ) {
			$status = ! empty( $row['participation_status'] ) ? (string) $row['participation_status'] : 'draft';
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}

		return $summary;
	}

	public function get_event_bundle( int $event_id ): array {
		$event = $this->events->find_by_id( $event_id );
		if ( empty( $event ) || ! $this->event_is_visible( $event_id ) ) {
			return array();
		}

		$model = $this->event_automation->get_participation_model_for_event( $event_id );
		$vendor_map = $this->get_vendor_label_map();
		$request_queue = $this->enrich_assignments( $this->event_automation->get_request_queue_for_event( $event_id ), $vendor_map );
		$authorized_assignments = $this->enrich_assignments( $this->event_automation->get_authorized_assignments_for_event( $event_id ), $vendor_map );

		return array(
			'event'                  => $event,
			'model'                  => $model,
			'request_queue'          => $request_queue,
			'request_queue_head'      => ! empty( $request_queue ) ? $request_queue[0] : array(),
			'authorized_assignments'  => $authorized_assignments,
			'vendor_options'         => $this->get_vendor_options(),
			'actionability'          => $this->build_actionability( $model, $request_queue, $authorized_assignments ),
		);
	}

	public function get_vendor_options(): array {
		$options = array();
		$vendors = $this->get_visible_vendors();

		foreach ( $vendors as $vendor ) {
			$vendor_id = (int) ( $vendor['id'] ?? 0 );
			if ( $vendor_id <= 0 ) {
				continue;
			}

			$options[] = array(
				'id'    => $vendor_id,
				'label' => $this->build_vendor_label( $vendor ),
			);
		}

		return $options;
	}

	private function merge_event_model( array $event, array $model ): array {
		$vendor_capacity  = (int) ( $event['vendor_capacity'] ?? 0 );
		$authorized_count = (int) ( $model['authorized_count'] ?? 0 );
		$remaining       = $vendor_capacity > 0 ? max( 0, $vendor_capacity - $authorized_count ) : 0;
		$request_count    = (int) ( $model['request_count'] ?? 0 );
		$state_label      = $this->build_participation_state_label( $model );

		return array_merge(
			$event,
			$model,
			array(
				'event_id'             => (int) ( $event['id'] ?? 0 ),
				'state_label'          => $state_label,
				'capacity_label'       => $this->build_capacity_label( $vendor_capacity, $authorized_count ),
				'remaining_capacity'   => $remaining,
				'request_window_label'  => $this->build_request_window_label( $model ),
				'vendor_count_label'    => $this->build_vendor_count_label( $authorized_count, $vendor_capacity ),
				'request_status_label'  => $this->build_request_status_label( $model, $request_count ),
			)
		);
	}

	private function enrich_assignments( array $assignments, array $vendor_map ): array {
		$rows = array();

		foreach ( $assignments as $assignment ) {
			$vendor_id = (int) ( $assignment['vendor_id'] ?? 0 );
			$assignment['vendor_name'] = $vendor_map[ $vendor_id ] ?? ( $vendor_id > 0 ? 'Vendor #' . $vendor_id : 'Unlinked vendor' );
			$rows[] = $assignment;
		}

		return $rows;
	}

	private function get_vendor_label_map(): array {
		$map = array();
		$vendors = $this->get_visible_vendors();

		foreach ( $vendors as $vendor ) {
			$vendor_id = (int) ( $vendor['id'] ?? 0 );
			if ( $vendor_id <= 0 ) {
				continue;
			}

			$map[ $vendor_id ] = $this->build_vendor_label( $vendor );
		}

		return $map;
	}

	private function get_visible_vendors(): array {
		$vendors = $this->scope_resolver->get_accessible_vendors( (int) get_current_user_id() );

		return is_array( $vendors ) ? $vendors : array();
	}

	private function get_visible_event_ids(): array {
		$user_id = (int) get_current_user_id();
		$scope = $this->scope_resolver->get_accessible_scope_ids( $user_id );

		if ( ! empty( $scope['all'] ) ) {
			$event_ids = array();

			foreach ( $this->events->all() as $event ) {
				if ( ! empty( $event['id'] ) ) {
					$event_ids[] = (int) $event['id'];
				}
			}

			return array_values( array_unique( array_filter( $event_ids ) ) );
		}

		$visible_event_ids = array();
		$scope_event_ids   = ! empty( $scope['event_ids'] ) ? array_map( 'intval', (array) $scope['event_ids'] ) : array();
		$visible_event_ids  = array_merge( $visible_event_ids, $scope_event_ids );

		$vendor_ids = ! empty( $scope['vendor_ids'] ) ? array_map( 'intval', (array) $scope['vendor_ids'] ) : array();
		if ( ! empty( $vendor_ids ) ) {
			$vendor_lookup = array_fill_keys( $vendor_ids, true );

			foreach ( $this->events->all() as $event ) {
				$event_id = (int) ( $event['id'] ?? 0 );
				if ( $event_id <= 0 ) {
					continue;
				}

				foreach ( $this->assignments->get_all_for_event( $event_id ) as $assignment ) {
					$assignment_vendor_id = (int) ( $assignment['vendor_id'] ?? 0 );
					if ( $assignment_vendor_id > 0 && isset( $vendor_lookup[ $assignment_vendor_id ] ) ) {
						$visible_event_ids[] = $event_id;
						break;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $visible_event_ids ) ) ) );
	}

	private function event_is_visible( int $event_id, array $visible_event_ids = array() ): bool {
		if ( $event_id <= 0 ) {
			return false;
		}

		if ( empty( $visible_event_ids ) ) {
			$visible_event_ids = $this->get_visible_event_ids();
		}

		return in_array( $event_id, $visible_event_ids, true );
	}

	private function build_vendor_label( array $vendor ): string {
		$name = ! empty( $vendor['vendor_name'] ) ? (string) $vendor['vendor_name'] : 'Vendor';
		$id   = (int) ( $vendor['id'] ?? 0 );

		return $name . ' (#' . $id . ')';
	}

	private function build_capacity_label( int $capacity, int $authorized_count ): string {
		if ( 0 === $capacity ) {
			return 'Unlimited';
		}

		return $authorized_count . ' / ' . $capacity;
	}

	private function build_vendor_count_label( int $authorized_count, int $capacity ): string {
		if ( 0 === $capacity ) {
			return (string) $authorized_count;
		}

		return $authorized_count . ' of ' . $capacity;
	}

	private function build_request_window_label( array $model ): string {
		if ( ! empty( $model['is_open_for_request'] ) ) {
			return 'Open for request';
		}

		if ( ! empty( $model['is_fully_assigned'] ) ) {
			return 'Fully assigned';
		}

		if ( ! empty( $model['is_request_closed'] ) ) {
			return 'Request closed';
		}

		return 'Draft';
	}

	private function build_participation_state_label( array $model ): string {
		if ( ! empty( $model['is_shipped'] ) ) {
			return 'Shipped';
		}

		if ( ! empty( $model['is_open_for_request'] ) ) {
			return ! empty( $model['has_capacity_remaining'] ) ? 'Open for requests' : 'Open, but at capacity';
		}

		if ( ! empty( $model['is_fully_assigned'] ) ) {
			return 'Fully assigned';
		}

		if ( ! empty( $model['is_request_closed'] ) ) {
			return 'Request closed';
		}

		return 'Draft';
	}

	private function build_request_status_label( array $model, int $request_count = 0 ): string {
		if ( ! empty( $model['can_accept_requests'] ) ) {
			return $request_count > 0 ? 'Accepting requests' : 'Open and waiting';
		}

		if ( ! empty( $model['is_fully_assigned'] ) ) {
			return 'Capacity reached';
		}

		if ( ! empty( $model['is_request_closed'] ) ) {
			return 'Closed';
		}

		return 'Waiting';
	}

	private function build_actionability( array $model, array $request_queue, array $authorized_assignments ): array {
		$next_request = ! empty( $request_queue ) ? $request_queue[0] : array();
		$remaining_capacity = (int) ( $model['remaining_capacity'] ?? 0 );
		$actor_user_id = (int) get_current_user_id();
		$can_manage = $this->event_automation->user_can_manage_event_participation( $actor_user_id );
		$can_open_requests = $can_manage && ! empty( $model['has_capacity_remaining'] ) && empty( $model['is_open_for_request'] );
		$can_close_requests = $can_manage && ! empty( $model['is_open_for_request'] );
		$can_approve_next   = $can_manage && ! empty( $next_request ) && ! empty( $model['has_capacity_remaining'] );
		$can_manual_assign  = $can_manage;
		$manual_assignment_label = $can_manual_assign
			? ( $remaining_capacity > 0 ? 'Manual fallback allowed' : 'Manual fallback override' )
			: 'Manual fallback unavailable';

		return array(
			'can_manage'           => $can_manage,
			'can_accept_requests'  => $can_manage && ! empty( $model['can_accept_requests'] ),
			'can_open_requests'    => $can_open_requests,
			'can_close_requests'   => $can_close_requests,
			'can_approve_next'     => $can_approve_next,
			'can_manual_assign'    => $can_manual_assign,
			'manual_assignment_label' => $manual_assignment_label,
			'queue_count'          => count( $request_queue ),
			'authorized_count'     => count( $authorized_assignments ),
			'remaining_capacity'   => $remaining_capacity,
			'request_status_label'  => $this->build_request_status_label( $model, count( $request_queue ) ),
			'next_request_vendor'   => ! empty( $next_request['vendor_name'] ) ? (string) $next_request['vendor_name'] : '',
			'next_request_sequence' => ! empty( $next_request['request_sequence'] ) ? (int) $next_request['request_sequence'] : 0,
		);
	}
}
