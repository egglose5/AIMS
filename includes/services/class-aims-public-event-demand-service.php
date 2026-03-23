<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Public_Event_Demand_Service {
	private $intake;
	private $planning;
	private $requests;
	private $request_items;

	public function __construct(
		AIMS_Event_Demand_Intake_Service $intake,
		AIMS_Event_Demand_Planning_Service $planning,
		$requests = null,
		$request_items = null
	) {
		$this->intake        = $intake;
		$this->planning      = $planning;
		$this->requests      = $requests;
		$this->request_items = $request_items;
	}

	public function submit_request( array $data ) {
		return $this->intake->intake_request( $data );
	}

	public function summarize_event_demand( int $event_id, array $requests = array() ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		if ( empty( $requests ) ) {
			$requests = $this->load_requests_for_event( $event_id );
		}

		return $this->planning->summarize_requests( $requests, $event_id );
	}

	public function summarize_persisted_event_demand( int $event_id ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		return $this->planning->summarize_event_demand( $event_id );
	}

	private function load_requests_for_event( int $event_id ): array {
		$items = $this->load_request_items_for_event( $event_id );
		if ( ! empty( $items ) ) {
			return $items;
		}

		if ( ! is_object( $this->requests ) ) {
			return array();
		}

		foreach ( array( 'get_for_event', 'all_for_event', 'find_by_event_id' ) as $method ) {
			if ( method_exists( $this->requests, $method ) ) {
				$rows = $this->requests->{$method}( $event_id );

				return is_array( $rows ) ? $rows : array();
			}
		}

		return array();
	}

	private function load_request_items_for_event( int $event_id ): array {
		if ( ! is_object( $this->request_items ) ) {
			return array();
		}

		if ( method_exists( $this->request_items, 'get_demand_summary_for_event' ) ) {
			$rows = (array) $this->request_items->get_demand_summary_for_event( $event_id );
			$mapped = array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$mapped[] = array(
					'event_id'           => (int) ( $row['event_id'] ?? 0 ),
					'woo_product_id'     => (int) ( $row['woo_product_id'] ?? 0 ),
					'product_sku'        => sanitize_text_field( $row['product_sku'] ?? '' ),
					'product_name'       => sanitize_text_field( $row['product_name'] ?? '' ),
					'quantity_requested' => (float) ( $row['total_quantity_requested'] ?? 0 ),
					'request_status'     => 'auto_accepted',
					'item_status'        => 'planning_signal',
					'intake_status'      => 'auto_accepted',
					'demand_signal_type' => 'planning_only',
					'request_source'     => 'public_event_demand_form',
				);
			}

			return $mapped;
		}

		return array();
	}
}
