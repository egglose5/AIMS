<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Participation_Data_Provider {
	private $event_automation;
	private $events;
	private $assignments;
	private $vendors;

	public function __construct(
		AIMS_Event_Automation_Service $event_automation = null,
		AIMS_Event_Repository $events = null,
		AIMS_Vendor_Event_Assignment_Repository $assignments = null,
		AIMS_Vendor_Repository $vendors = null
	) {
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
			)
		);
		$this->events       = $events ?: new AIMS_Event_Repository();
		$this->assignments  = $assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
		$this->vendors      = $vendors ?: new AIMS_Vendor_Repository();
	}

	public function get_rows(): array {
		$rows = array();

		foreach ( $this->events->all() as $event ) {
			$event_id = (int) ( $event['id'] ?? 0 );
			if ( $event_id <= 0 ) {
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
		if ( empty( $event ) ) {
			return array();
		}

		$model = $this->event_automation->get_participation_model_for_event( $event_id );
		$vendor_map = $this->get_vendor_label_map();

		return array(
			'event'              => $event,
			'model'              => $model,
			'request_queue'      => $this->enrich_assignments( $this->event_automation->get_request_queue_for_event( $event_id ), $vendor_map ),
			'authorized_assignments' => $this->enrich_assignments( $this->event_automation->get_authorized_assignments_for_event( $event_id ), $vendor_map ),
			'vendor_options'     => $this->get_vendor_options(),
		);
	}

	public function get_vendor_options(): array {
		$options = array();

		foreach ( $this->vendors->all() as $vendor ) {
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

		return array_merge(
			$event,
			$model,
			array(
				'event_id'             => (int) ( $event['id'] ?? 0 ),
				'capacity_label'       => $this->build_capacity_label( $vendor_capacity, $authorized_count ),
				'request_window_label'  => $this->build_request_window_label( $model ),
				'vendor_count_label'    => $this->build_vendor_count_label( $authorized_count, $vendor_capacity ),
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

		foreach ( $this->vendors->all() as $vendor ) {
			$vendor_id = (int) ( $vendor['id'] ?? 0 );
			if ( $vendor_id <= 0 ) {
				continue;
			}

			$map[ $vendor_id ] = $this->build_vendor_label( $vendor );
		}

		return $map;
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
}
