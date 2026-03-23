<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Customer_Request_Item_Repository {
	public const STATUS_PLANNED = 'planned';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_FULFILLED = 'fulfilled';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_customer_request_items';
	}

	public function save( array $data, int $item_id = 0 ): int {
		global $wpdb;

		$record = array(
			'request_id'          => (int) ( $data['request_id'] ?? 0 ),
			'event_id'            => (int) ( $data['event_id'] ?? 0 ),
			'vendor_id'           => (int) ( $data['vendor_id'] ?? 0 ),
			'woo_product_id'      => (int) ( $data['woo_product_id'] ?? 0 ),
			'product_sku'         => sanitize_text_field( $data['product_sku'] ?? '' ),
			'product_name'        => sanitize_text_field( $data['product_name'] ?? '' ),
			'quantity'            => number_format( (float) ( $data['quantity'] ?? $data['quantity_requested'] ?? 0 ), 4, '.', '' ),
			'quantity_requested'  => number_format( (float) ( $data['quantity_requested'] ?? $data['quantity'] ?? 0 ), 4, '.', '' ),
			'notes'               => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'request_note'        => isset( $data['request_note'] ) ? sanitize_textarea_field( $data['request_note'] ) : sanitize_textarea_field( $data['notes'] ?? '' ),
			'item_status'         => $this->normalize_status( (string) ( $data['item_status'] ?? $data['status'] ?? self::STATUS_PLANNED ) ),
			'updated_at'          => current_time( 'mysql' ),
		);

		if ( $item_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $item_id ) );
			return $item_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $item_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$item_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_request( int $request_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE request_id = %d ORDER BY product_sku ASC, id ASC',
				$request_id
			),
			ARRAY_A
		);
	}

	public function get_for_requests( array $request_ids ): array {
		global $wpdb;

		$request_ids = array_values( array_filter( array_map( 'intval', $request_ids ) ) );
		if ( empty( $request_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $request_ids ), '%d' ) );
		$query        = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE request_id IN (' . $placeholders . ') ORDER BY request_id DESC, product_sku ASC, id ASC';

		return $wpdb->get_results(
			$wpdb->prepare( $query, $request_ids ),
			ARRAY_A
		);
	}

	public function get_for_wp_user_id( int $wp_user_id ): array {
		global $wpdb;

		$requests_table = $wpdb->prefix . 'aims_event_customer_requests';

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT items.* FROM ' . $this->get_table_name() . ' items INNER JOIN ' . $requests_table . ' requests ON requests.id = items.request_id WHERE requests.wp_user_id = %d ORDER BY requests.requested_at DESC, items.product_sku ASC, items.id ASC',
				$wp_user_id
			),
			ARRAY_A
		);
	}

	public function get_for_event_by_sku( int $event_id, string $product_sku ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND product_sku = %s ORDER BY vendor_id ASC, id ASC',
				$event_id,
				sanitize_text_field( $product_sku )
			),
			ARRAY_A
		);
	}

	public function get_demand_summary_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event_id, product_sku, MAX(woo_product_id) AS woo_product_id, SUM(quantity_requested) AS total_quantity_requested, COUNT(*) AS item_count FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND item_status != %s GROUP BY event_id, product_sku ORDER BY product_sku ASC',
				$event_id,
				self::STATUS_CANCELLED
			),
			ARRAY_A
		);
	}

	public function get_demand_summary_for_event_vendor( int $event_id, int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event_id, vendor_id, product_sku, MAX(woo_product_id) AS woo_product_id, SUM(quantity_requested) AS total_quantity_requested, COUNT(*) AS item_count FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND vendor_id = %d AND item_status != %s GROUP BY event_id, vendor_id, product_sku ORDER BY product_sku ASC',
				$event_id,
				$vendor_id,
				self::STATUS_CANCELLED
			),
			ARRAY_A
		);
	}

	public function update_item_status( int $item_id, string $status ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'item_status' => $this->normalize_status( $status ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $item_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		$allowed = array(
			self::STATUS_PLANNED,
			self::STATUS_CANCELLED,
			self::STATUS_FULFILLED,
		);

		if ( in_array( $status, array( 'active', 'approved', 'pending' ), true ) ) {
			return self::STATUS_PLANNED;
		}

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_PLANNED;
	}
}
