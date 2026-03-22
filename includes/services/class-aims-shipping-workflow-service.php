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
		$current_status = $this->normalize_status( (string) ( $sale['fulfillment_status'] ?? AIMS_Square_Sale_Repository::STATUS_PENDING ) );

		if ( ! empty( $context['shipped'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_SHIPPED;
		}

		if ( ! empty( $context['inventory_shortfall'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_BACKORDERED;
		}

		if ( ! empty( $context['shipping_marker_present'] ) ) {
			if ( $this->has_shipping_contact_info( $customer, $shipping_address ) ) {
				return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING;
			}

			return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO;
		}

		if ( ! empty( $context['warehouse_fulfillment_required'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_BACKORDERED;
		}

		if ( $this->is_routed_status( $current_status ) ) {
			return $current_status;
		}

		return AIMS_Square_Sale_Repository::STATUS_FULFILLED;
	}

	public function route_sale(
		int $sale_id,
		array $sale,
		array $customer = array(),
		array $shipping_address = array(),
		array $context = array()
	): array {
		$status = $this->determine_status( $sale, $customer, $shipping_address, $context );
		$this->sales->update_fulfillment_status( $sale_id, $status );

		return array(
			'sale_id' => $sale_id,
			'status'  => $status,
		);
	}

	public function create_allocation_for_sale(
		array $sale,
		string $status,
		array $context = array()
	): int {
		$status = $this->normalize_status( $status );

		$allocation_type = AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_EVENT_STOCK;
		if ( $this->is_backordered_status( $status ) ) {
			$allocation_type = AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_WAREHOUSE_BACKORDER;
		} elseif ( $this->is_needs_shipping_status( $status ) ) {
			$allocation_type = AIMS_Sale_Fulfillment_Allocation_Repository::ALLOCATION_WAREHOUSE_PICK;
		}

		return $this->allocations->save(
			array(
				'square_sale_id'     => (int) ( $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
				'square_order_id'    => sanitize_text_field( $sale['square_order_id'] ?? '' ),
				'product_id'         => (int) ( $sale['woo_product_id'] ?? $sale['product_id'] ?? 0 ),
				'vendor_id'          => (int) ( $sale['vendor_id'] ?? 0 ),
				'event_id'           => (int) ( $sale['event_id'] ?? 0 ),
				'source_bucket_code' => sanitize_text_field( $context['source_bucket_code'] ?? '' ),
				'allocation_type'    => $allocation_type,
				'allocation_status'  => $this->map_allocation_status_for_sale_status( $status ),
				'quantity'           => (float) ( $sale['quantity'] ?? 0 ),
				'notes'              => $context['notes'] ?? '',
			)
		);
	}

	private function has_shipping_contact_info( array $customer, array $shipping_address ): bool {
		$email = trim( (string) ( $customer['email_address'] ?? '' ) );
		$phone = trim( (string) ( $customer['phone_number'] ?? '' ) );
		$name  = trim( (string) ( $customer['first_name'] ?? '' ) . ' ' . (string) ( $customer['last_name'] ?? '' ) );

		$address_line_1 = trim( (string) ( $shipping_address['address_line_1'] ?? '' ) );
		$city           = trim( (string) ( $shipping_address['city'] ?? '' ) );
		$state_region   = trim( (string) ( $shipping_address['state_region'] ?? '' ) );
		$postal_code    = trim( (string) ( $shipping_address['postal_code'] ?? '' ) );

		$has_contact = '' !== $email || '' !== $phone || '' !== $name;
		$has_address = '' !== $address_line_1 && '' !== $city && '' !== $state_region && '' !== $postal_code;

		return $has_contact && $has_address;
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

	private function map_allocation_status_for_sale_status( string $status ): string {
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
}
