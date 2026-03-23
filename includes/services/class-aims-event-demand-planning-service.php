<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Demand_Planning_Service {
	private $request_items;

	public function __construct( $request_items = null ) {
		$this->request_items = $request_items;
	}

	public function summarize_requests( array $requests, int $event_id = 0 ): array {
		$groups = array();

		foreach ( $requests as $request ) {
			if ( ! is_array( $request ) ) {
				continue;
			}

			$row_event_id = (int) ( $request['event_id'] ?? 0 );
			if ( $event_id > 0 && $row_event_id !== $event_id ) {
				continue;
			}

			$product_sku = $this->normalize_sku( $request['product_sku'] ?? '' );
			if ( $row_event_id <= 0 || '' === $product_sku ) {
				continue;
			}

			$key = $row_event_id . ':' . $product_sku;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'event_id'            => $row_event_id,
					'event_name'          => sanitize_text_field( $request['event_name'] ?? '' ),
					'woo_product_id'      => (int) ( $request['woo_product_id'] ?? 0 ),
					'product_sku'         => $product_sku,
					'product_name'        => sanitize_text_field( $request['product_name'] ?? '' ),
					'quantity_requested'  => 0.0,
					'item_count'          => 0,
					'approved_quantity'   => 0.0,
					'approved_count'      => 0,
					'sources'             => array(),
				);
			}

			$quantity       = (float) ( $request['quantity_requested'] ?? $request['quantity'] ?? $request['total_quantity'] ?? 0 );
			$request_status = sanitize_key( $request['request_status'] ?? 'auto_accepted' );
			$item_status    = sanitize_key( $request['item_status'] ?? 'planning_signal' );
			$request_source = sanitize_key( $request['request_source'] ?? 'public_event_demand_form' );

			$groups[ $key ]['quantity_requested'] += $quantity;
			$groups[ $key ]['item_count']++;

			if ( $this->is_accepted_request_status( $request_status ) && $this->is_planning_item_status( $item_status ) ) {
				$groups[ $key ]['approved_quantity'] += $quantity;
				$groups[ $key ]['approved_count']++;
			}

			if ( '' !== $request_source && ! in_array( $request_source, $groups[ $key ]['sources'], true ) ) {
				$groups[ $key ]['sources'][] = $request_source;
			}
		}

		return array_values( $groups );
	}

	public function summarize_by_event( array $requests ): array {
		$event_groups = array();

		foreach ( $this->summarize_requests( $requests ) as $summary ) {
			$event_id = (int) ( $summary['event_id'] ?? 0 );
			if ( $event_id <= 0 ) {
				continue;
			}

			if ( ! isset( $event_groups[ $event_id ] ) ) {
				$event_groups[ $event_id ] = array(
					'event_id'           => $event_id,
					'event_name'         => (string) ( $summary['event_name'] ?? '' ),
					'demand_lines'       => array(),
					'quantity_requested' => 0.0,
				);
			}

			$event_groups[ $event_id ]['demand_lines'][] = $summary;
			$event_groups[ $event_id ]['quantity_requested'] += (float) ( $summary['approved_quantity'] ?? 0 );
		}

		return array_values( $event_groups );
	}

	public function summarize_event_demand( int $event_id ): array {
		if ( $event_id <= 0 || ! is_object( $this->request_items ) ) {
			return array();
		}

		if ( method_exists( $this->request_items, 'get_demand_summary_for_event' ) ) {
			$rows = (array) $this->request_items->get_demand_summary_for_event( $event_id );

			return $this->summarize_requests( $this->map_repository_summary_rows( $rows ), $event_id );
		}

		return array();
	}

	private function map_repository_summary_rows( array $rows ): array {
		$mapped = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$mapped[] = array(
				'event_id'           => (int) ( $row['event_id'] ?? 0 ),
				'woo_product_id'     => (int) ( $row['woo_product_id'] ?? 0 ),
				'product_sku'        => sanitize_text_field( $row['product_sku'] ?? '' ),
				'product_name'       => sanitize_text_field( $row['product_name'] ?? '' ),
				'quantity_requested' => (float) ( $row['total_quantity_requested'] ?? 0 ),
				'request_status'     => 'auto_accepted',
				'item_status'        => 'planning_signal',
				'intake_status'      => 'auto_accepted',
				'demand_signal_type' => 'planning_only',
				'request_source'     => 'public_event_demand_form',
			);
		}

		return $mapped;
	}

	private function normalize_sku( $sku ): string {
		return strtoupper( trim( sanitize_text_field( (string) $sku ) ) );
	}

	private function is_accepted_request_status( string $status ): bool {
		return in_array( sanitize_key( $status ), array( 'auto_accepted', 'approved' ), true );
	}

	private function is_planning_item_status( string $status ): bool {
		$status = sanitize_key( $status );

		return ! in_array( $status, array( 'cancelled', 'fulfilled' ), true );
	}
}
