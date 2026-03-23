<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Import_Service {
	private $queue;
	private $sales;
	private $customers;
	private $addresses;
	private $fulfillment;
	private $normalization;
	private $intake;
	private $assignment_service;

	public function __construct(
		AIMS_Square_Import_Queue_Repository $queue,
		AIMS_Square_Sale_Repository $sales,
		AIMS_Customer_Repository $customers,
		AIMS_Customer_Address_Repository $addresses,
		AIMS_Fulfillment_Service $fulfillment,
		AIMS_Square_Normalization_Service $normalization = null,
		AIMS_Square_Webhook_Intake_Service $intake = null,
		AIMS_Square_Assignment_Service $assignment_service = null
	) {
		$this->queue              = $queue;
		$this->sales              = $sales;
		$this->customers          = $customers;
		$this->addresses          = $addresses;
		$this->fulfillment        = $fulfillment;
		$this->normalization      = $normalization ? $normalization : new AIMS_Square_Normalization_Service();
		$this->intake             = $intake;
		$this->assignment_service  = $assignment_service;
	}

	public function ingest_order_payload( array $payload ): array {
		$intake_result = $this->intake
			? $this->intake->ingest_order_payload( $payload )
			: array(
				'queue_id'     => $this->queue->save( $this->normalization->normalize_queue_record( $payload ) ),
				'queue_record' => $this->normalization->normalize_queue_record( $payload ),
				'raw_event_id' => 0,
				'raw_event'    => array(),
				'dedupe_key'   => '',
				'created'      => false,
			);

		$analysis = $this->analyze_order_payload( $payload );

		return array(
			'queue_id'     => (int) ( $intake_result['queue_id'] ?? 0 ),
			'queue_record' => $intake_result['queue_record'] ?? array(),
			'raw_event_id' => (int) ( $intake_result['raw_event_id'] ?? 0 ),
			'raw_event'    => $intake_result['raw_event'] ?? array(),
			'dedupe_key'   => (string) ( $intake_result['dedupe_key'] ?? '' ),
			'created'      => ! empty( $intake_result['created'] ),
			'analysis'     => $analysis,
		);
	}

	public function analyze_order_payload( array $payload ): array {
		return $this->normalization->analyze_order_payload( $payload );
	}

	public function detect_canonical_shipping_marker( array $payload ): array {
		return $this->normalization->detect_canonical_shipping_marker( $payload );
	}

	public function validate_customer_address_presence( array $customer_data, array $address_data, array $marker = array() ): array {
		return $this->normalization->validate_customer_address_presence( $customer_data, $address_data, $marker );
	}

	public function persist_queue_to_sales_flow( array $payload, int $queue_id = 0 ): array {
		if ( $queue_id <= 0 ) {
			$intake_result = $this->intake
				? $this->intake->ingest_order_payload( $payload )
				: array( 'queue_id' => $this->queue->save( $this->normalization->normalize_queue_record( $payload ) ) );

			$queue_id = (int) ( $intake_result['queue_id'] ?? 0 );
		}

		$analysis          = $this->analyze_order_payload( $payload );
		$sale_assignment   = $this->resolve_sale_assignment_context( $payload );
		$customer_id       = $this->resolve_customer_id( $analysis['customer_data'] ?? array() );
		$shipping_address_id = $this->resolve_shipping_address_id( $customer_id, $analysis['address_data'] ?? array() );
		$sale_ids          = array();
		$has_errors        = false;
		$order_totals      = $this->normalization->extract_order_totals( $payload, (array) ( $analysis['shipping_marker'] ?? array() ) );
		$default_vendor_id  = (int) ( $payload['vendor_id'] ?? $sale_assignment['vendor_id'] ?? 0 );
		$default_event_id   = (int) ( $payload['event_id'] ?? $sale_assignment['event_id'] ?? 0 );

		if ( empty( $payload['line_items'] ) || ! is_array( $payload['line_items'] ) ) {
			$this->queue->mark_error( $queue_id );

			return array(
				'queue_id' => $queue_id,
				'sale_ids' => $sale_ids,
				'analysis' => $analysis,
			);
		}

		foreach ( $payload['line_items'] as $line_item ) {
			$line_context = array(
				'vendor_id'           => $default_vendor_id,
				'event_id'            => $default_event_id,
				'customer_id'         => $customer_id,
				'shipping_address_id' => $shipping_address_id,
				'billing_address_id'  => 0,
				'woo_order_id'        => (int) ( $payload['woo_order_id'] ?? 0 ),
				'woo_product_id'      => (int) ( $line_item['woo_product_id'] ?? 0 ),
				'square_location_id'  => (string) ( $payload['location_id'] ?? '' ),
			);
			$sale_record = $this->normalization->normalize_sale_record( $payload, $line_item, $analysis, $line_context );
			$sale_id     = $this->sales->save( $sale_record );

			if ( $sale_id <= 0 ) {
				$this->queue->mark_error( $queue_id );
				$has_errors = true;
				continue;
			}

			$sale_ids[] = $sale_id;

			$this->create_fulfillment_allocations_for_sale( $sale_id, $payload, $line_item, $analysis, $line_context );
		}

		if ( $has_errors || empty( $sale_ids ) ) {
			$this->queue->mark_error( $queue_id );
		} else {
			$this->queue->mark_processed( $queue_id, $payload['created_at'] ?? current_time( 'mysql' ) );
		}

		return array(
			'queue_id'          => $queue_id,
			'sale_ids'          => $sale_ids,
			'analysis'          => $analysis,
			'sale_assignment'   => $sale_assignment,
			'order_totals'      => $order_totals,
		);
	}

	public function prepare_fulfillment_allocation_inputs( array $payload, array $marker = array(), array $validation = array(), array $context = array() ): array {
		return $this->normalization->prepare_fulfillment_allocation_inputs( $payload, $marker, $validation, $context );
	}

	public function extract_money_context( array $payload, array $marker ): array {
		return $this->normalization->extract_money_context( $payload, $marker );
	}

	public function extract_order_totals( array $payload, array $marker ): array {
		return $this->normalization->extract_order_totals( $payload, $marker );
	}

	public function extract_line_item_amounts( array $line_item ): array {
		return $this->normalization->extract_line_item_amounts( $line_item );
	}

	public function extract_customer_data( array $payload ): array {
		return $this->normalization->extract_customer_data( $payload );
	}

	public function extract_address_data( array $payload ): array {
		return $this->normalization->extract_address_data( $payload );
	}

	public function extract_tip_amount( array $payload ): float {
		return $this->normalization->extract_tip_amount( $payload );
	}

	public function normalize_money_amount( $value, bool $from_cents = false ): float {
		return $this->normalization->normalize_money_amount( $value, $from_cents );
	}

	public function normalize_quantity( $value ): float {
		return $this->normalization->normalize_quantity( $value );
	}

	private function create_fulfillment_allocations_for_sale(
		int $sale_id,
		array $payload,
		array $line_item,
		array $analysis,
		array $line_context
	): void {
		foreach ( $this->normalization->prepare_fulfillment_allocation_inputs(
			$line_item,
			(array) ( $analysis['shipping_marker'] ?? array() ),
			(array) ( $analysis['validation'] ?? array() ),
			$line_context
		) as $allocation_data ) {
			$allocation_data['square_sale_id']  = $sale_id;
			$allocation_data['square_order_id'] = (string) ( $payload['id'] ?? '' );
			$this->fulfillment->create_allocation( $allocation_data );
		}
	}

	private function resolve_sale_assignment_context( array $payload ): array {
		if ( null === $this->assignment_service ) {
			return array(
				'event_id'  => (int) ( $payload['event_id'] ?? 0 ),
				'vendor_id' => (int) ( $payload['vendor_id'] ?? 0 ),
			);
		}

		$sale = array(
			'square_location_id' => (string) ( $payload['location_id'] ?? '' ),
			'square_team_member_id' => $this->extract_square_team_member_id( $payload ),
			'sold_at'            => (string) ( $payload['created_at'] ?? '' ),
			'id'                 => (int) ( $payload['id'] ?? 0 ),
			'event_id'           => (int) ( $payload['event_id'] ?? 0 ),
			'vendor_id'          => (int) ( $payload['vendor_id'] ?? 0 ),
		);
		$resolved = $this->assignment_service->resolve_sale_assignment( $sale );

		return array(
			'event_id'  => (int) ( $resolved['event_id'] ?? $sale['event_id'] ),
			'vendor_id' => (int) ( $resolved['vendor_id'] ?? $sale['vendor_id'] ),
			'decision'  => $resolved,
		);
	}

	private function resolve_customer_id( array $customer_data ): int {
		$existing = ! empty( $customer_data['square_customer_id'] )
			? $this->customers->find_by_square_customer_id( (string) $customer_data['square_customer_id'] )
			: null;

		if ( ! empty( $existing['id'] ) ) {
			return (int) $existing['id'];
		}

		return $this->customers->save( $customer_data );
	}

	private function resolve_shipping_address_id( int $customer_id, array $address_data ): int {
		if ( $customer_id <= 0 ) {
			return 0;
		}

		$address_data['customer_id'] = $customer_id;

		if ( empty( $address_data['square_address_id'] ) ) {
			return 0;
		}

		$existing = $this->addresses->find_by_square_address_id( (string) $address_data['square_address_id'] );

		if ( ! empty( $existing['id'] ) ) {
			return (int) $existing['id'];
		}

		return $this->addresses->save( $address_data );
	}

	private function extract_square_team_member_id( array $payload ): string {
		$candidates = array(
			$payload['square_team_member_id'] ?? '',
			$payload['team_member_id'] ?? '',
			$payload['employee_id'] ?? '',
			$payload['payment']['team_member_id'] ?? '',
			$payload['payment']['employee_id'] ?? '',
		);

		foreach ( $candidates as $candidate ) {
			$candidate = sanitize_text_field( (string) $candidate );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}
}
