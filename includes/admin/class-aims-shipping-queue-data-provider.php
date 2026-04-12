<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Shipping_Queue_Data_Provider {
	private $sale_repo;

	public function __construct( AIMS_Square_Sale_Repository $sale_repo = null ) {
		$this->sale_repo = $sale_repo ?: new AIMS_Square_Sale_Repository();
	}

	public function get_rows(): array {
		$sales = $this->sale_repo->get_by_fulfillment_statuses(
			array(
				AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING,
				AIMS_Square_Sale_Repository::STATUS_NEEDS_SHIPPING_INFO,
			),
			200
		);

		if ( empty( $sales ) ) {
			return array();
		}

		$rows = array();
		foreach ( $sales as $sale ) {
			$sku = sanitize_text_field( (string) ( $sale['sku'] ?? '' ) );
			$rows[] = array(
				'order_ref'         => sanitize_text_field( (string) ( $sale['square_order_id'] ?? '' ) ),
				'sku'               => $sku,
				'quantity'          => (float) ( $sale['quantity'] ?? 0 ),
				'shipping_label'    => 'needs_shipping_info' === ( $sale['fulfillment_status'] ?? '' )
					? 'Needs Shipping Info'
					: 'Ready to Ship',
				'status'            => sanitize_text_field( (string) ( $sale['fulfillment_status'] ?? '' ) ),
				'event_id'          => (int) ( $sale['event_id'] ?? 0 ),
				'woo_order_id'      => (int) ( $sale['woo_order_id'] ?? 0 ),
				'created_at'        => sanitize_text_field( (string) ( $sale['sold_at'] ?? '' ) ),
				'fifo_location_url' => $this->build_fifo_location_url( $sku ),
			);
		}

		return $rows;
	}

	private function build_fifo_location_url( string $sku ): string {
		if ( '' === $sku ) {
			return '';
		}

		if ( ! function_exists( 'admin_url' ) ) {
			return '';
		}

		return admin_url(
			add_query_arg(
				array(
					'page'         => 'aims',
					'aims_fifo_sku' => $sku,
				),
				'admin.php'
			)
		);
	}
}
