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
			$status = $this->determine_fallback_status( $sale, $customer, $shipping_address, $context );
			return array(
				'sale_id'            => (int) ( $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
				'status'             => $status,
				'status_label'       => $this->describe_status( $status ),
				'routing_reason'     => $this->build_fallback_reason( $status, $customer, $shipping_address, $context ),
				'allocation_id'      => 0,
				'allocation_type'    => $this->derive_allocation_type( $status ),
				'allocation_status'  => $this->derive_allocation_status( $status ),
				'allocation_payload' => array_merge(
					$this->normalize_bucket_context( $context ),
					array(
						'square_sale_id'        => (int) ( $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
						'square_order_id'       => sanitize_text_field( $sale['square_order_id'] ?? '' ),
						'status_label'          => $this->describe_status( $status ),
						'routing_reason'        => $this->build_fallback_reason( $status, $customer, $shipping_address, $context ),
						'customer_ready'        => $this->has_required_customer_data( $customer ),
						'shipping_address_ready' => $this->has_full_shipping_address( $shipping_address ),
						'shipping_marker_present' => ! empty( $context['shipping_marker_present'] ),
					)
				),
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

	private function determine_fallback_status( array $sale, array $customer = array(), array $shipping_address = array(), array $context = array() ): string {
		$current_status = $this->normalize_status( (string) ( $sale['fulfillment_status'] ?? AIMS_Square_Sale_Repository::STATUS_PENDING ) );

		if ( ! empty( $context['shipped'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_SHIPPED;
		}

		if ( ! empty( $context['inventory_shortfall'] ) || ! empty( $context['warehouse_fulfillment_required'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_BACKORDERED;
		}

		if ( ! empty( $context['shipping_marker_present'] ) ) {
			if ( $this->has_required_customer_data( $customer ) && $this->has_full_shipping_address( $shipping_address ) ) {
				return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING;
			}

			return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO;
		}

		return $this->is_routed_status( $current_status )
			? $current_status
			: AIMS_Square_Sale_Repository::STATUS_FULFILLED;
	}

	private function describe_status( string $status ): string {
		switch ( $this->normalize_status( $status ) ) {
			case AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING:
				return 'Needs shipping';
			case AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO:
				return 'Needs shipping info';
			case AIMS_Square_Sale_Repository::STATUS_BACKORDERED:
				return 'Backordered';
			case AIMS_Square_Sale_Repository::STATUS_SHIPPED:
				return 'Shipped';
			case AIMS_Square_Sale_Repository::STATUS_FULFILLED:
				return 'Fulfilled';
			default:
				return 'Pending';
		}
	}

	private function build_fallback_reason( string $status, array $customer, array $shipping_address, array $context ): string {
		if ( AIMS_Square_Sale_Repository::STATUS_SHIPPED === $this->normalize_status( $status ) ) {
			return 'Marked shipped at intake.';
		}

		if ( AIMS_Square_Sale_Repository::STATUS_BACKORDERED === $this->normalize_status( $status ) ) {
			return 'Warehouse fulfillment required from fallback routing.';
		}

		if ( AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO === $this->normalize_status( $status ) ) {
			$missing = array();
			if ( ! $this->has_required_customer_data( $customer ) ) {
				$missing[] = 'customer contact';
			}
			if ( ! $this->has_full_shipping_address( $shipping_address ) ) {
				$missing[] = 'shipping address';
			}

			return 'Missing ' . implode( ' and ', $missing ) . '.';
		}

		if ( AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING === $this->normalize_status( $status ) ) {
			return ! empty( $context['shipping_marker_present'] ) ? 'Shipping marker present and contact info complete.' : 'Warehouse shipment queued.';
		}

		return 'Fulfilled on site.';
	}

	private function derive_allocation_type( string $status ): string {
		$status = $this->normalize_status( $status );

		if ( AIMS_Square_Sale_Repository::STATUS_BACKORDERED === $status ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_WAREHOUSE_BACKORDER;
		}

		if ( in_array( $status, array( AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING, AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO ), true ) ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_WAREHOUSE_PICK;
		}

		return AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_EVENT_STOCK;
	}

	private function derive_allocation_status( string $status ): string {
		$status = $this->normalize_status( $status );

		if ( AIMS_Square_Sale_Repository::STATUS_SHIPPED === $status ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_SHIPPED;
		}

		if ( AIMS_Square_Sale_Repository::STATUS_BACKORDERED === $status ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_BACKORDERED;
		}

		if ( in_array( $status, array( AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING, AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO ), true ) ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_PENDING;
		}

		return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_ALLOCATED;
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array(
			$status,
			array(
				AIMS_Square_Sale_Repository::STATUS_PENDING,
				AIMS_Square_Sale_Repository::STATUS_FULFILLED,
				AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING,
				AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO,
				AIMS_Square_Sale_Repository::STATUS_BACKORDERED,
				AIMS_Square_Sale_Repository::STATUS_SHIPPED,
			),
			true
		) ? $status : AIMS_Square_Sale_Repository::STATUS_PENDING;
	}

	private function is_routed_status( string $status ): bool {
		return in_array(
			$this->normalize_status( $status ),
			array(
				AIMS_Square_Sale_Repository::STATUS_FULFILLED,
				AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING,
				AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO,
				AIMS_Square_Sale_Repository::STATUS_BACKORDERED,
				AIMS_Square_Sale_Repository::STATUS_SHIPPED,
			),
			true
		);
	}

	private function has_required_customer_data( array $customer ): bool {
		return '' !== trim( (string) ( $customer['first_name'] ?? '' ) )
			&& '' !== trim( (string) ( $customer['last_name'] ?? '' ) )
			&& ( '' !== trim( (string) ( $customer['email_address'] ?? '' ) ) || '' !== trim( (string) ( $customer['phone_number'] ?? '' ) ) );
	}

	private function has_full_shipping_address( array $shipping_address ): bool {
		return '' !== trim( (string) ( $shipping_address['address_line_1'] ?? '' ) )
			&& '' !== trim( (string) ( $shipping_address['city'] ?? '' ) )
			&& '' !== trim( (string) ( $shipping_address['state_region'] ?? '' ) )
			&& '' !== trim( (string) ( $shipping_address['postal_code'] ?? '' ) )
			&& '' !== trim( (string) ( $shipping_address['country_code'] ?? '' ) );
	}
}
