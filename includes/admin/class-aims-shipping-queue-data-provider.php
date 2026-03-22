<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Shipping_Queue_Data_Provider {
	private $sale_repository;
	private $event_repository;
	private $customer_repository;

	public function __construct(
		AIMS_Square_Sale_Repository $sale_repository = null,
		AIMS_Event_Repository $event_repository = null,
		AIMS_Customer_Repository $customer_repository = null
	) {
		$this->sale_repository     = $sale_repository ?: new AIMS_Square_Sale_Repository();
		$this->event_repository    = $event_repository ?: new AIMS_Event_Repository();
		$this->customer_repository = $customer_repository ?: new AIMS_Customer_Repository();
	}

	public function get_rows(): array {
		$rows = $this->get_service_rows();

		return ! empty( $rows ) ? $rows : $this->get_placeholder_rows();
	}

	public function get_summary(): array {
		$rows = $this->get_service_rows();

		$summary = array(
			'needs_shipping'      => 0,
			'needs_shipping_info' => 0,
			'backordered'         => 0,
		);

		foreach ( $rows as $row ) {
			$status = ! empty( $row['status'] ) ? (string) $row['status'] : '';
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}

		if ( empty( $rows ) ) {
			$summary['needs_shipping'] = 0;
		}

		return $summary;
	}

	private function get_service_rows(): array {
		global $wpdb;

		$table      = $this->sale_repository->get_table_name();
		$event_table = $this->event_repository->get_table_name();
		$customer_table = $this->customer_repository->get_table_name();

		$sql = "
			SELECT
				s.id,
				s.square_order_id,
				s.fulfillment_status,
				s.shipping_amount,
				s.discount_amount,
				s.sold_at,
				s.created_at,
				s.event_id,
				s.customer_id,
				COALESCE(e.event_name, '') AS event_name,
				COALESCE(
					TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))),
					''
				) AS customer_name
			FROM {$table} s
			LEFT JOIN {$event_table} e ON e.id = s.event_id
			LEFT JOIN {$customer_table} c ON c.id = s.customer_id
			WHERE s.fulfillment_status IN ('needs_shipping', 'needs_shipping_info', 'backordered')
			ORDER BY s.sold_at ASC, s.id ASC
			LIMIT 25
		";

		$results = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $results ) ) {
			return array();
		}

		$rows = array();
		foreach ( $results as $result ) {
			$rows[] = $this->normalize_row( $result );
		}

		return $rows;
	}

	private function normalize_row( array $row ): array {
		$order_ref = ! empty( $row['square_order_id'] ) ? $row['square_order_id'] : 'Order #' . (int) $row['id'];
		$status    = ! empty( $row['fulfillment_status'] ) ? (string) $row['fulfillment_status'] : 'needs_shipping';
		$customer  = trim( (string) ( $row['customer_name'] ?? '' ) );

		if ( '' === $customer ) {
			$customer = 'Unknown customer';
		}

		return array(
			'order_ref'      => $order_ref,
			'customer_name'  => $customer,
			'event_name'     => ! empty( $row['event_name'] ) ? (string) $row['event_name'] : 'Unassigned event',
			'shipping_label' => 'needs_shipping' === $status ? 'AIMS Shipping Required' : ucfirst( str_replace( '_', ' ', $status ) ),
			'status'         => $status,
			'created_at'     => ! empty( $row['created_at'] ) ? (string) $row['created_at'] : current_time( 'mysql' ),
		);
	}

	private function get_placeholder_rows(): array {
		return array(
			array(
				'order_ref'      => 'SQ-10021',
				'customer_name'  => 'Sample Customer',
				'event_name'     => 'Sample Show',
				'shipping_label' => 'AIMS Shipping Required',
				'status'         => 'needs_shipping',
				'created_at'     => current_time( 'mysql' ),
			),
		);
	}
}
