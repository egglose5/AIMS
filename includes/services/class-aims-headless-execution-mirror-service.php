<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Headless_Execution_Mirror_Service {
	private $client;

	public function __construct( AIMS_Headless_Api_Client $client = null ) {
		$this->client = $client ?: AIMS_Headless_Api_Client::from_plugin_options();
	}

	public function is_enabled(): bool {
		return is_object( $this->client ) && method_exists( $this->client, 'is_configured' ) && $this->client->is_configured();
	}

	public function mirror_event_execution_movement( array $movement, array $context = array() ): array {
		if ( ! $this->is_enabled() ) {
			return array(
				'attempted' => false,
				'success'   => false,
				'skipped'   => true,
				'reason'    => 'headless_not_configured',
			);
		}

		$sku = $this->resolve_product_sku( (int) ( $movement['product_id'] ?? 0 ) );
		if ( '' === $sku ) {
			return array(
				'attempted' => false,
				'success'   => false,
				'skipped'   => true,
				'reason'    => 'missing_sku',
			);
		}

		$locations = $this->build_location_payload( $movement, $context );
		$quantity  = abs( (float) ( $movement['quantity_delta'] ?? 0 ) );

		if ( $quantity <= 0 ) {
			return array(
				'attempted' => false,
				'success'   => false,
				'skipped'   => true,
				'reason'    => 'invalid_quantity',
			);
		}

		$payload = array(
			'sku'            => $sku,
			'from_location'  => $locations['from_location'],
			'to_location'    => $locations['to_location'],
			'from_endpoint'  => $locations['from_endpoint'],
			'to_endpoint'    => $locations['to_endpoint'],
			'show_id'        => (string) ( $context['show_id'] ?? $context['event_id'] ?? '' ),
			'quantity'       => $quantity,
			'user_id'        => (int) ( $movement['applied_by'] ?? 0 ),
			'movement_type'  => sanitize_key( (string) ( $movement['movement_type'] ?? '' ) ),
			'occurred_at'    => sanitize_text_field( (string) ( $context['occurred_at'] ?? current_time( 'mysql' ) ) ),
			'manifest_uuid'  => sanitize_text_field( (string) ( $context['manifest_uuid'] ?? '' ) ),
		);

		$response = $this->client->post_move( $payload );

		return array(
			'attempted' => true,
			'success'   => ! empty( $response['success'] ),
			'skipped'   => false,
			'reason'    => ! empty( $response['success'] ) ? '' : 'remote_request_failed',
			'payload'   => $payload,
			'response'  => $response,
		);
	}

	private function resolve_product_sku( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( is_object( $product ) && method_exists( $product, 'get_sku' ) ) {
				$sku = sanitize_text_field( (string) $product->get_sku() );
				if ( '' !== $sku ) {
					return $sku;
				}
			}
		}

		if ( function_exists( 'get_post_meta' ) ) {
			return sanitize_text_field( (string) get_post_meta( $product_id, '_sku', true ) );
		}

		return '';
	}

	private function build_location_payload( array $movement, array $context ): array {
		$movement_type       = sanitize_key( (string) ( $movement['movement_type'] ?? '' ) );
		$event_id            = (int) ( $context['event_id'] ?? 0 );
		$bucket              = is_array( $context['bucket'] ?? null ) ? $context['bucket'] : array();
		$bucket_code         = sanitize_text_field( (string) ( $bucket['bucket_code'] ?? '' ) );
		$current_location    = $this->normalize_location_label(
			(string) ( $bucket['current_location_code'] ?? $bucket['current_location_name'] ?? '' ),
			'warehouse'
		);
		$home_location       = $this->normalize_location_label(
			(string) ( $bucket['home_location_code'] ?? $bucket['home_location_name'] ?? '' ),
			$current_location
		);
		$event_location      = $this->normalize_location_label( 'event:' . $event_id, 'event' );
		$bucket_endpoint     = '' !== $bucket_code ? 'bucket:' . $bucket_code : 'bucket';
		$event_endpoint      = $event_id > 0 ? 'event:' . $event_id : 'event';

		if ( 'event_return' === $movement_type || 'vendor_event_return' === sanitize_key( (string) ( $context['reference_type'] ?? '' ) ) ) {
			return array(
				'from_location' => $event_location,
				'to_location'   => $home_location,
				'from_endpoint' => $event_endpoint,
				'to_endpoint'   => $bucket_endpoint,
			);
		}

		return array(
			'from_location' => $current_location,
			'to_location'   => $event_location,
			'from_endpoint' => $bucket_endpoint,
			'to_endpoint'   => $event_endpoint,
		);
	}

	private function normalize_location_label( string $value, string $fallback ): string {
		$value = trim( sanitize_text_field( $value ) );

		return '' !== $value ? $value : $fallback;
	}
}
