<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Automation_Service {
	private $events;
	private $sales;
	private $assignments;
	private $financials;
	private $event_locations;

	public function __construct(
		AIMS_Event_Repository $events,
		AIMS_Square_Sale_Repository $sales,
		AIMS_Vendor_Event_Assignment_Repository $assignments,
		AIMS_Event_Financial_Service $financials
	) {
		$this->events      = $events;
		$this->sales       = $sales;
		$this->assignments = $assignments;
		$this->financials  = $financials;
	}

	public function match_sale_to_event( array $sale ): ?array {
		$square_location_id = (string) ( $sale['square_location_id'] ?? '' );
		$sold_at            = (string) ( $sale['sold_at'] ?? '' );

		if ( '' === $square_location_id || '' === $sold_at ) {
			return null;
		}

		return $this->find_matching_event_by_location_and_date( $square_location_id, $sold_at );
	}

	public function assign_sale_to_matching_event( array $sale ): ?array {
		$matched_event = $this->match_sale_to_event( $sale );

		if ( empty( $matched_event['id'] ) || empty( $sale['id'] ) ) {
			return null;
		}

		$this->apply_sale_assignment( $sale, $matched_event );

		return $matched_event;
	}

	public function recalculate_for_event( int $event_id ): array {
		return $this->financials->recalculate_event( $event_id );
	}

	public function process_unassigned_sales_batch( array $sales ): array {
		$results = array(
			'processed' => 0,
			'assigned'  => 0,
			'events'    => array(),
		);

		foreach ( $sales as $sale ) {
			$results['processed']++;
			$matched_event = $this->assign_sale_to_matching_event( $sale );

			if ( empty( $matched_event['id'] ) ) {
				continue;
			}

			$results['assigned']++;
			$results['events'][ (int) $matched_event['id'] ] = true;
		}

		$results['events'] = array_keys( $results['events'] );

		return $results;
	}

	public function reconcile_sales_for_event_window( string $square_location_id, string $sold_at ): int {
		$matched_event = $this->find_matching_event_by_location_and_date( $square_location_id, $sold_at );

		if ( empty( $matched_event['id'] ) ) {
			return 0;
		}

		$sales          = $this->sales->get_unassigned_sales_by_location_and_date( $square_location_id, $sold_at );
		$assigned_count = 0;

		foreach ( $sales as $sale ) {
			$vendor_id = $this->resolve_vendor_id_for_sale_assignment( $sale, $matched_event );

			if ( $this->apply_assignment_to_sale(
				(int) $sale['id'],
				(int) $matched_event['id'],
				$vendor_id
			) ) {
				$assigned_count++;
			}
		}

		if ( $assigned_count > 0 ) {
			$this->recalculate_for_event( (int) $matched_event['id'] );
		}

		return $assigned_count;
	}

	public function recalculate_after_assignment( int $event_id ): array {
		return $this->financials->recalculate_event( $event_id );
	}

	private function apply_sale_assignment( array $sale, array $matched_event ): bool {
		$vendor_id = $this->resolve_vendor_id_for_sale_assignment( $sale, $matched_event );

		$assigned = $this->apply_assignment_to_sale(
			(int) $sale['id'],
			(int) $matched_event['id'],
			$vendor_id
		);

		if ( $assigned ) {
			$this->recalculate_after_assignment( (int) $matched_event['id'] );
		}

		return $assigned;
	}

	private function apply_assignment_to_sale( int $sale_id, int $event_id, int $vendor_id ): bool {
		return $this->sales->assign_event( $sale_id, $event_id, $vendor_id );
	}

	private function resolve_vendor_id_for_sale_assignment( array $sale, array $matched_event ): int {
		$existing_vendor_id = (int) ( $sale['vendor_id'] ?? 0 );

		if ( $existing_vendor_id > 0 ) {
			return $existing_vendor_id;
		}

		$assignment = $this->assignments->get_primary_for_event( (int) $matched_event['id'] );

		return ! empty( $assignment['vendor_id'] ) ? (int) $assignment['vendor_id'] : 0;
	}

	private function find_matching_event_by_location_and_date( string $square_location_id, string $sold_at ): ?array {
		$event_locations = $this->get_event_locations_repository();

		if ( null !== $event_locations && method_exists( $event_locations, 'find_matching_event' ) ) {
			$event = $event_locations->find_matching_event( $square_location_id, $sold_at );

			if ( ! empty( $event ) ) {
				return $event;
			}
		}

		return $this->events->find_matching_event( $square_location_id, $sold_at );
	}

	private function get_event_locations_repository() {
		if ( null !== $this->event_locations ) {
			return $this->event_locations;
		}

		if ( class_exists( 'AIMS_Event_Square_Location_Repository' ) ) {
			$this->event_locations = new AIMS_Event_Square_Location_Repository();
		}

		return $this->event_locations;
	}
}
