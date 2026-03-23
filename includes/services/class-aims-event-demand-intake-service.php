<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Demand_Intake_Service {
	private $events;
	private $requests;
	private $request_items;

	public function __construct( $events, $requests = null, $request_items = null ) {
		$this->events        = $events;
		$this->requests      = $requests;
		$this->request_items = $request_items;
	}

	public function intake_request( array $data ) {
		$record = $this->normalize_request_record( $data );
		$valid  = $this->validate_request_record( $record );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$record['request_status']     = 'auto_accepted';
		$record['item_status']        = 'planning_signal';
		$record['intake_status']      = 'auto_accepted';
		$record['demand_signal_type'] = 'planning_only';
		$record['requested_at']       = $record['requested_at'] ?: current_time( 'mysql' );
		$record['approved_at']        = $record['approved_at'] ?: $record['requested_at'];

		$persisted = $this->persist_request_record( $record );

		return array(
			'request_id' => (int) ( $persisted['request_id'] ?? 0 ),
			'item_id'    => (int) ( $persisted['item_id'] ?? 0 ),
			'persisted'  => ! empty( $persisted['persisted'] ),
			'record'     => $record,
		);
	}

	public function validate_request_record( array $record ) {
		$user = $this->validate_authenticated_user( (int) $record['wp_user_id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$event = $this->validate_event( (int) $record['event_id'] );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		if ( (int) $record['woo_product_id'] <= 0 ) {
			return new WP_Error( 'aims_missing_woo_product_id', 'Event demand intake requires woo_product_id.' );
		}

		if ( '' === $record['product_sku'] ) {
			return new WP_Error( 'aims_missing_product_sku', 'Event demand intake requires product_sku.' );
		}

		$product = $this->validate_product_match( (int) $record['woo_product_id'], $record['product_sku'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		if ( $record['quantity_requested'] <= 0 ) {
			return new WP_Error( 'aims_invalid_demand_quantity', 'Event demand intake requires a positive quantity_requested.' );
		}

		return true;
	}

	public function normalize_request_record( array $data ): array {
		$quantity_requested = (float) ( $data['quantity_requested'] ?? $data['quantity'] ?? 0 );
		$user_context       = $this->resolve_authenticated_user_context( $data );

		return array(
			'event_id'             => (int) ( $data['event_id'] ?? 0 ),
			'vendor_id'            => (int) ( $data['vendor_id'] ?? 0 ),
			'customer_id'          => (int) ( $data['customer_id'] ?? 0 ),
			'wp_user_id'           => (int) ( $user_context['wp_user_id'] ?? 0 ),
			'customer_name'        => sanitize_text_field( $data['customer_name'] ?? $user_context['customer_name'] ?? '' ),
			'customer_email'       => sanitize_email( $data['customer_email'] ?? $user_context['customer_email'] ?? '' ),
			'customer_phone'       => sanitize_text_field( $data['customer_phone'] ?? $user_context['customer_phone'] ?? '' ),
			'woo_product_id'       => (int) ( $data['woo_product_id'] ?? 0 ),
			'product_sku'          => $this->normalize_sku( $data['product_sku'] ?? '' ),
			'product_name'         => sanitize_text_field( $data['product_name'] ?? '' ),
			'quantity_requested'   => $quantity_requested,
			'quantity'             => $quantity_requested,
			'request_notes'        => isset( $data['request_notes'] ) ? wp_kses_post( $data['request_notes'] ) : '',
			'notes'                => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : ( isset( $data['request_notes'] ) ? wp_kses_post( $data['request_notes'] ) : '' ),
			'request_source'       => sanitize_key( $data['request_source'] ?? $data['source'] ?? 'public_event_demand_form' ),
			'request_status'       => sanitize_key( $data['request_status'] ?? 'auto_accepted' ),
			'item_status'          => sanitize_key( $data['item_status'] ?? 'planning_signal' ),
			'intake_status'        => sanitize_key( $data['intake_status'] ?? 'auto_accepted' ),
			'demand_signal_type'   => sanitize_key( $data['demand_signal_type'] ?? 'planning_only' ),
			'payment_status'       => sanitize_key( $data['payment_status'] ?? 'not_applicable' ),
			'reservation_status'   => sanitize_key( $data['reservation_status'] ?? 'not_reserved' ),
			'square_order_creation'=> sanitize_key( $data['square_order_creation'] ?? 'disabled' ),
			'requested_at'         => sanitize_text_field( $data['requested_at'] ?? $data['submitted_at'] ?? '' ),
			'approved_at'          => sanitize_text_field( $data['approved_at'] ?? '' ),
			'event_name'           => sanitize_text_field( $data['event_name'] ?? '' ),
		);
	}

	public function persist_request_record( array $record ): array {
		$request_id = 0;
		$item_id    = 0;

		if ( is_object( $this->requests ) && method_exists( $this->requests, 'save' ) ) {
			$request_id = (int) $this->requests->save(
				array(
					'event_id'       => (int) $record['event_id'],
					'vendor_id'      => (int) $record['vendor_id'],
					'customer_id'    => (int) $record['customer_id'],
					'wp_user_id'     => (int) $record['wp_user_id'],
					'customer_name'  => (string) $record['customer_name'],
					'customer_email' => (string) $record['customer_email'],
					'customer_phone' => (string) $record['customer_phone'],
					'request_source' => (string) $record['request_source'],
					'request_status' => 'planned',
					'requested_at'   => $record['requested_at'],
					'approved_at'    => $record['approved_at'],
					'notes'          => (string) $record['notes'],
				)
			);
		}

		if ( is_object( $this->request_items ) && method_exists( $this->request_items, 'save' ) ) {
			$item_id = (int) $this->request_items->save(
				array(
					'request_id'     => $request_id,
					'event_id'       => (int) $record['event_id'],
					'vendor_id'      => (int) $record['vendor_id'],
					'woo_product_id' => (int) $record['woo_product_id'],
					'product_sku'    => (string) $record['product_sku'],
					'product_name'   => (string) $record['product_name'],
					'quantity'       => (float) $record['quantity_requested'],
					'item_status'    => 'planned',
					'notes'          => (string) $record['notes'],
				)
			);
		}

		return array(
			'request_id' => $request_id,
			'item_id'    => $item_id,
			'persisted'  => $request_id > 0 || $item_id > 0,
		);
	}

	private function validate_event( int $event_id ) {
		if ( $event_id <= 0 ) {
			return new WP_Error( 'aims_missing_event_id', 'Event demand intake requires a valid event_id.' );
		}

		$event = $this->find_event( $event_id );
		if ( empty( $event ) ) {
			return new WP_Error( 'aims_unknown_event', 'Event demand intake requires an existing event.' );
		}

		return $event;
	}

	private function find_event( int $event_id ): ?array {
		if ( is_object( $this->events ) ) {
			foreach ( array( 'find', 'get', 'get_event' ) as $method ) {
				if ( method_exists( $this->events, $method ) ) {
					$event = $this->events->{$method}( $event_id );
					if ( is_array( $event ) ) {
						return $event;
					}
				}
			}

			if ( method_exists( $this->events, 'all' ) ) {
				foreach ( (array) $this->events->all() as $event ) {
					if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
						return $event;
					}
				}
			}
		}

		return null;
	}

	private function validate_authenticated_user( int $wp_user_id ) {
		if ( $wp_user_id <= 0 || ! function_exists( 'get_user_by' ) ) {
			return new WP_Error( 'aims_login_required', 'Event demand intake requires a logged-in WordPress user.' );
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user || ! is_object( $user ) ) {
			return new WP_Error( 'aims_invalid_wp_user', 'Event demand intake requires a valid linked WordPress user account.' );
		}

		return $user;
	}

	private function validate_product_match( int $woo_product_id, string $product_sku ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return true;
		}

		$product = wc_get_product( $woo_product_id );
		if ( ! $product || ! is_object( $product ) ) {
			return new WP_Error( 'aims_unknown_woo_product', 'woo_product_id does not resolve to a WooCommerce product.' );
		}

		if ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
			return new WP_Error( 'aims_invalid_physical_product', 'Event demand intake requires a physical WooCommerce product.' );
		}

		$resolved_sku = $this->normalize_sku( $product->get_sku() );
		if ( '' === $resolved_sku ) {
			return new WP_Error( 'aims_missing_woo_product_sku', 'The selected WooCommerce product does not have a SKU.' );
		}

		if ( $resolved_sku !== $product_sku ) {
			return new WP_Error( 'aims_woo_product_sku_mismatch', 'woo_product_id and product_sku must match the same WooCommerce product.' );
		}

		return $product;
	}

	private function normalize_sku( $sku ): string {
		return strtoupper( trim( sanitize_text_field( (string) $sku ) ) );
	}

	private function resolve_authenticated_user_context( array $data ): array {
		$wp_user_id = (int) ( $data['wp_user_id'] ?? 0 );

		if ( $wp_user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
			$wp_user_id = (int) get_current_user_id();
		}

		$context = array(
			'wp_user_id'     => $wp_user_id,
			'customer_name'  => '',
			'customer_email' => '',
			'customer_phone' => '',
		);

		if ( $wp_user_id <= 0 || ! function_exists( 'get_user_by' ) ) {
			return $context;
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user || ! is_object( $user ) ) {
			return $context;
		}

		$display_name = '';
		if ( ! empty( $user->display_name ) ) {
			$display_name = (string) $user->display_name;
		} elseif ( ! empty( $user->first_name ) || ! empty( $user->last_name ) ) {
			$display_name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		} elseif ( ! empty( $user->user_login ) ) {
			$display_name = (string) $user->user_login;
		}

		$context['customer_name']  = sanitize_text_field( $display_name );
		$context['customer_email'] = sanitize_email( $user->user_email ?? '' );

		if ( function_exists( 'get_user_meta' ) ) {
			$context['customer_phone'] = sanitize_text_field(
				(string) get_user_meta( $wp_user_id, 'billing_phone', true )
			);
		}

		return $context;
	}
}
