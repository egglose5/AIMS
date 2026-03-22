<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Shipping_Queue_Data_Provider {
	private $sale_repository;
	private $event_repository;
	private $customer_repository;
	private $scope_resolver;

	public function __construct(
		AIMS_Square_Sale_Repository $sale_repository = null,
		AIMS_Event_Repository $event_repository = null,
		AIMS_Customer_Repository $customer_repository = null,
		AIMS_Admin_Scope_Resolver $scope_resolver = null
	) {
		$this->sale_repository     = $sale_repository ?: new AIMS_Square_Sale_Repository();
		$this->event_repository    = $event_repository ?: new AIMS_Event_Repository();
		$this->customer_repository = $customer_repository ?: new AIMS_Customer_Repository();
		$this->scope_resolver      = $scope_resolver ?: new AIMS_Admin_Scope_Resolver();
	}

	public function get_rows(): array {
		return $this->get_service_rows();
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

		$table          = $this->sale_repository->get_table_name();
		$event_table    = $this->event_repository->get_table_name();
		$customer_table = $this->customer_repository->get_table_name();
		$alloc_table    = $wpdb->prefix . 'aims_sale_fulfillment_allocations';
		$scope          = $this->scope_resolver->get_accessible_scope_ids();
		$where_parts    = array(
			"s.fulfillment_status IN ('needs_shipping', 'needs_shipping_info', 'backordered')",
		);
		$params         = array();

		if ( empty( $scope['all'] ) ) {
			$scope_clauses = array();

			if ( ! empty( $scope['vendor_ids'] ) ) {
				$placeholders   = implode( ',', array_fill( 0, count( $scope['vendor_ids'] ), '%d' ) );
				$scope_clauses[] = 's.vendor_id IN (' . $placeholders . ')';
				$params          = array_merge( $params, $scope['vendor_ids'] );
			}

			if ( ! empty( $scope['event_ids'] ) ) {
				$placeholders   = implode( ',', array_fill( 0, count( $scope['event_ids'] ), '%d' ) );
				$scope_clauses[] = 's.event_id IN (' . $placeholders . ')';
				$params          = array_merge( $params, $scope['event_ids'] );
			}

			if ( ! empty( $scope['bucket_codes'] ) ) {
				$placeholders   = implode( ',', array_fill( 0, count( $scope['bucket_codes'] ), '%s' ) );
				$scope_clauses[] = 'EXISTS (SELECT 1 FROM ' . $alloc_table . ' a WHERE a.square_sale_id = s.id AND a.source_bucket_code IN (' . $placeholders . '))';
				$params          = array_merge( $params, $scope['bucket_codes'] );
			}

			if ( empty( $scope_clauses ) ) {
				return array();
			}

			$where_parts[] = '(' . implode( ' OR ', $scope_clauses ) . ')';
		}

		$sql = "
			SELECT
				s.id,
				s.square_order_id,
				s.fulfillment_status,
				s.shipping_amount,
				s.discount_amount,
				(
					SELECT a.allocation_type
					FROM {$alloc_table} a
					WHERE a.square_sale_id = s.id
					ORDER BY a.created_at ASC, a.id ASC
					LIMIT 1
				) AS allocation_type,
				(
					SELECT a.allocation_status
					FROM {$alloc_table} a
					WHERE a.square_sale_id = s.id
					ORDER BY a.created_at ASC, a.id ASC
					LIMIT 1
				) AS allocation_status,
				(
					SELECT a.source_bucket_code
					FROM {$alloc_table} a
					WHERE a.square_sale_id = s.id
					ORDER BY a.created_at ASC, a.id ASC
					LIMIT 1
				) AS source_bucket_code,
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
			WHERE " . implode( ' AND ', $where_parts ) . "
			ORDER BY
				CASE s.fulfillment_status
					WHEN 'needs_shipping_info' THEN 1
					WHEN 'needs_shipping' THEN 2
					WHEN 'backordered' THEN 3
					ELSE 4
				END,
				s.sold_at ASC,
				s.id ASC
			LIMIT 25
		";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

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

		$source_label = $this->build_source_label( $row );

		return array(
			'order_ref'      => $order_ref,
			'customer_name'  => $customer,
			'event_name'     => ! empty( $row['event_name'] ) ? (string) $row['event_name'] : 'Unassigned event',
			'shipping_label' => 'needs_shipping' === $status ? 'AIMS Shipping Required' : ucfirst( str_replace( '_', ' ', $status ) ),
			'status'         => $status,
			'queue_type'     => $this->get_queue_type_label( $status ),
			'scope_label'    => $this->build_scope_label( $row ),
			'source_label'   => $source_label,
			'created_at'     => ! empty( $row['created_at'] ) ? (string) $row['created_at'] : current_time( 'mysql' ),
		);
	}

	private function build_scope_label( array $row ): string {
		$allocation_type = ! empty( $row['allocation_type'] ) ? (string) $row['allocation_type'] : '';
		$source_bucket   = ! empty( $row['source_bucket_code'] ) ? (string) $row['source_bucket_code'] : '';
		$event_name      = ! empty( $row['event_name'] ) ? (string) $row['event_name'] : '';

		if ( 'event_stock' === $allocation_type && $source_bucket !== '' ) {
			return 'Event bucket: ' . $source_bucket;
		}

		if ( 'warehouse_pick' === $allocation_type ) {
			return 'Warehouse pick';
		}

		if ( 'warehouse_backorder' === $allocation_type ) {
			return 'Warehouse backorder';
		}

		if ( $event_name !== '' ) {
			return 'Event: ' . $event_name;
		}

		return $source_bucket !== '' ? $source_bucket : 'Unassigned scope';
	}

	private function build_source_label( array $row ): string {
		$allocation_type   = ! empty( $row['allocation_type'] ) ? (string) $row['allocation_type'] : '';
		$source_bucket     = ! empty( $row['source_bucket_code'] ) ? (string) $row['source_bucket_code'] : '';
		$allocation_status = ! empty( $row['allocation_status'] ) ? (string) $row['allocation_status'] : '';

		if ( 'warehouse_backorder' === $allocation_type ) {
			return 'Warehouse backorder';
		}

		if ( 'warehouse_pick' === $allocation_type ) {
			return 'Warehouse pick';
		}

		if ( 'event_stock' === $allocation_type ) {
			return $source_bucket !== '' ? 'Event stock: ' . $source_bucket : 'Event stock';
		}

		if ( 'backordered' === $allocation_status || 'backordered' === ( $row['fulfillment_status'] ?? '' ) ) {
			return 'Warehouse backorder';
		}

		return $source_bucket !== '' ? $source_bucket : 'Unassigned source';
	}

	private function get_queue_type_label( string $status ): string {
		switch ( $status ) {
			case 'needs_shipping_info':
				return 'Needs Shipping Info';
			case 'needs_shipping':
				return 'Needs Shipping';
			case 'backordered':
				return 'Backordered';
			default:
				return ucfirst( str_replace( '_', ' ', $status ) );
		}
	}

}
