<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Customer_Demand_Data_Provider {
	private $events;
	private $requests;
	private $request_items;

	public function __construct(
		AIMS_Event_Repository $events = null,
		AIMS_Event_Customer_Request_Repository $requests = null,
		AIMS_Event_Customer_Request_Item_Repository $request_items = null
	) {
		$this->events        = $events ?: new AIMS_Event_Repository();
		$this->requests      = $requests ?: new AIMS_Event_Customer_Request_Repository();
		$this->request_items = $request_items ?: new AIMS_Event_Customer_Request_Item_Repository();
	}

	public function get_event_context(): array {
		$event = $this->get_selected_event();

		return is_array( $event ) ? $event : array();
	}

	public function get_rows(): array {
		$event = $this->get_selected_event();
		if ( empty( $event ) ) {
			return array();
		}

		$event_id    = (int) ( $event['id'] ?? 0 );
		$request_rows = $this->load_requests_for_event( $event_id );
		$summary_rows = $this->request_items->get_demand_summary_for_event( $event_id );

		if ( empty( $summary_rows ) ) {
			return array();
		}

		$customer_map = $this->build_customer_map( $request_rows );

		$rows = array();
		foreach ( $summary_rows as $summary ) {
			$product_sku = (string) ( $summary['product_sku'] ?? '' );
			$item_rows   = $this->request_items->get_for_event_by_sku( $event_id, $product_sku );
			$requested   = (float) ( $summary['total_quantity_requested'] ?? 0 );
			$fulfilled   = 0.0;
			$latest_date = '';
			$customer_ids = array();

			foreach ( $item_rows as $item_row ) {
				if ( 'fulfilled' === sanitize_key( (string) ( $item_row['item_status'] ?? '' ) ) ) {
					$fulfilled += (float) ( $item_row['quantity_requested'] ?? 0 );
				}

				$request_id = (int) ( $item_row['request_id'] ?? 0 );
				if ( $request_id > 0 ) {
					$customer_ids[ $request_id ] = true;
				}

				$item_date = (string) ( $item_row['updated_at'] ?? $item_row['created_at'] ?? '' );
				if ( '' !== $item_date && ( '' === $latest_date || strcmp( $item_date, $latest_date ) > 0 ) ) {
					$latest_date = $item_date;
				}
			}

			$reserved  = min( $requested, $fulfilled );
			$open      = max( 0, $requested - $reserved );
			$product_name = $this->resolve_product_name( $summary, $item_rows );

			$rows[] = array(
				'event_id'            => $event_id,
				'event_name'          => (string) ( $summary['event_name'] ?? $event['event_name'] ?? '' ),
				'product_sku'         => $product_sku,
				'woo_product_id'      => (int) ( $summary['woo_product_id'] ?? 0 ),
				'product_name'        => $product_name,
				'account_display'     => $this->build_account_display( $request_rows, $customer_ids ),
				'quantity_requested'  => $requested,
				'quantity_reserved'   => $reserved,
				'quantity_open'       => $open,
				'customer_display'    => $this->build_customer_display( array_keys( $customer_ids ), $customer_map ),
				'created_at'          => '' !== $latest_date ? $latest_date : current_time( 'mysql' ),
			);
		}

		return $rows;
	}

	public function get_summary_metrics(): array {
		$rows = $this->get_rows();

		$total_events = array();
		$total_skus = 0;
		$total_requested = 0.0;
		$total_open = 0.0;

		foreach ( $rows as $row ) {
			$total_events[ (int) ( $row['event_id'] ?? 0 ) ] = true;
			$total_skus++;
			$total_requested += (float) ( $row['quantity_requested'] ?? 0 );
			$total_open += (float) ( $row['quantity_open'] ?? 0 );
		}

		return array(
			'total_events'    => count( $total_events ),
			'total_skus'      => $total_skus,
			'total_requested' => $total_requested,
			'total_open'      => $total_open,
		);
	}

	private function get_selected_event(): ?array {
		$requested_event_id = isset( $_GET['event_id'] ) ? (int) wp_unslash( $_GET['event_id'] ) : 0;
		if ( $requested_event_id > 0 ) {
			$event = $this->find_event( $requested_event_id );
			if ( is_array( $event ) ) {
				return $event;
			}
		}

		foreach ( $this->get_events() as $event ) {
			$event_id = (int) ( $event['id'] ?? 0 );
			if ( $event_id <= 0 ) {
				continue;
			}

			if ( ! empty( $this->request_items->get_demand_summary_for_event( $event_id ) ) ) {
				return $event;
			}
		}

		$events = $this->get_events();
		return ! empty( $events ) ? $events[0] : null;
	}

	private function load_requests_for_event( int $event_id ): array {
		return is_object( $this->requests ) && method_exists( $this->requests, 'get_for_event' ) ? (array) $this->requests->get_for_event( $event_id ) : array();
	}

	private function build_customer_map( array $request_rows ): array {
		$map = array();

		foreach ( $request_rows as $row ) {
			$request_id = (int) ( $row['id'] ?? 0 );
			if ( $request_id <= 0 ) {
				continue;
			}

			$map[ $request_id ] = trim( (string) ( $row['customer_name'] ?? '' ) );
		}

		return $map;
	}

	private function build_customer_display( array $request_ids, array $customer_map ): string {
		$names = array();
		foreach ( $request_ids as $request_id ) {
			$request_id = (int) $request_id;
			if ( $request_id <= 0 || empty( $customer_map[ $request_id ] ) ) {
				continue;
			}

			$names[] = $customer_map[ $request_id ];
		}

		$names = array_values( array_unique( array_filter( $names ) ) );

		if ( empty( $names ) ) {
			return 'Planning data only';
		}

		return implode( ', ', array_slice( $names, 0, 3 ) );
	}

	private function build_account_display( array $request_rows, array $request_ids ): string {
		$accounts = array();

		foreach ( $request_rows as $row ) {
			$request_id = (int) ( $row['id'] ?? 0 );
			if ( $request_id <= 0 || empty( $request_ids[ $request_id ] ) ) {
				continue;
			}

			$user_id = (int) ( $row['wp_user_id'] ?? 0 );
			$label   = trim( (string) ( $row['customer_name'] ?? '' ) );

			if ( $user_id > 0 ) {
				$label = '' !== $label ? $label . ' (#' . $user_id . ')' : '#' . $user_id;
			}

			if ( '' !== $label ) {
				$accounts[] = $label;
			}
		}

		$accounts = array_values( array_unique( array_filter( $accounts ) ) );

		return empty( $accounts ) ? 'Linked account unavailable' : implode( ', ', array_slice( $accounts, 0, 3 ) );
	}

	private function resolve_product_name( array $summary, array $item_rows ): string {
		$product_name = trim( (string) ( $summary['product_name'] ?? '' ) );
		if ( '' !== $product_name ) {
			return $product_name;
		}

		foreach ( $item_rows as $item_row ) {
			$product_name = trim( (string) ( $item_row['product_name'] ?? '' ) );
			if ( '' !== $product_name ) {
				return $product_name;
			}
		}

		$woo_product_id = (int) ( $summary['woo_product_id'] ?? 0 );
		if ( $woo_product_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $woo_product_id );
			if ( $product && is_object( $product ) && ! $product->is_virtual() && ! $product->is_downloadable() ) {
				$product_name = trim( (string) $product->get_name() );
				if ( '' !== $product_name ) {
					return $product_name;
				}
			}
		}

		return trim( (string) ( $summary['product_sku'] ?? '' ) );
	}

	private function get_events(): array {
		return is_object( $this->events ) && method_exists( $this->events, 'all' ) ? (array) $this->events->all() : array();
	}

	private function find_event( int $event_id ): ?array {
		if ( ! is_object( $this->events ) ) {
			return null;
		}

		foreach ( array( 'find', 'get', 'get_event' ) as $method ) {
			if ( method_exists( $this->events, $method ) ) {
				$event = $this->events->{$method}( $event_id );
				if ( is_array( $event ) ) {
					return $event;
				}
			}
		}

		foreach ( $this->get_events() as $event ) {
			if ( (int) ( $event['id'] ?? 0 ) === $event_id ) {
				return $event;
			}
		}

		return null;
	}
}
