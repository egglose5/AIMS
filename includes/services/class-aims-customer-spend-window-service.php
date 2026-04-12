<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Customer_Spend_Window_Service {
	private $customer_resolver;
	private $orders_loader;

	public function __construct( callable $customer_resolver = null, callable $orders_loader = null ) {
		$this->customer_resolver = $customer_resolver ?: array( $this, 'resolve_customer_default' );
		$this->orders_loader     = $orders_loader ?: array( $this, 'load_orders_default' );
	}

	public function get_dashboard_snapshot( string $customer_lookup = '', ?int $window_days = null, int $limit = 10 ): array {
		$lookup      = sanitize_text_field( trim( $customer_lookup ) );
		$days        = null === $window_days ? AIMS_Plugin::get_customer_spend_window_days() : AIMS_Plugin::sanitize_customer_spend_window_days( (string) $window_days );
		$after_unix  = time() - ( $days * 86400 );
		$after_iso   = gmdate( 'Y-m-d H:i:s', $after_unix );
		$statuses    = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );
		$limit       = max( 1, min( 200, $limit ) );

		if ( '' === $lookup ) {
			return array(
				'window_days'      => $days,
				'customer_lookup'  => '',
				'resolved'         => false,
				'customer'         => null,
				'total_spend'      => 0.0,
				'order_count'      => 0,
				'orders'           => array(),
				'message'          => 'Enter a customer ID, email, or username to evaluate rolling spend.',
			);
		}

		$customer = call_user_func( $this->customer_resolver, $lookup );
		if ( ! is_array( $customer ) || (int) ( $customer['id'] ?? 0 ) <= 0 ) {
			return array(
				'window_days'      => $days,
				'customer_lookup'  => $lookup,
				'resolved'         => false,
				'customer'         => null,
				'total_spend'      => 0.0,
				'order_count'      => 0,
				'orders'           => array(),
				'message'          => 'Customer not found for the provided lookup.',
			);
		}

		$orders = call_user_func( $this->orders_loader, (int) $customer['id'], $after_iso, $statuses, $limit );
		$rows   = is_array( $orders ) ? $orders : array();

		$total_spend = 0.0;
		$normalized_rows = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$total = (float) ( $row['total'] ?? 0 );
			$total_spend += max( 0, $total );

			$normalized_rows[] = array(
				'order_id' => (int) ( $row['order_id'] ?? 0 ),
				'total'    => round( $total, 2 ),
				'date'     => (string) ( $row['date'] ?? '' ),
				'status'   => sanitize_text_field( (string) ( $row['status'] ?? '' ) ),
			);
		}

		return array(
			'window_days'      => $days,
			'customer_lookup'  => $lookup,
			'resolved'         => true,
			'customer'         => $customer,
			'total_spend'      => round( $total_spend, 2 ),
			'order_count'      => count( $normalized_rows ),
			'orders'           => $normalized_rows,
			'message'          => '',
		);
	}

	private function resolve_customer_default( string $lookup ): ?array {
		if ( '' === $lookup || ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		$user = null;
		if ( ctype_digit( $lookup ) ) {
			$user = get_user_by( 'id', (int) $lookup );
		}

		if ( ! $user && false !== strpos( $lookup, '@' ) ) {
			$user = get_user_by( 'email', $lookup );
		}

		if ( ! $user ) {
			$user = get_user_by( 'login', $lookup );
		}

		if ( ! $user || ! isset( $user->ID ) ) {
			return null;
		}

		$display_name = isset( $user->display_name ) ? sanitize_text_field( (string) $user->display_name ) : '';
		$email        = isset( $user->user_email ) ? sanitize_email( (string) $user->user_email ) : '';

		return array(
			'id'           => (int) $user->ID,
			'display_name' => $display_name,
			'email'        => $email,
		);
	}

	private function load_orders_default( int $customer_id, string $after_iso, array $statuses, int $limit ): array {
		if ( $customer_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'limit'       => $limit,
				'status'      => $statuses,
				'date_after'  => $after_iso,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$rows = array();
		foreach ( (array) $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}

			$date_value = '';
			if ( method_exists( $order, 'get_date_created' ) && $order->get_date_created() ) {
				$date_value = (string) $order->get_date_created()->date_i18n( get_option( 'date_format' ) );
			}

			$rows[] = array(
				'order_id' => (int) $order->get_id(),
				'total'    => (float) $order->get_total(),
				'date'     => $date_value,
				'status'   => (string) $order->get_status(),
			);
		}

		return $rows;
	}
}
