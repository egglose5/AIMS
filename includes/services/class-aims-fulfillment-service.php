<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Fulfillment_Service {
	private $allocations;
	private $shipping_workflow;

	public function __construct(
		AIMS_Sale_Fulfillment_Allocation_Repository $allocations,
		AIMS_Shipping_Workflow_Service $shipping_workflow = null
	) {
		$this->allocations = $allocations;
		$this->shipping_workflow = $shipping_workflow;
	}

	public function create_allocation( array $data ): int {
		$data['allocation_status'] = $this->allocations->normalize_status( (string) ( $data['allocation_status'] ?? AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_ALLOCATED ) );
		$data['allocation_type']   = $this->allocations->normalize_allocation_type( (string) ( $data['allocation_type'] ?? AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_EVENT_STOCK ) );

		return $this->allocations->save( $data );
	}

	public function create_backorder_allocation( array $data ): int {
		$data = wp_parse_args(
			$data,
			array(
				'allocation_type'   => AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_WAREHOUSE_BACKORDER,
				'allocation_status' => AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_BACKORDERED,
			)
		);

		return $this->create_allocation( $data );
	}

	public function route_sale_allocation(
		array $sale,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): array {
		if ( null === $this->shipping_workflow ) {
			return array(
				'status'        => AIMS_Square_Sale_Repository::STATUS_PENDING,
				'allocation_id'  => 0,
			);
		}

		$status = $this->shipping_workflow->determine_status( $sale, $customer, $shipping_address, $context );
		$allocation_id = $this->shipping_workflow->create_allocation_for_sale( $sale, $status, $context );

		return array(
			'status'        => $status,
			'allocation_id'  => $allocation_id,
		);
	}
}
