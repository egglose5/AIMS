<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Automation_Service {
	private $events;
	private $sales;
	private $assignments;
	private $financials;

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

		return $this->events->find_matching_event( $square_location_id, $sold_at );
	}

	public function assign_sale_to_matching_event( array $sale ): ?array {
		if ( ! empty( $sale['event_id'] ) && (int) $sale['event_id'] > 0 ) {
			return null;
		}

		$matched_event = $this->match_sale_to_event( $sale );

		if ( empty( $matched_event['id'] ) || empty( $sale['id'] ) ) {
			return null;
		}

		if ( ! $this->apply_sale_assignment( $sale, $matched_event, true ) ) {
			return null;
		}

		return $matched_event;
	}

	public function assign_sale_by_id( int $sale_id ): ?array {
		$sale = $this->sales->find_by_id( $sale_id );

		if ( empty( $sale ) ) {
			return null;
		}

		return $this->assign_sale_to_matching_event( $sale );
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

			if ( ! empty( $sale['event_id'] ) && (int) $sale['event_id'] > 0 ) {
				continue;
			}

			$matched_event = $this->match_sale_to_event( $sale );

			if ( empty( $matched_event['id'] ) ) {
				continue;
			}

			if ( $this->apply_sale_assignment( $sale, $matched_event, false ) ) {
				$results['assigned']++;
				$results['events'][ (int) $matched_event['id'] ] = true;
			}
		}

		foreach ( array_keys( $results['events'] ) as $event_id ) {
			$this->recalculate_after_assignment( (int) $event_id );
		}

		$results['events'] = array_keys( $results['events'] );

		return $results;
	}

	public function assign_unassigned_sales_for_location_date( string $square_location_id, string $sold_at ): array {
		$sales = $this->sales->get_unassigned_sales_by_location_and_date( $square_location_id, $sold_at );

		return $this->process_unassigned_sales_batch( $sales );
	}

	public function reconcile_sales_for_event_window( string $square_location_id, string $sold_at ): int {
		$matched_event = $this->events->find_matching_event( $square_location_id, $sold_at );

		if ( empty( $matched_event['id'] ) ) {
			return 0;
		}

		$sales = $this->sales->get_unassigned_sales_by_location_and_date( $square_location_id, $sold_at );
		$vendor_id  = $this->assignments->get_vendor_id_for_event( (int) $matched_event['id'] );
		$assigned_count = 0;

		foreach ( $sales as $sale ) {
			if ( $this->apply_assignment_to_sale(
				(int) $sale['id'],
				(int) $matched_event['id'],
				$vendor_id
			) ) {
				$assigned_count++;
			}
		}

		if ( $assigned_count > 0 ) {
			$this->recalculate_after_assignment( (int) $matched_event['id'] );
		}

		return $assigned_count;
	}

	public function recalculate_after_assignment( int $event_id ): array {
		return $this->financials->recalculate_event( $event_id );
	}

	private function apply_sale_assignment( array $sale, array $matched_event, bool $recalculate = true ): bool {
		$vendor_id  = $this->assignments->get_vendor_id_for_event( (int) $matched_event['id'] );

		$assigned = $this->apply_assignment_to_sale(
			(int) $sale['id'],
			(int) $matched_event['id'],
			$vendor_id
		);

		if ( $assigned && $recalculate ) {
			$this->recalculate_after_assignment( (int) $matched_event['id'] );
		}

		return $assigned;
	}

	private function apply_assignment_to_sale( int $sale_id, int $event_id, int $vendor_id ): bool {
		return $this->sales->assign_event( $sale_id, $event_id, $vendor_id );
	}
}
