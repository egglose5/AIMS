<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Demand_Summary_Data_Provider {
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

		$event_id = (int) ( $event['id'] ?? 0 );
		$summary_rows = $this->request_items->get_demand_summary_for_event( $event_id );

		if ( empty( $summary_rows ) ) {
			return array();
		}

		$rows = array();
		foreach ( $summary_rows as $row ) {
			$product_sku = (string) ( $row['product_sku'] ?? '' );
			$item_rows   = $this->request_items->get_for_event_by_sku( $event_id, $product_sku );
			$fulfilled   = 0.0;
			$latest_date = '';
			$product_name = $this->resolve_product_name( $row, $item_rows );

			foreach ( $item_rows as $item_row ) {
				if ( 'fulfilled' === sanitize_key( (string) ( $item_row['item_status'] ?? '' ) ) ) {
					$fulfilled += (float) ( $item_row['quantity_requested'] ?? 0 );
				}

				$item_date = (string) ( $item_row['updated_at'] ?? $item_row['created_at'] ?? '' );
				if ( '' !== $item_date && ( '' === $latest_date || strcmp( $item_date, $latest_date ) > 0 ) ) {
					$latest_date = $item_date;
				}
			}

			$demand = (float) ( $row['total_quantity_requested'] ?? 0 );
			$fulfilled = min( $demand, $fulfilled );

			$rows[] = array(
				'event_id'          => $event_id,
				'event_name'        => (string) ( $event['event_name'] ?? '' ),
				'account_display'   => $this->build_account_display( $event_id ),
				'product_sku'       => $product_sku,
				'woo_product_id'    => (int) ( $row['woo_product_id'] ?? 0 ),
				'product_name'      => $product_name,
				'demand_quantity'   => $demand,
				'fulfilled_quantity' => $fulfilled,
				'open_quantity'     => max( 0, $demand - $fulfilled ),
				'last_updated'      => '' !== $latest_date ? $latest_date : current_time( 'mysql' ),
			);
		}

		return $rows;
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

	private function build_account_display( int $event_id ): string {
		$accounts = array();

		foreach ( (array) $this->requests->get_for_event( $event_id ) as $request_row ) {
			if ( ! is_array( $request_row ) ) {
				continue;
			}

			$label = trim( (string) ( $request_row['customer_name'] ?? '' ) );
			$user_id = (int) ( $request_row['wp_user_id'] ?? 0 );

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
}
