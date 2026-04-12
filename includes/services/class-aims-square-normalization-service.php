<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Normalization_Service {
	private const DEFAULT_AIMS_SHIPPING_MARKER_NAME = 'AIMS Ship From Warehouse';
	private $charge_rules;

	public function __construct( AIMS_Square_Order_Charge_Rule_Service $charge_rules = null ) {
		$this->charge_rules = $charge_rules ? $charge_rules : new AIMS_Square_Order_Charge_Rule_Service();
	}

	public function analyze_order_payload( array $payload ): array {
		$marker        = $this->detect_canonical_shipping_marker( $payload );
		$charge_markers = $this->detect_configured_charge_markers( $payload );
		$customer      = $this->extract_customer_data( $payload );
		$address       = $this->extract_address_data( $payload );
		$validation    = $this->validate_customer_address_presence( $customer, $address, $marker );
		$money_context = $this->extract_money_context( $payload, $marker );

		return array(
			'shipping_marker'    => $marker,
			'charge_markers'     => $charge_markers,
			'customer_data'      => $customer,
			'address_data'       => $address,
			'validation'         => $validation,
			'money_context'      => $money_context,
			'fulfillment_inputs' => $this->prepare_fulfillment_allocation_inputs( $payload, $marker, $validation ),
		);
	}

	public function normalize_queue_record( array $payload ): array {
		return array(
			'square_order_id' => (string) ( $payload['id'] ?? '' ),
			'location_id'     => (string) ( $payload['location_id'] ?? '' ),
			'import_status'   => 'pending',
			'payload'         => $payload,
		);
	}

	public function normalize_sale_record(
		array $payload,
		array $line_item,
		array $analysis = array(),
		array $context = array()
	): array {
		$analysis = ! empty( $analysis ) ? $analysis : $this->analyze_order_payload( $payload );
		$order_totals = $this->extract_order_totals( $payload, (array) ( $analysis['shipping_marker'] ?? array() ) );
		$line_amounts = $this->extract_line_item_amounts( $line_item );
		$customer_data = (array) ( $analysis['customer_data'] ?? array() );
		$shipping_marker = (array) ( $analysis['shipping_marker'] ?? array() );
		$assignment_context = wp_parse_args(
			$context,
			array(
				'vendor_id'           => (int) ( $payload['vendor_id'] ?? 0 ),
				'event_id'            => (int) ( $payload['event_id'] ?? 0 ),
				'woo_order_id'        => (int) ( $payload['woo_order_id'] ?? 0 ),
				'woo_product_id'      => (int) ( $line_item['woo_product_id'] ?? 0 ),
				'customer_id'         => (int) ( $context['customer_id'] ?? 0 ),
				'shipping_address_id' => (int) ( $context['shipping_address_id'] ?? 0 ),
				'billing_address_id'  => (int) ( $context['billing_address_id'] ?? 0 ),
				'square_location_id'  => (string) ( $payload['location_id'] ?? '' ),
				'charge_markers'      => (array) ( $analysis['charge_markers'] ?? array() ),
			)
		);

		return array(
			'square_order_id'      => (string) ( $payload['id'] ?? '' ),
			'square_line_item_uid' => (string) ( $line_item['uid'] ?? $line_item['id'] ?? wp_generate_uuid4() ),
			'square_location_id'   => (string) ( $assignment_context['square_location_id'] ?? '' ),
			'square_customer_id'   => (string) ( $customer_data['square_customer_id'] ?? '' ),
			'customer_id'          => (int) ( $assignment_context['customer_id'] ?? 0 ),
			'shipping_address_id'  => (int) ( $assignment_context['shipping_address_id'] ?? 0 ),
			'billing_address_id'   => (int) ( $assignment_context['billing_address_id'] ?? 0 ),
			'vendor_id'            => (int) ( $assignment_context['vendor_id'] ?? 0 ),
			'event_id'             => (int) ( $assignment_context['event_id'] ?? 0 ),
			'woo_order_id'         => (int) ( $assignment_context['woo_order_id'] ?? 0 ),
			'woo_product_id'       => (int) ( $assignment_context['woo_product_id'] ?? 0 ),
			'sku'                  => (string) ( $line_item['sku'] ?? '' ),
			'source'               => 'square',
			'delivery_method'      => ! empty( $shipping_marker['has_aims_shipping_marker'] ) ? 'ship' : 'pickup',
			'shipping_amount'      => $this->normalize_money_amount( $order_totals['shipping_amount'] ?? 0 ),
			'discount_amount'      => $this->normalize_money_amount( $line_amounts['discount_amount'] ?? 0 ),
			'discount_label'       => (string) ( $line_item['discount_name'] ?? $line_item['discount_label'] ?? $order_totals['discount_label'] ?? '' ),
			'tip_amount'           => $this->normalize_money_amount( $order_totals['tip_amount'] ?? 0 ),
			'tax_amount'           => $this->normalize_money_amount( $line_amounts['tax_amount'] ?? 0 ),
			'fulfillment_status'   => $this->determine_fulfillment_status( $analysis ),
			'quantity'             => $this->normalize_quantity( $line_amounts['quantity'] ?? 0 ),
			'gross_amount'         => $this->normalize_money_amount( $line_amounts['gross_amount'] ?? 0 ),
			'net_amount'           => $this->normalize_money_amount( $line_amounts['net_amount'] ?? 0 ),
			'amount_paid'          => $this->normalize_money_amount( $line_amounts['net_amount'] ?? 0 ),
			'payload'              => $this->build_operational_sale_payload( $payload, $line_item, $assignment_context, $line_amounts, $shipping_marker ),
			'sold_at'              => $payload['created_at'] ?? null,
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

	public function detect_configured_charge_markers( array $payload ): array {
		if ( ! is_object( $this->charge_rules ) || ! method_exists( $this->charge_rules, 'match_payload_charges' ) ) {
			return array(
				'matched_rules'      => array(),
				'matched_charge_ids' => array(),
				'flags'              => array(),
				'projection_charges' => array(),
			);
		}

		return (array) $this->charge_rules->match_payload_charges( $payload );
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

	public function prepare_fulfillment_allocation_inputs( array $payload, array $marker = array(), array $validation = array(), array $context = array() ): array {
		$quantity          = $this->normalize_quantity( $payload['quantity'] ?? 1 );
		$requires_shipping = ! empty( $marker['has_aims_shipping_marker'] );
		$event_id          = (int) ( $context['event_id'] ?? $payload['event_id'] ?? 0 );
		$vendor_id         = (int) ( $context['vendor_id'] ?? $payload['vendor_id'] ?? 0 );

		if ( ! empty( $validation['needs_shipping_info'] ) ) {
			return array();
		}

		// Event stock is an operational effect that should only exist once the sale has been resolved to an event.
		if ( ! $requires_shipping && $event_id <= 0 ) {
			return array();
		}

		return array(
			array(
				'product_id'         => (int) ( $payload['woo_product_id'] ?? 0 ),
				'vendor_id'          => $vendor_id,
				'event_id'           => $event_id,
				'source_bucket_code' => sanitize_text_field( (string) ( $context['source_bucket_code'] ?? '' ) ),
				'allocation_type'    => $requires_shipping ? 'warehouse_backorder' : 'event_stock',
				'allocation_status'  => $requires_shipping ? 'pending' : 'allocated',
				'quantity'           => $quantity,
				'notes'              => $requires_shipping
					? 'Created from canonical AIMS shipping marker; physical bucket resolution deferred to warehouse workflows.'
					: 'Created from event-linked sale; physical bucket resolution deferred to event inventory workflows.',
			),
		);
	}

	public function extract_money_context( array $payload, array $marker ): array {
		$order_total = $this->extract_order_totals( $payload, $marker );

		return array(
			'gross_amount'    => $order_total['gross_amount'],
			'net_amount'      => $order_total['net_amount'],
			'discount_amount' => $order_total['discount_amount'],
			'shipping_amount' => $order_total['shipping_amount'],
			'tip_amount'      => $this->extract_tip_amount( $payload ),
		);
	}

	public function extract_order_totals( array $payload, array $marker ): array {
		$totals = $this->extract_money_fields(
			$payload,
			array(
				'gross_amount',
				'net_amount',
				'discount_amount',
				'shipping_amount',
			)
		);

		$totals['shipping_amount'] = ! empty( $marker['has_aims_shipping_marker'] )
			? $this->normalize_money_amount( $marker['amount'] ?? 0 )
			: $this->normalize_money_amount( $totals['shipping_amount'] );

		return array(
			'gross_amount'    => $totals['gross_amount'],
			'net_amount'      => $totals['net_amount'],
			'discount_amount' => $totals['discount_amount'],
			'shipping_amount' => $totals['shipping_amount'],
			'discount_label'  => (string) ( $payload['discount_label'] ?? '' ),
		);
	}

	public function extract_line_item_amounts( array $line_item ): array {
		$totals = $this->extract_money_fields(
			$line_item,
			array(
				'gross_amount',
				'net_amount',
				'tax_amount',
				'discount_amount',
				'quantity',
			)
		);

		if ( 0.0 === $totals['gross_amount'] && isset( $line_item['total_money']['amount'] ) ) {
			$totals['gross_amount'] = $this->normalize_money_amount( $line_item['total_money']['amount'] );
		}

		if ( 0.0 === $totals['net_amount'] && isset( $line_item['total_money']['amount'] ) ) {
			$totals['net_amount'] = $this->normalize_money_amount( $line_item['total_money']['amount'] );
		}

		if ( 0.0 === $totals['discount_amount'] && ! empty( $line_item['applied_discounts'] ) && is_array( $line_item['applied_discounts'] ) ) {
			foreach ( $line_item['applied_discounts'] as $discount ) {
				$totals['discount_amount'] += $this->normalize_money_amount( $discount['applied_money']['amount'] ?? 0, true );
			}
		}

		if ( 0.0 === $totals['tax_amount'] && ! empty( $line_item['applied_taxes'] ) && is_array( $line_item['applied_taxes'] ) ) {
			foreach ( $line_item['applied_taxes'] as $tax ) {
				$totals['tax_amount'] += $this->normalize_money_amount( $tax['applied_money']['amount'] ?? 0, true );
			}
		}

		return $totals;
	}

	public function extract_tip_amount( array $payload ): float {
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

	public function extract_customer_data( array $payload ): array {
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

	public function extract_address_data( array $payload ): array {
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

	public function normalize_money_amount( $value, bool $from_cents = false ): float {
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

	public function normalize_quantity( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		return round( (float) $value, 4 );
	}

	private function build_operational_sale_payload( array $payload, array $line_item, array $context, array $line_amounts, array $shipping_marker ): array {
		$charge_markers      = (array) ( $context['charge_markers'] ?? array() );
		$matched_charge_codes = array();

		if ( ! empty( $charge_markers['matched_rules'] ) && is_array( $charge_markers['matched_rules'] ) ) {
			foreach ( $charge_markers['matched_rules'] as $matched_rule ) {
				$code = sanitize_key( (string) ( $matched_rule['code'] ?? '' ) );
				if ( '' !== $code ) {
					$matched_charge_codes[] = $code;
				}
			}
		}

		return array(
			'square_order_id'      => (string) ( $payload['id'] ?? '' ),
			'square_line_item_uid' => (string) ( $line_item['uid'] ?? $line_item['id'] ?? '' ),
			'square_location_id'   => (string) ( $context['square_location_id'] ?? $payload['location_id'] ?? '' ),
			'event_id'             => (int) ( $context['event_id'] ?? 0 ),
			'vendor_id'            => (int) ( $context['vendor_id'] ?? 0 ),
			'woo_product_id'       => (int) ( $context['woo_product_id'] ?? 0 ),
			'sku'                  => (string) ( $line_item['sku'] ?? '' ),
			'quantity'             => $this->normalize_quantity( $line_amounts['quantity'] ?? 0 ),
			'amount_paid'          => $this->normalize_money_amount( $line_amounts['net_amount'] ?? 0 ),
			'gross_amount'         => $this->normalize_money_amount( $line_amounts['gross_amount'] ?? 0 ),
			'discount_amount'      => $this->normalize_money_amount( $line_amounts['discount_amount'] ?? 0 ),
			'tax_amount'           => $this->normalize_money_amount( $line_amounts['tax_amount'] ?? 0 ),
			'delivery_method'      => ! empty( $shipping_marker['has_aims_shipping_marker'] ) ? 'ship' : 'pickup',
			'charge_flags'         => (array) ( $charge_markers['flags'] ?? array() ),
			'matched_charge_codes' => array_values( array_unique( $matched_charge_codes ) ),
		);
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

	private function determine_fulfillment_status( array $analysis ): string {
		if ( ! empty( $analysis['charge_markers']['force_unfulfilled'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_PENDING;
		}

		if ( ! empty( $analysis['validation']['needs_shipping_info'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO;
		}

		if ( ! empty( $analysis['shipping_marker']['has_aims_shipping_marker'] ) ) {
			return AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING;
		}

		return AIMS_Square_Sale_Repository::STATUS_FULFILLED;
	}

	private function get_aims_shipping_marker_name(): string {
		$aims_shipping_marker_name = get_option( 'aims_shipping_marker_name', self::DEFAULT_AIMS_SHIPPING_MARKER_NAME );

		$aims_shipping_marker_name = sanitize_text_field( (string) $aims_shipping_marker_name );

		return '' !== $aims_shipping_marker_name ? $aims_shipping_marker_name : self::DEFAULT_AIMS_SHIPPING_MARKER_NAME;
	}
}
