<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Shipping_Workflow_Service {
	private $sales;
	private $allocations;

	public function __construct(
		AIMS_Square_Sale_Repository $sales,
		AIMS_Sale_Fulfillment_Allocation_Repository $allocations
	) {
		$this->sales       = $sales;
		$this->allocations = $allocations;
	}

	public function normalize_status( string $status ): string {
		return $this->sales->normalize_fulfillment_status( $status );
	}

	public function is_fulfilled_status( string $status ): bool {
		return AIMS_Square_Sale_Repository::STATUS_FULFILLED === $this->normalize_status( $status );
	}

	public function is_needs_shipping_status( string $status ): bool {
		return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING === $this->normalize_status( $status );
	}

	public function is_needs_shipping_info_status( string $status ): bool {
		return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO === $this->normalize_status( $status );
	}

	public function is_backordered_status( string $status ): bool {
		return AIMS_Square_Sale_Repository::STATUS_BACKORDERED === $this->normalize_status( $status );
	}

	public function is_shipped_status( string $status ): bool {
		return AIMS_Square_Sale_Repository::STATUS_SHIPPED === $this->normalize_status( $status );
	}

	public function determine_status(
		array $sale,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): string {
		$workflow_context = $this->build_workflow_context_flags( $sale, $customer, $shipping_address, $context );

		return $this->evaluate_status_from_context( $sale, $workflow_context );
	}

	public function route_sale(
		int $sale_id,
		array $sale,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): array {
		$workflow_context = $this->build_workflow_context( $sale, $customer, $shipping_address, $context );
		$status = $workflow_context['status'];
		$this->sales->update_fulfillment_status( $sale_id, $status );

		return array(
			'sale_id'       => $sale_id,
			'status'        => $status,
			'status_label'  => $workflow_context['status_label'],
			'routing_reason' => $workflow_context['routing_reason'],
		);
	}

	public function process_sale_by_id(
		int $sale_id,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): array {
		$sale = $this->sales->find_by_id( $sale_id );
		if ( empty( $sale ) ) {
			return array(
				'sale_id'           => $sale_id,
				'status'            => AIMS_Square_Sale_Repository::STATUS_PENDING,
				'allocation_id'     => 0,
				'allocation_type'   => AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_EVENT_STOCK,
				'allocation_status' => AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_PENDING,
				'allocation_payload' => array(),
				'error'             => 'sale_not_found',
			);
		}

		return $this->process_sale_workflow( $sale_id, $sale, $customer, $shipping_address, $context );
	}

	public function process_sale_workflow(
		int $sale_id,
		array $sale,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): array {
		$workflow_context = $this->build_workflow_context( $sale, $customer, $shipping_address, $context );
		$status = $workflow_context['status'];
		if ( $sale_id > 0 ) {
			$this->sales->update_fulfillment_status( $sale_id, $status );
		}

		$allocation_payload = $this->build_allocation_payload( $sale, $status, array_merge( $context, $workflow_context ) );
		$allocation_id = $this->allocations->save( $allocation_payload );

		return array(
			'sale_id'            => $sale_id,
			'status'             => $status,
			'status_label'       => $workflow_context['status_label'],
			'routing_reason'     => $workflow_context['routing_reason'],
			'allocation_id'      => $allocation_id,
			'allocation_type'    => $this->derive_allocation_type( $status ),
			'allocation_status'  => $this->derive_allocation_status( $status ),
			'allocation_payload' => $allocation_payload,
			'customer_ready'     => ! empty( $workflow_context['customer_ready'] ),
			'shipping_address_ready' => ! empty( $workflow_context['shipping_address_ready'] ),
		);
	}

	public function create_allocation_for_sale(
		array $sale,
		string $status,
		array $context = array()
	): int {
		$status = $this->normalize_status( $status );

		return $this->allocations->save(
			$this->build_allocation_payload( $sale, $status, $context )
		);
	}

	public function build_allocation_payload(
		array $sale,
		string $status,
		array $context = array()
	): array {
		$status = $this->normalize_status( $status );
		$bucket_context = $this->normalize_bucket_context( $sale, $context );

		return array(
			'square_sale_id'     => (int) ( $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
			'square_order_id'    => sanitize_text_field( $sale['square_order_id'] ?? '' ),
			'product_id'         => (int) ( $sale['woo_product_id'] ?? $sale['product_id'] ?? 0 ),
			'vendor_id'          => (int) ( $sale['vendor_id'] ?? 0 ),
			'event_id'           => (int) ( $sale['event_id'] ?? 0 ),
			'source_bucket_id'   => $bucket_context['id'],
			'source_bucket_code' => $bucket_context['bucket_code'],
			'source_bucket_name' => $bucket_context['bucket_name'],
			'allocation_type'    => $this->derive_allocation_type( $status ),
			'allocation_status'  => $this->derive_allocation_status( $status ),
			'quantity'           => (float) ( $sale['quantity'] ?? 0 ),
			'notes'              => $context['notes'] ?? '',
			'routing_reason'     => ! empty( $context['routing_reason'] ) ? sanitize_text_field( (string) $context['routing_reason'] ) : '',
			'status_label'       => $this->describe_status( $status ),
			'customer_ready'     => ! empty( $context['customer_ready'] ),
			'shipping_address_ready' => ! empty( $context['shipping_address_ready'] ),
			'shipping_marker_present' => ! empty( $context['shipping_marker_present'] ),
		);
	}

	public function describe_status( string $status ): string {
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

	private function is_routed_status( string $status ): bool {
		return in_array(
			$status,
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

	private function derive_allocation_type( string $status ): string {
		if ( $this->is_backordered_status( $status ) ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_WAREHOUSE_BACKORDER;
		}

		if ( $this->is_needs_shipping_status( $status ) || $this->is_needs_shipping_info_status( $status ) ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_WAREHOUSE_PICK;
		}

		return AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_EVENT_STOCK;
	}

	private function derive_allocation_status( string $status ): string {
		if ( $this->is_shipped_status( $status ) ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_SHIPPED;
		}

		if ( $this->is_backordered_status( $status ) ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_BACKORDERED;
		}

		if ( $this->is_needs_shipping_status( $status ) || $this->is_needs_shipping_info_status( $status ) ) {
			return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_PENDING;
		}

		return AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_ALLOCATED;
	}

	private function build_workflow_context(
		array $sale,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): array {
		$flags  = $this->build_workflow_context_flags( $sale, $customer, $shipping_address, $context );
		$status = $this->evaluate_status_from_context( $sale, $flags );

		return array(
			'status'                    => $status,
			'status_label'              => $this->describe_status( $status ),
			'routing_reason'            => $this->build_routing_reason( $status, $flags ),
			'shipping_marker_present'   => $flags['shipping_marker_present'],
			'customer_ready'            => $flags['customer_ready'],
			'shipping_address_ready'    => $flags['shipping_address_ready'],
			'inventory_shortfall'       => $flags['inventory_shortfall'],
			'current_status'            => $flags['current_status'],
			'warehouse_fulfillment_required' => $flags['warehouse_fulfillment_required'],
			'shipped'                   => $flags['shipped'],
		);
	}

	private function build_workflow_context_flags(
		array $sale,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): array {
		return array(
			'current_status'             => $this->normalize_status( (string) ( $sale['fulfillment_status'] ?? AIMS_Square_Sale_Repository::STATUS_PENDING ) ),
			'shipping_marker_present'    => ! empty( $context['shipping_marker_present'] ),
			'customer_ready'             => $this->has_required_customer_data( $customer ),
			'shipping_address_ready'     => $this->has_full_shipping_address( $shipping_address ),
			'inventory_shortfall'        => ! empty( $context['inventory_shortfall'] ),
			'warehouse_fulfillment_required' => ! empty( $context['warehouse_fulfillment_required'] ),
			'shipped'                    => ! empty( $context['shipped'] ),
		);
	}

	private function evaluate_status_from_context( array $sale, array $flags ): string {
		$current_status = ! empty( $flags['current_status'] ) ? (string) $flags['current_status'] : AIMS_Square_Sale_Repository::STATUS_PENDING;

		if ( ! empty( $flags['shipped'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_SHIPPED;
		}

		if ( ! empty( $flags['inventory_shortfall'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_BACKORDERED;
		}

		if ( ! empty( $flags['shipping_marker_present'] ) ) {
			if ( ! empty( $flags['customer_ready'] ) && ! empty( $flags['shipping_address_ready'] ) ) {
				return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING;
			}

			return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO;
		}

		if ( ! empty( $flags['warehouse_fulfillment_required'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_BACKORDERED;
		}

		if ( $this->is_routed_status( $current_status ) ) {
			return $current_status;
		}

		return AIMS_Square_Sale_Repository::STATUS_FULFILLED;
	}

	private function build_routing_reason( string $status, array $flags ): string {
		if ( ! empty( $flags['shipped'] ) ) {
			return 'Marked shipped at intake.';
		}

		if ( ! empty( $flags['inventory_shortfall'] ) ) {
			return 'Inventory shortfall routed to warehouse backorder.';
		}

		if ( ! empty( $flags['warehouse_fulfillment_required'] ) ) {
			return 'Warehouse fulfillment required by workflow context.';
		}

		if ( AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO === $this->normalize_status( $status ) ) {
			$missing = array();
			if ( empty( $flags['customer_ready'] ) ) {
				$missing[] = 'customer contact';
			}
			if ( empty( $flags['shipping_address_ready'] ) ) {
				$missing[] = 'shipping address';
			}

			return 'Missing ' . implode( ' and ', $missing ) . '.';
		}

		if ( AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING === $this->normalize_status( $status ) ) {
			return ! empty( $flags['shipping_marker_present'] ) ? 'Shipping marker present and contact info complete.' : 'Warehouse shipment queued.';
		}

		if ( AIMS_Square_Sale_Repository::STATUS_FULFILLED === $this->normalize_status( $status ) ) {
			return 'Fulfilled on site.';
		}

		return 'Pending routing.';
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

	private function normalize_bucket_context( array $sale, array $context ): array {
		$bucket = array();

		foreach ( array( 'bucket', 'inventory_bucket', 'source_bucket' ) as $key ) {
			if ( ! empty( $context[ $key ] ) ) {
				$bucket = $this->cast_bucket_context( $context[ $key ] );
				break;
			}

			if ( ! empty( $sale[ $key ] ) ) {
				$bucket = $this->cast_bucket_context( $sale[ $key ] );
				break;
			}
		}

		$bucket_id = (int) ( $context['source_bucket_id'] ?? $context['bucket_id'] ?? $sale['source_bucket_id'] ?? $sale['bucket_id'] ?? $bucket['id'] ?? 0 );
		$bucket_code = sanitize_text_field( $context['source_bucket_code'] ?? $context['bucket_code'] ?? $sale['source_bucket_code'] ?? $sale['bucket_code'] ?? $bucket['bucket_code'] ?? '' );
		$bucket_name = sanitize_text_field( $context['source_bucket_name'] ?? $context['bucket_name'] ?? $sale['source_bucket_name'] ?? $sale['bucket_name'] ?? $bucket['bucket_name'] ?? $bucket_code );

		return array(
			'id'          => $bucket_id,
			'bucket_code' => $bucket_code,
			'bucket_name' => $bucket_name,
		);
	}

	private function cast_bucket_context( $bucket ): array {
		if ( is_array( $bucket ) ) {
			return $bucket;
		}

		if ( is_object( $bucket ) ) {
			return get_object_vars( $bucket );
		}

		return array();
	}
}
