<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Woo_Order_Projection_Service {
	private $draft_order_creator;
	private $order_promoter;

	public function __construct( callable $draft_order_creator = null, callable $order_promoter = null ) {
		$this->draft_order_creator = $draft_order_creator;
		$this->order_promoter      = $order_promoter;
	}

	public function project_normalized_sale( array $sale_record, array $context = array() ): array {
		$decision = $this->evaluate_projection( $sale_record, $context );

		if ( 'ready' !== $decision['status'] ) {
			return $decision;
		}

		$created = $this->create_draft_order( $sale_record, $context );
		if ( (int) ( $created['woo_order_id'] ?? 0 ) <= 0 ) {
			return array_merge(
				$decision,
				array(
					'status'       => 'skipped',
					'reason'       => (string) ( $created['reason'] ?? 'woocommerce_unavailable' ),
					'woo_order_id' => 0,
				)
			);
		}

		return array_merge(
			$decision,
			$created,
			array(
				'status'          => 'projected',
				'reason'          => (string) ( $created['reason'] ?? 'draft_projected' ),
				'woo_order_id'    => (int) ( $created['woo_order_id'] ?? 0 ),
				'projection_mode' => (string) ( $created['projection_mode'] ?? $decision['projection_mode'] ?? 'draft' ),
			)
		);
	}

	public function evaluate_projection( array $sale_record, array $context = array() ): array {
		$projection_mode      = sanitize_key( (string) ( $context['projection_mode'] ?? 'draft' ) );
		$reconciliation_state = sanitize_key( (string) ( $context['reconciliation_status'] ?? 'pending' ) );
		$woo_order_id         = (int) ( $sale_record['woo_order_id'] ?? 0 );
		$result               = array(
			'status'          => 'ready',
			'reason'          => 'projection_ready',
			'projection_mode' => '' !== $projection_mode ? $projection_mode : 'draft',
			'woo_order_id'    => $woo_order_id,
			'square_sale_id'  => (int) ( $sale_record['normalized_sale_id'] ?? $sale_record['id'] ?? 0 ),
			'square_order_id' => (string) ( $sale_record['square_order_id'] ?? '' ),
			'event_id'        => (int) ( $sale_record['event_id'] ?? 0 ),
			'vendor_id'       => (int) ( $sale_record['vendor_id'] ?? 0 ),
		);

		if ( $woo_order_id > 0 ) {
			$result['status'] = 'linked';
			$result['reason'] = 'already_linked';
			return $result;
		}

		if ( empty( $context['allow_woo_order_projection'] ) ) {
			$result['status'] = 'skipped';
			$result['reason'] = 'projection_disabled';
			return $result;
		}

		if ( empty( $context['allow_unreconciled_projection'] ) && 'reconciled' !== $reconciliation_state ) {
			$result['status'] = 'skipped';
			$result['reason'] = 'awaiting_reconciliation';
			return $result;
		}

		if ( ! in_array( $result['projection_mode'], array( 'draft', 'pending' ), true ) ) {
			$result['status'] = 'skipped';
			$result['reason'] = 'unsupported_projection_mode';
			return $result;
		}

		return $result;
	}

	private function create_draft_order( array $sale_record, array $context = array() ): array {
		$resolved_charges              = $this->resolve_projection_charges( $sale_record, $context );
		$context['projection_charges'] = $resolved_charges;

		if ( is_callable( $this->draft_order_creator ) ) {
			return $this->normalize_created_order_result(
				call_user_func( $this->draft_order_creator, $sale_record, $context ),
				$context
			);
		}

		if ( ! function_exists( 'wc_create_order' ) ) {
			return array(
				'woo_order_id'    => 0,
				'projection_mode' => sanitize_key( (string) ( $context['projection_mode'] ?? 'draft' ) ),
				'reason'          => 'woocommerce_unavailable',
			);
		}

		$projection_mode = sanitize_key( (string) ( $context['projection_mode'] ?? 'draft' ) );
		$order_status    = 'pending' === $projection_mode ? 'pending' : 'draft';

		$order = wc_create_order( array( 'status' => $order_status ) );
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $order ) ) || ! is_object( $order ) ) {
			return array(
				'woo_order_id'    => 0,
				'projection_mode' => $projection_mode,
				'reason'          => 'draft_order_create_failed',
			);
		}

		$line_item_added = $this->add_projected_line_item_to_order( $order, $sale_record );
		$charge_count    = $this->add_projection_charges_to_order( $order, $resolved_charges );
		$customer_link   = $this->apply_customer_profile_to_order( $order, $sale_record, $context );

		if ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_aims_square_order_id', (string) ( $sale_record['square_order_id'] ?? '' ) );
			$order->update_meta_data( '_aims_square_sale_id', (int) ( $sale_record['normalized_sale_id'] ?? $sale_record['id'] ?? 0 ) );
			$order->update_meta_data( '_aims_projection_source', 'aims_square_replay' );
			$sync_run_id = (int) ( $context['sync_run_id'] ?? 0 );
			if ( $sync_run_id > 0 ) {
				$order->update_meta_data( '_aims_sync_run_id', $sync_run_id );
			}
		}

		if ( method_exists( $order, 'calculate_totals' ) ) {
			$order->calculate_totals( false );
		}

		if ( method_exists( $order, 'add_order_note' ) ) {
			if ( 'pending' === $order_status ) {
				$order->add_order_note( 'AIMS projection created from Square sale replay and set to pending for native WooCommerce tracking.' );
			} else {
				$order->add_order_note( 'AIMS draft projection created from Square sale replay pending operational reconciliation.' );
			}
		}

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		$woo_order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$reason       = $line_item_added
			? ( 'pending' === $order_status ? 'pending_projected' : 'draft_projected' )
			: ( 'pending' === $order_status ? 'pending_projected_without_line_item' : 'draft_projected_without_line_item' );

		return array(
			'woo_order_id'    => $woo_order_id,
			'projection_mode' => $projection_mode,
			'projection_charge_count' => $charge_count,
			'woo_customer_id' => (int) ( $customer_link['woo_customer_id'] ?? 0 ),
			'account_invite_attempted' => ! empty( $customer_link['account_invite_attempted'] ),
			'reason'          => $woo_order_id > 0 ? $reason : 'draft_order_create_failed',
		);
	}

	private function apply_customer_profile_to_order( $order, array $sale_record, array $context = array() ): array {
		$customer_data = (array) ( $context['customer_data'] ?? array() );
		$address_data  = (array) ( $context['address_data'] ?? array() );

		if ( ! is_object( $order ) ) {
			return array(
				'woo_customer_id'          => 0,
				'account_invite_attempted' => false,
			);
		}

		$billing_first_name = sanitize_text_field( (string) ( $customer_data['first_name'] ?? '' ) );
		$billing_last_name  = sanitize_text_field( (string) ( $customer_data['last_name'] ?? '' ) );
		$billing_company    = sanitize_text_field( (string) ( $customer_data['company_name'] ?? '' ) );
		$billing_email      = sanitize_email( (string) ( $customer_data['email_address'] ?? '' ) );
		$billing_phone      = sanitize_text_field( (string) ( $customer_data['phone_number'] ?? '' ) );

		$this->set_order_field( $order, 'set_billing_first_name', $billing_first_name );
		$this->set_order_field( $order, 'set_billing_last_name', $billing_last_name );
		$this->set_order_field( $order, 'set_billing_company', $billing_company );
		$this->set_order_field( $order, 'set_billing_email', $billing_email );
		$this->set_order_field( $order, 'set_billing_phone', $billing_phone );

		$shipping_address_1 = sanitize_text_field( (string) ( $address_data['address_line_1'] ?? '' ) );
		$shipping_address_2 = sanitize_text_field( (string) ( $address_data['address_line_2'] ?? '' ) );
		$shipping_city      = sanitize_text_field( (string) ( $address_data['city'] ?? '' ) );
		$shipping_state     = sanitize_text_field( (string) ( $address_data['state_region'] ?? '' ) );
		$shipping_postcode  = sanitize_text_field( (string) ( $address_data['postal_code'] ?? '' ) );
		$shipping_country   = strtoupper( sanitize_text_field( (string) ( $address_data['country_code'] ?? 'US' ) ) );

		$this->set_order_field( $order, 'set_billing_address_1', $shipping_address_1 );
		$this->set_order_field( $order, 'set_billing_address_2', $shipping_address_2 );
		$this->set_order_field( $order, 'set_billing_city', $shipping_city );
		$this->set_order_field( $order, 'set_billing_state', $shipping_state );
		$this->set_order_field( $order, 'set_billing_postcode', $shipping_postcode );
		$this->set_order_field( $order, 'set_billing_country', $shipping_country );

		$this->set_order_field( $order, 'set_shipping_first_name', $billing_first_name );
		$this->set_order_field( $order, 'set_shipping_last_name', $billing_last_name );
		$this->set_order_field( $order, 'set_shipping_company', $billing_company );
		$this->set_order_field( $order, 'set_shipping_address_1', $shipping_address_1 );
		$this->set_order_field( $order, 'set_shipping_address_2', $shipping_address_2 );
		$this->set_order_field( $order, 'set_shipping_city', $shipping_city );
		$this->set_order_field( $order, 'set_shipping_state', $shipping_state );
		$this->set_order_field( $order, 'set_shipping_postcode', $shipping_postcode );
		$this->set_order_field( $order, 'set_shipping_country', $shipping_country );

		$allow_account_invite = ! array_key_exists( 'allow_customer_account_invite', $context ) || ! empty( $context['allow_customer_account_invite'] );
		$woo_customer_id      = $allow_account_invite ? $this->resolve_or_create_wc_customer( $customer_data, $address_data ) : 0;

		if ( $woo_customer_id > 0 ) {
			$this->set_order_field( $order, 'set_customer_id', $woo_customer_id );
		}

		if ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_aims_square_customer_id', (string) ( $customer_data['square_customer_id'] ?? '' ) );
			$order->update_meta_data( '_aims_customer_account_invite_attempted', $allow_account_invite && '' !== $billing_email ? 'yes' : 'no' );
		}

		return array(
			'woo_customer_id'          => $woo_customer_id,
			'account_invite_attempted' => $allow_account_invite && '' !== $billing_email,
		);
	}

	private function set_order_field( $order, string $method, $value ): void {
		if ( is_object( $order ) && method_exists( $order, $method ) ) {
			$order->{$method}( $value );
		}
	}

	private function resolve_or_create_wc_customer( array $customer_data, array $address_data = array() ): int {
		$email = sanitize_email( (string) ( $customer_data['email_address'] ?? '' ) );
		if ( '' === $email ) {
			return 0;
		}

		if ( function_exists( 'email_exists' ) ) {
			$existing_user_id = (int) email_exists( $email );
			if ( $existing_user_id > 0 ) {
				return $existing_user_id;
			}
		}

		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			return 0;
		}

		$username = $this->derive_customer_username( $email );
		$password = function_exists( 'wp_generate_password' ) ? wp_generate_password( 20, true, true ) : wp_generate_uuid4();
		$args     = array(
			'first_name' => sanitize_text_field( (string) ( $customer_data['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $customer_data['last_name'] ?? '' ) ),
		);

		$billing_phone = sanitize_text_field( (string) ( $customer_data['phone_number'] ?? '' ) );
		if ( '' !== $billing_phone ) {
			$args['billing_phone'] = $billing_phone;
		}

		$billing_address_1 = sanitize_text_field( (string) ( $address_data['address_line_1'] ?? '' ) );
		if ( '' !== $billing_address_1 ) {
			$args['billing_address_1'] = $billing_address_1;
			$args['billing_address_2'] = sanitize_text_field( (string) ( $address_data['address_line_2'] ?? '' ) );
			$args['billing_city']      = sanitize_text_field( (string) ( $address_data['city'] ?? '' ) );
			$args['billing_state']     = sanitize_text_field( (string) ( $address_data['state_region'] ?? '' ) );
			$args['billing_postcode']  = sanitize_text_field( (string) ( $address_data['postal_code'] ?? '' ) );
			$args['billing_country']   = strtoupper( sanitize_text_field( (string) ( $address_data['country_code'] ?? 'US' ) ) );
		}

		$created = wc_create_new_customer( $email, $username, $password, $args );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $created ) ) {
			return 0;
		}

		return (int) $created;
	}

	private function derive_customer_username( string $email ): string {
		$raw_base = (string) preg_replace( '/@.*$/', '', strtolower( $email ) );
		$base     = function_exists( 'sanitize_user' )
			? sanitize_user( $raw_base, true )
			: sanitize_key( preg_replace( '/[^a-z0-9_\-]/i', '_', $raw_base ) );
		if ( '' === $base ) {
			$base = 'aims_customer';
		}

		if ( ! function_exists( 'username_exists' ) ) {
			return $base;
		}

		if ( ! username_exists( $base ) ) {
			return $base;
		}

		for ( $suffix = 2; $suffix <= 1000; $suffix++ ) {
			$candidate = $base . '_' . $suffix;
			if ( ! username_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return $base . '_' . wp_generate_uuid4();
	}

	private function add_projected_line_item_to_order( $order, array $sale_record ): bool {
		if ( ! function_exists( 'wc_get_product' ) || ! is_object( $order ) || ! method_exists( $order, 'add_product' ) ) {
			return false;
		}

		$product_id = (int) ( $sale_record['woo_product_id'] ?? 0 );
		$quantity   = max( 1.0, (float) ( $sale_record['quantity'] ?? 1 ) );
		$line_total = max( 0.0, (float) ( $sale_record['net_amount'] ?? 0 ) );

		if ( $product_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) ) {
			return false;
		}

		$order->add_product(
			$product,
			$quantity,
			array(
				'subtotal' => $line_total,
				'total'    => $line_total,
			)
		);

		return true;
	}

	private function add_projection_charges_to_order( $order, array $charges ): int {
		if ( empty( $charges ) || ! is_object( $order ) || ! method_exists( $order, 'add_item' ) || ! class_exists( 'WC_Order_Item_Fee' ) ) {
			return 0;
		}

		$added = 0;

		foreach ( $charges as $charge ) {
			$amount = isset( $charge['amount'] ) ? (float) $charge['amount'] : 0.0;
			$label  = sanitize_text_field( (string) ( $charge['label'] ?? '' ) );

			if ( $amount <= 0 || '' === $label ) {
				continue;
			}

			$fee = new \WC_Order_Item_Fee();
			if ( method_exists( $fee, 'set_name' ) ) {
				$fee->set_name( $label );
			}
			if ( method_exists( $fee, 'set_amount' ) ) {
				$fee->set_amount( $amount );
			}
			if ( method_exists( $fee, 'set_total' ) ) {
				$fee->set_total( $amount );
			}

			$is_taxable = ! empty( $charge['taxable'] );
			if ( method_exists( $fee, 'set_tax_status' ) ) {
				$fee->set_tax_status( $is_taxable ? 'taxable' : 'none' );
			}

			$tax_class = sanitize_text_field( (string) ( $charge['tax_class'] ?? '' ) );
			if ( '' !== $tax_class && method_exists( $fee, 'set_tax_class' ) ) {
				$fee->set_tax_class( $tax_class );
			}

			if ( ! empty( $charge['meta'] ) && is_array( $charge['meta'] ) && method_exists( $fee, 'add_meta_data' ) ) {
				foreach ( $charge['meta'] as $meta_key => $meta_value ) {
					$meta_key = sanitize_key( (string) $meta_key );
					if ( '' === $meta_key ) {
						continue;
					}

					$fee->add_meta_data( $meta_key, is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value ) );
				}
			}

			$order->add_item( $fee );
			++$added;
		}

		return $added;
	}

	private function resolve_projection_charges( array $sale_record, array $context = array() ): array {
		$charges = array();

		$unfulfilled_charge = $this->build_unfulfilled_charge( $sale_record, $context );
		if ( ! empty( $unfulfilled_charge ) ) {
			$charges[] = $unfulfilled_charge;
		}

		if ( ! empty( $context['additional_projection_charges'] ) && is_array( $context['additional_projection_charges'] ) ) {
			foreach ( $context['additional_projection_charges'] as $charge ) {
				if ( is_array( $charge ) ) {
					$normalized = $this->normalize_projection_charge( $charge );
					if ( ! empty( $normalized ) ) {
						$charges[] = $normalized;
					}
				}
			}
		}

		return $charges;
	}

	private function build_unfulfilled_charge( array $sale_record, array $context = array() ): array {
		$unfulfilled_statuses = array( 'pending', 'unfulfilled', 'processing', 'backordered' );
		$status               = sanitize_key( (string) ( $sale_record['fulfillment_status'] ?? '' ) );

		if ( ! in_array( $status, $unfulfilled_statuses, true ) ) {
			return array();
		}

		if ( ! empty( $context['unfulfilled_charge'] ) && is_array( $context['unfulfilled_charge'] ) ) {
			return $this->normalize_projection_charge(
				array_merge(
					array( 'code' => 'unfulfilled' ),
					$context['unfulfilled_charge']
				)
			);
		}

		$amount = isset( $context['unfulfilled_charge_amount'] ) ? (float) $context['unfulfilled_charge_amount'] : 0.0;
		if ( $amount <= 0 ) {
			return array();
		}

		return $this->normalize_projection_charge(
			array(
				'code'   => 'unfulfilled',
				'label'  => (string) ( $context['unfulfilled_charge_label'] ?? 'Unfulfilled Line Charge' ),
				'amount' => $amount,
			)
		);
	}

	private function normalize_projection_charge( array $charge ): array {
		$amount = isset( $charge['amount'] ) ? (float) $charge['amount'] : 0.0;
		$label  = sanitize_text_field( (string) ( $charge['label'] ?? '' ) );

		if ( $amount <= 0 || '' === $label ) {
			return array();
		}

		$normalized = array(
			'code'     => sanitize_key( (string) ( $charge['code'] ?? '' ) ),
			'label'    => $label,
			'amount'   => $amount,
			'taxable'  => ! empty( $charge['taxable'] ),
			'tax_class'=> sanitize_text_field( (string) ( $charge['tax_class'] ?? '' ) ),
		);

		if ( ! empty( $charge['meta'] ) && is_array( $charge['meta'] ) ) {
			$normalized['meta'] = $charge['meta'];
		}

		return $normalized;
	}

	/**
	 * Promotes all draft WooCommerce orders associated with the given run to 'pending'.
	 *
	 * @param int   $run_id        The sync run ID (informational — used in the return value).
	 * @param int[] $woo_order_ids WooCommerce order IDs to attempt promotion.
	 * @return array{run_id:int, promoted_count:int, skipped_count:int, errors:array}
	 */
	public function promote_draft_projections_for_run( int $run_id, array $woo_order_ids ): array {
		$promoted = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $woo_order_ids as $order_id ) {
			$order_id = (int) $order_id;
			if ( $order_id <= 0 ) {
				++$skipped;
				continue;
			}

			if ( is_callable( $this->order_promoter ) ) {
				$outcome = call_user_func( $this->order_promoter, $order_id );
				$status  = (string) ( is_array( $outcome ) ? ( $outcome['status'] ?? 'skipped' ) : 'skipped' );
			} else {
				$status = $this->promote_single_order( $order_id );
			}

			if ( 'promoted' === $status ) {
				++$promoted;
			} elseif ( 'error' === $status ) {
				$errors[] = $order_id;
			} else {
				++$skipped;
			}
		}

		return array(
			'run_id'         => $run_id,
			'promoted_count' => $promoted,
			'skipped_count'  => $skipped,
			'errors'         => $errors,
		);
	}

	private function promote_single_order( int $order_id ): string {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 'skipped';
		}

		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
			return 'skipped';
		}

		if ( 'draft' !== $order->get_status() ) {
			return 'skipped';
		}

		if ( method_exists( $order, 'set_status' ) ) {
			$order->set_status( 'pending' );
		}

		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'AIMS: draft projection promoted to pending by operator.' );
		}

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return 'promoted';
	}

	private function normalize_created_order_result( $result, array $context = array() ): array {		$projection_mode = sanitize_key( (string) ( $context['projection_mode'] ?? 'draft' ) );

		if ( is_numeric( $result ) ) {
			$result = array( 'woo_order_id' => (int) $result );
		} elseif ( is_object( $result ) && method_exists( $result, 'get_id' ) ) {
			$result = array( 'woo_order_id' => (int) $result->get_id() );
		} elseif ( ! is_array( $result ) ) {
			$result = array();
		}

		$woo_order_id = (int) ( $result['woo_order_id'] ?? $result['order_id'] ?? 0 );

		return array(
			'woo_order_id'    => $woo_order_id,
			'projection_mode' => (string) ( $result['projection_mode'] ?? ( '' !== $projection_mode ? $projection_mode : 'draft' ) ),
			'reason'          => (string) ( $result['reason'] ?? ( $woo_order_id > 0 ? 'draft_projected' : 'draft_order_create_failed' ) ),
		);
	}
}
