<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sale_Repository {
	public const STATUS_PENDING = 'pending';
	public const STATUS_FULFILLED = 'fulfilled';
	public const STATUS_NEEDS_SHIPPING = 'needs_shipping';
	public const STATUS_NEEDS_SHIPPING_INFO = 'needs_shipping_info';
	public const STATUS_BACKORDERED = 'backordered';
	public const STATUS_SHIPPED = 'shipped';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_square_sales';
	}

	public function allowed_fulfillment_statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_FULFILLED,
			self::STATUS_NEEDS_SHIPPING,
			self::STATUS_NEEDS_SHIPPING_INFO,
			self::STATUS_BACKORDERED,
			self::STATUS_SHIPPED,
		);
	}

	public function normalize_fulfillment_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, $this->allowed_fulfillment_statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	public function save( array $data ): int {
		global $wpdb;

		$record = array(
			'square_order_id'      => sanitize_text_field( $data['square_order_id'] ?? '' ),
			'square_line_item_uid' => sanitize_text_field( $data['square_line_item_uid'] ?? '' ),
			'square_location_id'   => sanitize_text_field( $data['square_location_id'] ?? '' ),
			'square_customer_id'   => sanitize_text_field( $data['square_customer_id'] ?? '' ),
			'customer_id'          => (int) ( $data['customer_id'] ?? 0 ),
			'shipping_address_id'  => (int) ( $data['shipping_address_id'] ?? 0 ),
			'billing_address_id'   => (int) ( $data['billing_address_id'] ?? 0 ),
			'vendor_id'            => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'             => (int) ( $data['event_id'] ?? 0 ),
			'woo_order_id'         => (int) ( $data['woo_order_id'] ?? 0 ),
			'woo_product_id'       => (int) ( $data['woo_product_id'] ?? 0 ),
			'sku'                  => sanitize_text_field( $data['sku'] ?? '' ),
			'source'               => sanitize_key( $data['source'] ?? 'square' ),
			'delivery_method'      => sanitize_key( $data['delivery_method'] ?? 'pickup' ),
			'shipping_amount'      => number_format( (float) ( $data['shipping_amount'] ?? 0 ), 2, '.', '' ),
			'discount_amount'      => number_format( (float) ( $data['discount_amount'] ?? 0 ), 2, '.', '' ),
			'discount_label'       => sanitize_text_field( $data['discount_label'] ?? '' ),
			'tip_amount'           => number_format( (float) ( $data['tip_amount'] ?? 0 ), 2, '.', '' ),
			'tax_amount'           => number_format( (float) ( $data['tax_amount'] ?? 0 ), 2, '.', '' ),
			'amount_paid'          => number_format( (float) ( $data['amount_paid'] ?? $data['net_amount'] ?? 0 ), 2, '.', '' ),
			'fulfillment_status'   => $this->normalize_fulfillment_status( (string) ( $data['fulfillment_status'] ?? self::STATUS_PENDING ) ),
			'quantity'             => number_format( (float) ( $data['quantity'] ?? 0 ), 4, '.', '' ),
			'gross_amount'         => number_format( (float) ( $data['gross_amount'] ?? 0 ), 2, '.', '' ),
			'net_amount'           => number_format( (float) ( $data['net_amount'] ?? 0 ), 2, '.', '' ),
			'payload'              => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
			'sold_at'              => $data['sold_at'] ?? null,
			'updated_at'           => current_time( 'mysql' ),
		);

		$existing_id = $this->find_existing_id( $record['square_order_id'], $record['square_line_item_uid'] );

		if ( $existing_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%f', '%f', '%f', '%s', '%f', '%f', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $existing_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%f', '%f', '%f', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	private function find_existing_id( string $square_order_id, string $square_line_item_uid ): int {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $this->get_table_name() . ' WHERE square_order_id = %s AND square_line_item_uid = %s',
				$square_order_id,
				$square_line_item_uid
			)
		);

		return (int) $id;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY sold_at ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_net_total_for_event_vendor( int $event_id, int $vendor_id ): float {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(net_amount), 0) FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND vendor_id = %d',
				$event_id,
				$vendor_id
			)
		);

		return (float) $total;
	}

	public function get_discount_total_for_event( int $event_id ): float {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(discount_amount), 0) FROM ' . $this->get_table_name() . ' WHERE event_id = %d',
				$event_id
			)
		);

		return (float) $total;
	}

	public function get_tip_total_for_event( int $event_id ): float {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(tip_amount), 0) FROM ' . $this->get_table_name() . ' WHERE event_id = %d',
				$event_id
			)
		);

		return (float) $total;
	}

	public function get_unassigned_sales_by_location_and_date( string $square_location_id, string $sold_at ): array {
		global $wpdb;

		$sold_date = $this->normalize_date( $sold_at );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = 0 AND square_location_id = %s AND DATE(sold_at) = %s ORDER BY id ASC',
				sanitize_text_field( $square_location_id ),
				$sold_date
			),
			ARRAY_A
		);
	}

	public function assign_event( int $sale_id, int $event_id, int $vendor_id = 0 ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'event_id'   => $event_id,
				'vendor_id'  => $vendor_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $sale_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function update_fulfillment_status( int $sale_id, string $status ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'fulfillment_status' => $this->normalize_fulfillment_status( $status ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $sale_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	private function normalize_date( string $value ): string {
		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d', $time ) : sanitize_text_field( $value );
	}
}
