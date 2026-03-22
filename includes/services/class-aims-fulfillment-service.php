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
		$data = array_merge( $this->normalize_bucket_context( $data ), $data );
		$data['allocation_status'] = $this->allocations->normalize_status( (string) ( $data['allocation_status'] ?? AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_ALLOCATED ) );
		$data['allocation_type']   = $this->allocations->normalize_allocation_type( (string) ( $data['allocation_type'] ?? AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_EVENT_STOCK ) );

		return $this->allocations->save( $data );
	}

	public function create_backorder_allocation( array $data ): int {
		$data = array_merge( $this->normalize_bucket_context( $data ), $data );
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
				'sale_id'           => (int) ( $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
				'status'            => AIMS_Square_Sale_Repository::STATUS_PENDING,
				'allocation_id'     => 0,
				'allocation_type'   => AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_EVENT_STOCK,
				'allocation_status' => AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_PENDING,
				'allocation_payload' => $this->normalize_bucket_context( $context ),
			);
		}

		return $this->shipping_workflow->process_sale_workflow(
			(int) ( $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
			$sale,
			$customer,
			$shipping_address,
			$context
		);
	}

	private function normalize_bucket_context( array $context ): array {
		$bucket = array();

		foreach ( array( 'bucket', 'inventory_bucket', 'source_bucket' ) as $key ) {
			if ( empty( $context[ $key ] ) ) {
				continue;
			}

			if ( is_array( $context[ $key ] ) ) {
				$bucket = $context[ $key ];
				break;
			}

			if ( is_object( $context[ $key ] ) ) {
				$bucket = get_object_vars( $context[ $key ] );
				break;
			}
		}

		$bucket_id = (int) ( $context['source_bucket_id'] ?? $context['bucket_id'] ?? $bucket['id'] ?? 0 );
		$bucket_code = sanitize_text_field( $context['source_bucket_code'] ?? $context['bucket_code'] ?? $bucket['bucket_code'] ?? '' );
		$bucket_name = sanitize_text_field( $context['source_bucket_name'] ?? $context['bucket_name'] ?? $bucket['bucket_name'] ?? $bucket_code );

		return array(
			'source_bucket_id'   => $bucket_id,
			'source_bucket_code' => $bucket_code,
			'source_bucket_name' => $bucket_name,
		);
	}
}
