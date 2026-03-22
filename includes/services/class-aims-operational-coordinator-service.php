<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Operational_Coordinator_Service {
	private $square_import;
	private $event_automation;
	private $shipping_workflow;
	private $sales;

	public function __construct(
		AIMS_Square_Import_Service $square_import,
		AIMS_Event_Automation_Service $event_automation,
		AIMS_Shipping_Workflow_Service $shipping_workflow,
		AIMS_Square_Sale_Repository $sales
	) {
		$this->square_import    = $square_import;
		$this->event_automation  = $event_automation;
		$this->shipping_workflow = $shipping_workflow;
		$this->sales             = $sales;
	}

	public function process_square_payload( array $payload ): array {
		// This is the orchestration seam: import, assign, route, then recalculate in one controlled path.
		$intake = $this->square_import->persist_queue_to_sales_flow( $payload );
		$analysis = ! empty( $intake['analysis'] ) && is_array( $intake['analysis'] ) ? $intake['analysis'] : array();
		$sale_ids = ! empty( $intake['sale_ids'] ) && is_array( $intake['sale_ids'] ) ? $intake['sale_ids'] : array();

		$assignment_results    = array();
		$routing_results       = array();
		$recalculation_results = array();

		foreach ( $sale_ids as $sale_id ) {
			$sale_id = (int) $sale_id;
			if ( $sale_id <= 0 ) {
				continue;
			}

			$assignment_results[] = $this->event_automation->assign_sale_by_id( $sale_id );
		}

		foreach ( $sale_ids as $sale_id ) {
			$sale_id = (int) $sale_id;
			if ( $sale_id <= 0 ) {
				continue;
			}

			$sale = $this->sales->find_by_id( $sale_id );
			if ( empty( $sale ) ) {
				continue;
			}

			$customer = ! empty( $analysis['customer_data'] ) && is_array( $analysis['customer_data'] ) ? $analysis['customer_data'] : array();
			$shipping_address = ! empty( $analysis['address_data'] ) && is_array( $analysis['address_data'] ) ? $analysis['address_data'] : array();
			$context = $this->build_operational_context( $analysis, $sale );

			$routing_results[] = $this->shipping_workflow->process_sale_by_id(
				$sale_id,
				$customer,
				$shipping_address,
				$context
			);

			if ( ! empty( $sale['event_id'] ) ) {
				$recalculation_results[ (int) $sale['event_id'] ] = $this->event_automation->recalculate_for_event( (int) $sale['event_id'] );
			}
		}

		return array(
			'intake'                => $intake,
			'assignment_results'    => $assignment_results,
			'routing_results'       => $routing_results,
			'recalculation_results' => array_values( $recalculation_results ),
		);
	}

	public function process_sale_id( int $sale_id ): array {
		$sale = $this->sales->find_by_id( $sale_id );
		if ( empty( $sale ) ) {
			return array(
				'sale_id' => $sale_id,
				'status'  => 'missing',
			);
		}

		$assignment = $this->event_automation->assign_sale_by_id( $sale_id );
		$customer = array();
		$shipping_address = array();
		$context = $this->build_operational_context( array(), $sale );

		return array(
			'sale_id'            => $sale_id,
			'assignment'         => $assignment,
			'fulfillment_result' => $this->shipping_workflow->process_sale_by_id( $sale_id, $customer, $shipping_address, $context ),
		);
	}

	public function process_unassigned_sales_for_location_date( string $square_location_id, string $sold_at ): array {
		$results = $this->event_automation->assign_unassigned_sales_for_location_date( $square_location_id, $sold_at );
		$assigned_sales = $this->sales->get_unassigned_sales_by_location_and_date( $square_location_id, $sold_at );

		return array(
			'event_results' => $results,
			'sales_scanned'  => count( $assigned_sales ),
			'location_id'    => sanitize_text_field( $square_location_id ),
			'sold_at'        => sanitize_text_field( $sold_at ),
		);
	}

	private function build_operational_context( array $analysis, array $sale ): array {
		$shipping_marker = ! empty( $analysis['shipping_marker'] ) && is_array( $analysis['shipping_marker'] )
			? $analysis['shipping_marker']
			: array();
		$event_id = ! empty( $sale['event_id'] ) ? (int) $sale['event_id'] : 0;
		$assignment_model = $event_id > 0 ? $this->event_automation->get_assignment_model_for_event( $event_id ) : array();
		$participation_policy = ! empty( $assignment_model['policy'] ) ? (string) $assignment_model['policy'] : 'request_first';

		// The routing context is intentionally explicit so shipping logic does not guess from ambient state.
		return array(
			'shipping_marker_present'  => ! empty( $shipping_marker['has_aims_shipping_marker'] ),
			'inventory_shortfall'      => ! empty( $sale['fulfillment_status'] ) && 'backordered' === $sale['fulfillment_status'],
			'warehouse_fulfillment_required' => ! empty( $shipping_marker['has_aims_shipping_marker'] ),
			'shipped'                  => ! empty( $sale['fulfillment_status'] ) && 'shipped' === $sale['fulfillment_status'],
			'source_bucket_code'       => ! empty( $shipping_marker['has_aims_shipping_marker'] ) ? 'warehouse' : 'event',
			'bucket_first_inventory'   => true,
			'event_participation_policy' => $participation_policy,
			'event_participation_state'  => $participation_policy,
			'notes'                    => ! empty( $shipping_marker['has_aims_shipping_marker'] )
				? 'Orchestrated from bucket-first warehouse inventory.'
				: 'Orchestrated from bucket-first event inventory.',
		);
	}
}
