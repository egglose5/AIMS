<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Woo_Order_Projection_Service {
	private $draft_order_creator;

	public function __construct( callable $draft_order_creator = null ) {
		$this->draft_order_creator = $draft_order_creator;
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

		if ( 'draft' !== $result['projection_mode'] ) {
			$result['status'] = 'skipped';
			$result['reason'] = 'unsupported_projection_mode';
			return $result;
		}

		return $result;
	}

	private function create_draft_order( array $sale_record, array $context = array() ): array {
		if ( is_callable( $this->draft_order_creator ) ) {
			return $this->normalize_created_order_result(
				call_user_func( $this->draft_order_creator, $sale_record, $context ),
				$context
			);
		}

		if ( ! function_exists( 'wc_create_order' ) ) {
			return array(
				'woo_order_id'    => 0,
				'projection_mode' => 'draft',
				'reason'          => 'woocommerce_unavailable',
			);
		}

		$order = wc_create_order( array( 'status' => 'draft' ) );
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $order ) ) || ! is_object( $order ) ) {
			return array(
				'woo_order_id'    => 0,
				'projection_mode' => 'draft',
				'reason'          => 'draft_order_create_failed',
			);
		}

		if ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_aims_square_order_id', (string) ( $sale_record['square_order_id'] ?? '' ) );
			$order->update_meta_data( '_aims_square_sale_id', (int) ( $sale_record['normalized_sale_id'] ?? $sale_record['id'] ?? 0 ) );
			$order->update_meta_data( '_aims_projection_source', 'aims_square_replay' );
		}

		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'AIMS draft projection created from Square sale replay pending operational reconciliation.' );
		}

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		$woo_order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;

		return array(
			'woo_order_id'    => $woo_order_id,
			'projection_mode' => 'draft',
			'reason'          => $woo_order_id > 0 ? 'draft_projected' : 'draft_order_create_failed',
		);
	}

	private function normalize_created_order_result( $result, array $context = array() ): array {
		$projection_mode = sanitize_key( (string) ( $context['projection_mode'] ?? 'draft' ) );

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
