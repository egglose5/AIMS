<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Import_Service {
	private const DEFAULT_AIMS_SHIPPING_MARKER_NAME = 'AIMS Ship From Warehouse';

	private $queue;
	private $sales;
	private $customers;
	private $addresses;
	private $fulfillment;

	public function __construct(
		AIMS_Square_Import_Queue_Repository $queue,
		AIMS_Square_Sale_Repository $sales,
		AIMS_Customer_Repository $customers,
		AIMS_Customer_Address_Repository $addresses,
		AIMS_Fulfillment_Service $fulfillment
	) {
		$this->queue       = $queue;
		$this->sales       = $sales;
		$this->customers   = $customers;
		$this->addresses   = $addresses;
		$this->fulfillment = $fulfillment;
	}

	public function ingest_order_payload( array $payload ): array {
		$queue_record = $this->normalize_queue_record( $payload );
		$queue_id     = $this->queue->save( $queue_record );
		$analysis     = $this->analyze_order_payload( $payload );

		return array(
			'queue_id' => $queue_id,
			'analysis' => $analysis,
		);
	}

	public function analyze_order_payload( array $payload ): array {
		$marker         = $this->detect_canonical_shipping_marker( $payload );
		$customer_data  = $this->extract_customer_data( $payload );
		$address_data   = $this->extract_address_data( $payload );
		$validation     = $this->validate_customer_address_presence( $customer_data, $address_data, $marker );
		$money_context   = $this->extract_money_context( $payload, $marker );

		return array(
			'shipping_marker'   => $marker,
			'customer_data'     => $customer_data,
			'address_data'      => $address_data,
			'validation'        => $validation,
			'money_context'     => $money_context,
			'fulfillment_inputs' => $this->prepare_fulfillment_allocation_inputs( $payload, $marker, $validation ),
		);
	}

	public function detect_canonical_shipping_marker( array $payload ): array {
		$charges = array();

		if ( ! empty( $payload['service_charges'] ) && is_array( $payload['service_charges'] ) ) {
			$charges = $payload['service_charges'];
		}

		$aims_shipping_marker_name = $this->get_aims_shipping_marker_name();

		foreach ( $charges as $charge ) {
			$name = sanitize_text_field( $charge['name'] ?? '' );
			if ( $name === $aims_shipping_marker_name ) {
				return array(
					'has_aims_shipping_marker' => true,
					'aims_shipping_marker_name' => $aims_shipping_marker_name,
					'service_charge_id'        => sanitize_text_field( $charge['uid'] ?? $charge['id'] ?? '' ),
					'amount'                   => number_format( (float) ( $charge['amount_money']['amount'] ?? 0 ) / 100, 2, '.', '' ),
					'currency'                 => sanitize_text_field( $charge['amount_money']['currency'] ?? '' ),
					'label'                    => $name,
					'raw_charge'               => $charge,
				);
			}
		}

		return array(
			'has_aims_shipping_marker' => false,
			'aims_shipping_marker_name' => $aims_shipping_marker_name,
			'service_charge_id'        => '',
			'amount'                   => '0.00',
			'currency'                 => '',
			'label'                    => $aims_shipping_marker_name,
			'raw_charge'               => null,
		);
	}

	public function validate_customer_address_presence( array $customer_data, array $address_data, array $marker = array() ): array {
		$has_customer = ! empty( $customer_data['square_customer_id'] ) || ! empty( $customer_data['email_address'] );
		$has_address  = ! empty( $address_data['address_line_1'] ) && ! empty( $address_data['postal_code'] );

		return array(
			'valid'               => $has_customer && ( ! empty( $marker['has_aims_shipping_marker'] ) ? $has_address : true ),
			'has_customer'        => $has_customer,
			'has_address'         => $has_address,
			'requires_shipping'   => ! empty( $marker['has_aims_shipping_marker'] ),
			'needs_shipping_info' => ! empty( $marker['has_aims_shipping_marker'] ) && ! $has_address,
		);
	}

	public function persist_queue_to_sales_flow( array $payload ): array {
		$analysis  = $this->analyze_order_payload( $payload );
		$queue_id  = $this->queue->save( $this->normalize_queue_record( $payload ) );
		$sale_ids  = array();
		$has_errors = false;

		if ( empty( $payload['line_items'] ) || ! is_array( $payload['line_items'] ) ) {
			$this->queue->mark_error( $queue_id );

			return array(
				'queue_id' => $queue_id,
				'sale_ids' => $sale_ids,
				'analysis' => $analysis,
			);
		}

		$customer_id = $this->resolve_customer_id( $analysis['customer_data'] );
		$shipping_address_id = $this->resolve_shipping_address_id( $customer_id, $analysis['address_data'] );
		$vendor_id = (int) ( $payload['vendor_id'] ?? 0 );
		$event_id  = (int) ( $payload['event_id'] ?? 0 );
		$order_totals = $this->extract_order_totals( $payload, $analysis['shipping_marker'] );

		foreach ( $payload['line_items'] as $line_item ) {
			$line_amounts = $this->extract_line_item_amounts( $line_item );
			$sale_id = $this->sales->save(
				array(
					'square_order_id'      => (string) ( $payload['id'] ?? '' ),
					'square_line_item_uid' => (string) ( $line_item['uid'] ?? $line_item['id'] ?? wp_generate_uuid4() ),
					'square_location_id'   => (string) ( $payload['location_id'] ?? '' ),
					'square_customer_id'   => (string) ( $analysis['customer_data']['square_customer_id'] ?? '' ),
					'customer_id'          => $customer_id,
					'shipping_address_id'  => $shipping_address_id,
					'billing_address_id'   => 0,
					'vendor_id'            => $vendor_id,
					'event_id'             => $event_id,
					'woo_order_id'         => 0,
					'woo_product_id'       => (int) ( $line_item['woo_product_id'] ?? 0 ),
					'sku'                  => (string) ( $line_item['sku'] ?? '' ),
					'source'               => 'square',
					'delivery_method'      => ! empty( $analysis['shipping_marker']['has_aims_shipping_marker'] ) ? 'ship' : 'pickup',
					'shipping_amount'      => $this->normalize_money_amount( $order_totals['shipping_amount'] ),
					'discount_amount'      => $this->normalize_money_amount( $line_amounts['discount_amount'] ),
					'discount_label'       => (string) ( $line_item['discount_name'] ?? $line_item['discount_label'] ?? $order_totals['discount_label'] ?? '' ),
					'tip_amount'           => $this->extract_tip_amount( $payload ),
					'fulfillment_status'   => $this->determine_fulfillment_status( $analysis ),
					'quantity'             => $this->normalize_quantity( $line_amounts['quantity'] ),
					'gross_amount'         => $this->normalize_money_amount( $line_amounts['gross_amount'] ),
					'net_amount'           => $this->normalize_money_amount( $line_amounts['net_amount'] ),
					'payload'              => $line_item,
					'sold_at'              => $payload['created_at'] ?? null,
				)
			);

			if ( $sale_id <= 0 ) {
				$this->queue->mark_error( $queue_id );
				$has_errors = true;
				continue;
			}

			$sale_ids[] = $sale_id;

			foreach ( $this->prepare_fulfillment_allocation_inputs( $line_item, $analysis['shipping_marker'], $analysis['validation'] ) as $allocation_data ) {
				$allocation_data['square_sale_id'] = $sale_id;
				$allocation_data['square_order_id'] = (string) ( $payload['id'] ?? '' );
				$this->fulfillment->create_allocation( $allocation_data );
			}
		}

		if ( $has_errors || empty( $sale_ids ) ) {
			$this->queue->mark_error( $queue_id );
		} else {
			$this->queue->mark_processed( $queue_id, $payload['created_at'] ?? current_time( 'mysql' ) );
		}

		return array(
			'queue_id' => $queue_id,
			'sale_ids' => $sale_ids,
			'analysis' => $analysis,
		);
	}

	public function prepare_fulfillment_allocation_inputs( array $payload, array $marker = array(), array $validation = array() ): array {
		$quantity = $this->normalize_quantity( $payload['quantity'] ?? 1 );
		$source_bucket = ! empty( $marker['has_aims_shipping_marker'] ) ? 'warehouse' : 'event';

		if ( ! empty( $validation['needs_shipping_info'] ) ) {
			return array();
		}

		return array(
			array(
				'product_id'         => (int) ( $payload['woo_product_id'] ?? 0 ),
				'vendor_id'          => (int) ( $payload['vendor_id'] ?? 0 ),
				'event_id'           => (int) ( $payload['event_id'] ?? 0 ),
				'source_bucket_code' => $source_bucket,
				'allocation_type'    => ! empty( $marker['has_aims_shipping_marker'] ) ? 'warehouse_backorder' : 'event_stock',
				'allocation_status'  => ! empty( $marker['has_aims_shipping_marker'] ) ? 'pending' : 'allocated',
				'quantity'           => $quantity,
				'notes'              => ! empty( $marker['has_aims_shipping_marker'] ) ? 'Created from canonical AIMS shipping marker.' : 'Created from event-stock sale.',
			),
		);
	}

	private function get_aims_shipping_marker_name(): string {
		$aims_shipping_marker_name = get_option( 'aims_shipping_marker_name', self::DEFAULT_AIMS_SHIPPING_MARKER_NAME );

		$aims_shipping_marker_name = sanitize_text_field( (string) $aims_shipping_marker_name );

		return '' !== $aims_shipping_marker_name ? $aims_shipping_marker_name : self::DEFAULT_AIMS_SHIPPING_MARKER_NAME;
	}

	private function extract_money_context( array $payload, array $marker ): array {
		$order_total = $this->extract_order_totals( $payload, $marker );

		return array(
			'gross_amount'    => $order_total['gross_amount'],
			'net_amount'      => $order_total['net_amount'],
			'discount_amount'  => $order_total['discount_amount'],
			'shipping_amount'  => $order_total['shipping_amount'],
		);
	}

	private function extract_order_totals( array $payload, array $marker ): array {
		$totals = $this->extract_money_fields( $payload, array(
			'gross_amount',
			'net_amount',
			'discount_amount',
			'shipping_amount',
		) );

		$totals['shipping_amount'] = ! empty( $marker['has_aims_shipping_marker'] )
			? $this->normalize_money_amount( $marker['amount'] ?? 0 )
			: $this->normalize_money_amount( $totals['shipping_amount'] );

		return array(
			'gross_amount'   => $totals['gross_amount'],
			'net_amount'     => $totals['net_amount'],
			'discount_amount'=> $totals['discount_amount'],
			'shipping_amount'=> $totals['shipping_amount'],
			'discount_label' => (string) ( $payload['discount_label'] ?? '' ),
		);
	}

	private function extract_line_item_amounts( array $line_item ): array {
		$totals = $this->extract_money_fields( $line_item, array(
			'gross_amount',
			'net_amount',
			'discount_amount',
			'quantity',
		) );

		if ( 0.0 === $totals['gross_amount'] && isset( $line_item['total_money']['amount'] ) ) {
			$totals['gross_amount'] = $this->normalize_money_amount( $line_item['total_money']['amount'] );
		}

		if ( 0.0 === $totals['net_amount'] && isset( $line_item['total_money']['amount'] ) ) {
			$totals['net_amount'] = $this->normalize_money_amount( $line_item['total_money']['amount'] );
		}

		if ( 0.0 === $totals['discount_amount'] && ! empty( $line_item['applied_discounts'] ) && is_array( $line_item['applied_discounts'] ) ) {
			foreach ( $line_item['applied_discounts'] as $discount ) {
				$totals['discount_amount'] += $this->normalize_money_amount( $discount['applied_money']['amount'] ?? 0 );
			}
		}

		return $totals;
	}

	private function extract_money_fields( array $source, array $fields ): array {
		$values = array();

		foreach ( $fields as $field ) {
			$values[ $field ] = $this->read_normalized_money_field( $source, $field );
		}

		return $values;
	}

	private function read_normalized_money_field( array $source, string $field ): float {
		$candidates = array(
			array( 'value' => $source[ $field ] ?? null, 'from_cents' => false ),
			array( 'value' => $source[ $field . '_money' ] ?? null, 'from_cents' => true ),
			array( 'value' => $source[ $field . '_amount' ] ?? null, 'from_cents' => false ),
		);

		foreach ( $candidates as $candidate ) {
			$value = $candidate['value'];
			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				if ( isset( $value['amount'] ) ) {
					return $this->normalize_money_amount( $value['amount'], (bool) $candidate['from_cents'] );
				}

				if ( isset( $value['amount_money']['amount'] ) ) {
					return $this->normalize_money_amount( $value['amount_money']['amount'], true );
				}

				continue;
			}

			return $this->normalize_money_amount( $value, (bool) $candidate['from_cents'] );
		}

		return 0.0;
	}

	private function normalize_money_amount( $value, bool $from_cents = false ): float {
		if ( is_array( $value ) && isset( $value['amount'] ) ) {
			$value = $value['amount'];
		}

		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		$value = (float) $value;

		if ( $from_cents ) {
			$value = $value / 100;
		}

		return round( $value, 2 );
	}

	private function normalize_quantity( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		return round( (float) $value, 4 );
	}

	private function determine_fulfillment_status( array $analysis ): string {
		if ( ! empty( $analysis['validation']['needs_shipping_info'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO;
		}

		if ( ! empty( $analysis['shipping_marker']['has_aims_shipping_marker'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING;
		}

		return AIMS_Square_Sale_Repository::STATUS_FULFILLED;
	}

	private function normalize_queue_record( array $payload ): array {
		return array(
			'square_order_id' => (string) ( $payload['id'] ?? '' ),
			'location_id'     => (string) ( $payload['location_id'] ?? '' ),
			'import_status'   => 'pending',
			'payload'         => $payload,
		);
	}

	private function extract_customer_data( array $payload ): array {
		$customer = array();

		if ( ! empty( $payload['customer'] ) && is_array( $payload['customer'] ) ) {
			$customer = $payload['customer'];
		}

		return array(
			'square_customer_id' => (string) ( $customer['id'] ?? '' ),
			'first_name'         => (string) ( $customer['given_name'] ?? '' ),
			'last_name'          => (string) ( $customer['family_name'] ?? '' ),
			'company_name'       => (string) ( $customer['company_name'] ?? '' ),
			'email_address'      => (string) ( $customer['email_address'] ?? '' ),
			'phone_number'       => (string) ( $customer['phone_number'] ?? '' ),
			'notes'              => '',
		);
	}

	private function extract_address_data( array $payload ): array {
		$address = array();

		if ( ! empty( $payload['shipping_address'] ) && is_array( $payload['shipping_address'] ) ) {
			$address = $payload['shipping_address'];
		}

		return array(
			'square_address_id' => (string) ( $address['id'] ?? '' ),
			'address_type'      => 'shipping',
			'is_primary'        => 1,
			'address_line_1'    => (string) ( $address['address_line_1'] ?? '' ),
			'address_line_2'    => (string) ( $address['address_line_2'] ?? '' ),
			'city'              => (string) ( $address['locality'] ?? '' ),
			'state_region'      => (string) ( $address['administrative_district_level_1'] ?? '' ),
			'postal_code'       => (string) ( $address['postal_code'] ?? '' ),
			'country_code'      => (string) ( $address['country'] ?? 'US' ),
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

	private function extract_tip_amount( array $payload ): float {
		if ( ! empty( $payload['total_tip_money']['amount'] ) ) {
			return $this->normalize_money_amount( $payload['total_tip_money']['amount'] );
		}

		if ( ! empty( $payload['tip_money']['amount'] ) ) {
			return $this->normalize_money_amount( $payload['tip_money']['amount'] );
		}

		if ( ! empty( $payload['payment']['tip_money']['amount'] ) ) {
			return $this->normalize_money_amount( $payload['payment']['tip_money']['amount'] );
		}

		return 0.0;
	}
}
