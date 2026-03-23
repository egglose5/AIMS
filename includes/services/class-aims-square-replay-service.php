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

	public function __construct(
		AIMS_Square_Raw_Event_Repository $raw_events = null,
		AIMS_Square_Normalized_Sale_Repository $normalized_sales = null,
		AIMS_Square_Assignment_Service $assignments = null,
		AIMS_Vendor_Sales_Attribution_Service $attribution = null,
		AIMS_Square_Normalization_Service $normalization = null,
		AIMS_Sync_Effect_Repository $effects = null,
		AIMS_Square_Exception_Service $exceptions = null
	) {
		$this->raw_events      = $raw_events;
		$this->normalized_sales = $normalized_sales;
		$this->assignments     = $assignments;
		$this->attribution     = $attribution;
		$this->normalization   = $normalization ? $normalization : new AIMS_Square_Normalization_Service();
		$this->effects         = $effects;
		$this->exceptions      = $exceptions;
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
		$payload = $this->extract_raw_payload( $raw_event );

		if ( empty( $payload ) ) {
			if ( null !== $this->exceptions ) {
				$this->exceptions->create_exception(
					array( 'normalized_sale_id' => (int) ( $raw_event['id'] ?? 0 ) ),
					'invalid_raw_event',
					'warning',
					'Raw event payload could not be decoded for replay.',
					$context
				);
			}

			return array(
				'raw_event_id' => (int) ( $raw_event['id'] ?? 0 ),
				'replayed'     => false,
				'reasons'      => array( 'Raw event payload could not be decoded.' ),
			);
		}

		$analysis        = $this->normalization->analyze_order_payload( $payload );
		$normalized_rows = array();
		$attributions    = array();
		$effects         = array();
		$line_items      = (array) ( $payload['line_items'] ?? array() );

		if ( empty( $line_items ) ) {
			if ( null !== $this->exceptions ) {
				$this->exceptions->flag_unmatched_sale( array( 'normalized_sale_id' => (int) ( $raw_event['id'] ?? 0 ) ), $context );
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

			if ( null !== $this->normalized_sales && method_exists( $this->normalized_sales, 'save' ) ) {
				$sale_record['normalized_sale_id'] = (int) $this->normalized_sales->save( $sale_record );
			}

			$normalized_rows[] = $sale_record;

			$assignment_decision = array();
			if ( null !== $this->assignments ) {
				$assignment_decision = $this->assignments->resolve_sale_assignment( $sale_record, array() );
			}

			$resolved_assignment = (array) ( $assignment_decision['assignment_window']['selected_assignment'] ?? array() );
			$attribution_record = null !== $this->attribution
				? $this->attribution->attribute_sale( $sale_record, $resolved_assignment, array_merge( $context, array( 'raw_event_id' => (int) ( $raw_event['id'] ?? 0 ) ) ) )
				: array();

			if ( ! empty( $attribution_record ) ) {
				$attributions[] = $attribution_record;
			}

			$effect_record = $this->build_effect_record( $raw_event, $sale_record, $attribution_record, $context );

			if ( null !== $this->effects && method_exists( $this->effects, 'save' ) ) {
				$effect_record['id'] = (int) $this->effects->save( $effect_record );
			}

			$effects[] = $effect_record;
		}

		return array(
			'raw_event_id'    => (int) ( $raw_event['id'] ?? 0 ),
			'replayed'        => true,
			'analysis'        => $analysis,
			'normalized_rows' => $normalized_rows,
			'attributions'    => $attributions,
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
					'uid'              => (string) ( $normalized_sale['square_line_item_uid'] ?? '' ),
					'woo_product_id'   => (int) ( $normalized_sale['woo_product_id'] ?? 0 ),
					'discount_amount'   => (float) ( $normalized_sale['discount_amount'] ?? 0 ),
					'gross_amount'      => (float) ( $normalized_sale['gross_amount'] ?? 0 ),
					'net_amount'        => (float) ( $normalized_sale['net_amount'] ?? 0 ),
					'quantity'          => (float) ( $normalized_sale['quantity'] ?? 0 ),
					'sku'               => (string) ( $normalized_sale['sku'] ?? '' ),
					'total_money'       => array( 'amount' => (float) ( $normalized_sale['net_amount'] ?? 0 ) ),
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

	private function build_effect_record( array $raw_event, array $sale_record, array $attribution_record, array $context ): array {
		return array(
			'sync_run_id'        => (int) ( $context['sync_run_id'] ?? 0 ),
			'sync_action_id'     => (int) ( $context['sync_action_id'] ?? 0 ),
			'effect_type'        => ! empty( $attribution_record ) ? 'replay_attribution' : 'replay_normalization',
			'target_table'       => ! empty( $attribution_record ) ? 'aims_vendor_sales_attribution' : 'aims_square_normalized_sales',
			'target_id'          => (int) ( $attribution_record['attribution_id'] ?? $sale_record['normalized_sale_id'] ?? 0 ),
			'reversal_status'    => 'none',
			'reversed_at'        => null,
			'reversal_sync_action_id' => 0,
			'metadata_json'      => wp_json_encode(
				array(
					'raw_event_id' => (int) ( $raw_event['id'] ?? 0 ),
					'sale_id'      => (int) ( $sale_record['normalized_sale_id'] ?? 0 ),
					'attribution'  => $attribution_record,
				)
			),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		);
	}
}
