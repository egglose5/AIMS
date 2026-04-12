<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Replay_Service {
	private $raw_events;
	private $normalized_sales;
	private $assignments;
	private $attribution;
	private $normalization;
	private $effects;
	private $exceptions;
	private $fulfillment;
	private $projection;
	private $fulfillment_bridge;

	public function __construct(
		AIMS_Square_Raw_Event_Repository $raw_events = null,
		AIMS_Square_Normalized_Sale_Repository $normalized_sales = null,
		AIMS_Square_Assignment_Service $assignments = null,
		AIMS_Vendor_Sales_Attribution_Service $attribution = null,
		AIMS_Square_Normalization_Service $normalization = null,
		AIMS_Sync_Effect_Repository $effects = null,
		AIMS_Square_Exception_Service $exceptions = null,
		AIMS_Fulfillment_Service $fulfillment = null,
		AIMS_Woo_Order_Projection_Service $projection = null,
		AIMS_Fulfillment_Inventory_Bridge $fulfillment_bridge = null
	) {
		$this->raw_events         = $raw_events;
		$this->normalized_sales   = $normalized_sales;
		$this->assignments        = $assignments;
		$this->attribution        = $attribution;
		$this->normalization      = $normalization ? $normalization : new AIMS_Square_Normalization_Service();
		$this->effects            = $effects;
		$this->exceptions         = $exceptions;
		$this->fulfillment        = $fulfillment;
		$this->projection         = $projection;
		$this->fulfillment_bridge = $fulfillment_bridge;
	}

	public function replay_by_raw_event_id( int $raw_event_id, array $context = array() ): array {
		if ( null === $this->raw_events ) {
			return array(
				'raw_event_id' => $raw_event_id,
				'replayed'     => false,
				'reasons'      => array( 'Raw event repository is not available.' ),
			);
		}

		$raw_event = null;

		foreach ( array( 'find', 'find_by_id', 'get_by_id' ) as $method ) {
			if ( method_exists( $this->raw_events, $method ) ) {
				$raw_event = $this->raw_events->{$method}( $raw_event_id );
				break;
			}
		}

		if ( empty( $raw_event ) ) {
			return array(
				'raw_event_id' => $raw_event_id,
				'replayed'     => false,
				'reasons'      => array( 'Raw event was not found.' ),
			);
		}

		return $this->replay_raw_event( $raw_event, $context );
	}

	public function replay_raw_event( array $raw_event, array $context = array() ): array {
		$raw_event_id = (int) ( $raw_event['id'] ?? 0 );
		$sync_run_id  = (int) ( $context['sync_run_id'] ?? 0 );

		// Dedup guard: return early without writing any new records when this
		// raw event has already been processed for the current sync run.
		if (
			$raw_event_id > 0
			&& $sync_run_id > 0
			&& null !== $this->effects
			&& method_exists( $this->effects, 'has_effect_for_raw_event' )
			&& $this->effects->has_effect_for_raw_event( $sync_run_id, $raw_event_id )
		) {
			return array(
				'raw_event_id'     => $raw_event_id,
				'replayed'         => false,
				'already_replayed' => true,
				'reasons'          => array(
					'Replay dedup: raw event ' . $raw_event_id . ' already processed for sync run ' . $sync_run_id . '.',
				),
			);
		}

		$payload = $this->extract_raw_payload( $raw_event );

		if ( empty( $payload ) ) {
			if ( null !== $this->exceptions ) {
				$this->exceptions->create_exception(
					array( 'normalized_sale_id' => $raw_event_id ),
					'invalid_raw_event',
					'warning',
					'Raw event payload could not be decoded for replay.',
					$context
				);
			}

			return array(
				'raw_event_id' => $raw_event_id,
				'replayed'     => false,
				'reasons'      => array( 'Raw event payload could not be decoded.' ),
			);
		}

		$analysis        = $this->normalization->analyze_order_payload( $payload );
		$normalized_rows = array();
		$attributions    = array();
		$effects         = array();
		$fulfillment     = array();
		$projection      = array();
		$line_items      = (array) ( $payload['line_items'] ?? array() );

		if ( empty( $line_items ) ) {
			if ( null !== $this->exceptions ) {
				$this->exceptions->flag_unmatched_sale( array( 'normalized_sale_id' => $raw_event_id ), $context );
			}
		}

		foreach ( $line_items as $line_item ) {
			$sale_context = array(
				'vendor_id'           => (int) ( $payload['vendor_id'] ?? 0 ),
				'event_id'            => (int) ( $payload['event_id'] ?? 0 ),
				'customer_id'         => (int) ( $context['customer_id'] ?? 0 ),
				'shipping_address_id' => (int) ( $context['shipping_address_id'] ?? 0 ),
				'billing_address_id'  => (int) ( $context['billing_address_id'] ?? 0 ),
				'woo_order_id'        => (int) ( $context['woo_order_id'] ?? 0 ),
				'woo_product_id'      => (int) ( $line_item['woo_product_id'] ?? 0 ),
				'square_location_id'  => (string) ( $payload['location_id'] ?? '' ),
			);
			$sale_record = $this->normalization->normalize_sale_record( $payload, $line_item, $analysis, $sale_context );

			$assignment_decision = array();
			if ( null !== $this->assignments ) {
				$assignment_decision = $this->assignments->resolve_sale_assignment( $sale_record, array() );
			}

			$resolved_assignment = (array) ( $assignment_decision['assignment_window']['selected_assignment'] ?? array() );
			if ( ! empty( $resolved_assignment ) ) {
				$sale_context['vendor_id'] = (int) ( $resolved_assignment['vendor_id'] ?? $sale_context['vendor_id'] ?? 0 );
				$sale_context['event_id']  = (int) ( $resolved_assignment['event_id'] ?? $sale_context['event_id'] ?? 0 );
				$sale_record               = $this->normalization->normalize_sale_record( $payload, $line_item, $analysis, $sale_context );
			}

			if ( null !== $this->normalized_sales && method_exists( $this->normalized_sales, 'save' ) ) {
				$sale_record['normalized_sale_id'] = (int) $this->normalized_sales->save( $sale_record );
			}

			$attribution_record = null !== $this->attribution
				? $this->attribution->attribute_sale( $sale_record, $resolved_assignment, array_merge( $context, array( 'raw_event_id' => $raw_event_id ) ) )
				: array();

			if ( ! empty( $attribution_record ) ) {
				$attributions[] = $attribution_record;
			}

			$fulfillment_records = $this->create_fulfillment_allocations_for_sale( $sale_record, $payload, $line_item, $analysis, $sale_context );
			if ( ! empty( $fulfillment_records ) ) {
				$fulfillment = array_merge( $fulfillment, $fulfillment_records );
			}

			if ( ! empty( $fulfillment_records ) && null !== $this->fulfillment_bridge && method_exists( $this->fulfillment_bridge, 'reserve_for_allocation' ) ) {
				foreach ( $fulfillment_records as $alloc_record ) {
					if ( ! empty( $alloc_record['allocation_id'] ) ) {
						$this->fulfillment_bridge->reserve_for_allocation( (int) $alloc_record['allocation_id'], $alloc_record );
					}
				}
			}

			$projection_context                               = array_merge(
				$context,
				array(
					'raw_event_id'        => $raw_event_id,
					'line_item'           => $line_item,
					'payload'             => $payload,
					'analysis'            => $analysis,
					'resolved_assignment' => $resolved_assignment,
					'customer_data'       => (array) ( $analysis['customer_data'] ?? array() ),
					'address_data'        => (array) ( $analysis['address_data'] ?? array() ),
					'customer_id'         => (int) ( $sale_context['customer_id'] ?? 0 ),
					'shipping_address_id' => (int) ( $sale_context['shipping_address_id'] ?? 0 ),
				)
			);
			$projection_context['additional_projection_charges'] = $this->merge_projection_charges(
				(array) ( $projection_context['additional_projection_charges'] ?? array() ),
				(array) ( $analysis['charge_markers']['projection_charges'] ?? array() )
			);

			if ( ! empty( $analysis['charge_markers']['force_pending_projection'] ) ) {
				$projection_context['projection_mode']               = 'pending';
				$projection_context['allow_unreconciled_projection'] = true;
			}

			$projection_record = $this->project_sale_to_woo( $sale_record, $projection_context );
			if ( ! empty( $projection_record ) ) {
				if ( ! empty( $projection_record['woo_order_id'] ) ) {
					$sale_record['woo_order_id'] = (int) $projection_record['woo_order_id'];

					if ( null !== $this->normalized_sales && method_exists( $this->normalized_sales, 'save' ) && ! empty( $sale_record['normalized_sale_id'] ) ) {
						$this->normalized_sales->save( $sale_record, (int) $sale_record['normalized_sale_id'] );
					}
				}

				$projection[] = $projection_record;
			}

			$normalized_rows[] = $sale_record;

			$effect_record = $this->build_effect_record( $raw_event, $sale_record, $attribution_record, $fulfillment_records, $projection_record, $context );

			if ( null !== $this->effects && method_exists( $this->effects, 'save' ) ) {
				$effect_record['id'] = (int) $this->effects->save( $effect_record );
			}

			$effects[] = $effect_record;
		}

		return array(
			'raw_event_id'    => $raw_event_id,
			'replayed'        => true,
			'analysis'        => $analysis,
			'normalized_rows' => $normalized_rows,
			'attributions'    => $attributions,
			'fulfillment'     => $fulfillment,
			'projection'      => $projection,
			'effects'         => $effects,
		);
	}

	public function replay_normalized_sale( array $normalized_sale, array $context = array() ): array {
		$payload = array(
			'id'           => (string) ( $normalized_sale['square_order_id'] ?? '' ),
			'location_id'  => (string) ( $normalized_sale['square_location_id'] ?? '' ),
			'created_at'   => (string) ( $normalized_sale['sold_at'] ?? '' ),
			'line_items'   => array(
				array(
					'uid'             => (string) ( $normalized_sale['square_line_item_uid'] ?? '' ),
					'woo_product_id'  => (int) ( $normalized_sale['woo_product_id'] ?? 0 ),
					'discount_amount' => (float) ( $normalized_sale['discount_amount'] ?? 0 ),
					'gross_amount'    => (float) ( $normalized_sale['gross_amount'] ?? 0 ),
					'net_amount'      => (float) ( $normalized_sale['net_amount'] ?? 0 ),
					'tax_amount'      => (float) ( $normalized_sale['tax_amount'] ?? 0 ),
					'quantity'        => (float) ( $normalized_sale['quantity'] ?? 0 ),
					'sku'             => (string) ( $normalized_sale['sku'] ?? '' ),
					'total_money'     => array( 'amount' => (float) ( $normalized_sale['net_amount'] ?? 0 ) ),
				),
			),
		);

		return $this->replay_raw_event( array( 'payload' => $payload ) + $normalized_sale, $context );
	}

	private function extract_raw_payload( array $raw_event ): array {
		$payload = $raw_event['payload'] ?? $raw_event['payload_json'] ?? array();

		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $payload ) ? $payload : array();
	}

	private function build_effect_record( array $raw_event, array $sale_record, array $attribution_record, array $fulfillment_records, array $projection_record, array $context ): array {
		return array(
			'sync_run_id'             => (int) ( $context['sync_run_id'] ?? 0 ),
			'sync_action_id'          => (int) ( $context['sync_action_id'] ?? 0 ),
			'effect_type'             => ! empty( $attribution_record ) ? 'replay_attribution' : 'replay_normalization',
			'target_table'            => ! empty( $attribution_record ) ? 'aims_vendor_sales_attribution' : 'aims_square_normalized_sales',
			'target_id'               => (int) ( $attribution_record['attribution_id'] ?? $sale_record['normalized_sale_id'] ?? 0 ),
			'reversal_status'         => 'none',
			'reversed_at'             => null,
			'reversal_sync_action_id' => 0,
			'metadata_json'           => wp_json_encode(
				array(
					'raw_event_id' => (int) ( $raw_event['id'] ?? 0 ),
					'sale_id'      => (int) ( $sale_record['normalized_sale_id'] ?? 0 ),
					'attribution'  => $attribution_record,
					'fulfillment'  => $fulfillment_records,
					'projection'   => empty( $projection_record ) ? array() : array( $projection_record ),
				)
			),
			'created_at'              => current_time( 'mysql' ),
			'updated_at'              => current_time( 'mysql' ),
		);
	}

	private function create_fulfillment_allocations_for_sale(
		array $sale_record,
		array $payload,
		array $line_item,
		array $analysis,
		array $line_context
	): array {
		if ( null === $this->fulfillment ) {
			return array();
		}

		$created = array();

		foreach ( $this->normalization->prepare_fulfillment_allocation_inputs(
			$line_item,
			(array) ( $analysis['shipping_marker'] ?? array() ),
			(array) ( $analysis['validation'] ?? array() ),
			$line_context
		) as $allocation_data ) {
			$allocation_data['square_sale_id']  = (int) ( $sale_record['normalized_sale_id'] ?? 0 );
			$allocation_data['square_order_id'] = (string) ( $payload['id'] ?? $sale_record['square_order_id'] ?? '' );
			$allocation_id                      = (int) $this->fulfillment->create_allocation( $allocation_data );

			$created[] = array(
				'allocation_id'     => $allocation_id,
				'square_sale_id'    => (int) ( $allocation_data['square_sale_id'] ?? 0 ),
				'square_order_id'   => (string) ( $allocation_data['square_order_id'] ?? '' ),
				'product_id'        => (int) ( $allocation_data['product_id'] ?? 0 ),
				'vendor_id'         => (int) ( $allocation_data['vendor_id'] ?? 0 ),
				'event_id'          => (int) ( $allocation_data['event_id'] ?? 0 ),
				'allocation_type'   => (string) ( $allocation_data['allocation_type'] ?? '' ),
				'allocation_status' => (string) ( $allocation_data['allocation_status'] ?? '' ),
				'quantity'          => (float) ( $allocation_data['quantity'] ?? 0 ),
			);
		}

		return $created;
	}

	private function project_sale_to_woo( array $sale_record, array $context = array() ): array {
		if ( null === $this->projection || ! method_exists( $this->projection, 'project_normalized_sale' ) ) {
			return array();
		}

		return (array) $this->projection->project_normalized_sale( $sale_record, $context );
	}

	private function merge_projection_charges( array $base_charges, array $rule_charges ): array {
		$merged = array();

		foreach ( array_merge( $base_charges, $rule_charges ) as $charge ) {
			if ( ! is_array( $charge ) ) {
				continue;
			}

			$amount = round( (float) ( $charge['amount'] ?? 0 ), 2 );
			$label  = sanitize_text_field( (string) ( $charge['label'] ?? '' ) );

			if ( $amount <= 0 || '' === $label ) {
				continue;
			}

			$merged[] = $charge;
		}

		return array_values( $merged );
	}
}
