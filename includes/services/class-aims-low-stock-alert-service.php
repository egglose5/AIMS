<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Low_Stock_Alert_Service {
	private $position_repository;
	private $threshold;

	public function __construct( AIMS_Bucket_Inventory_Position_Repository $position_repository = null, ?int $threshold = null ) {
		$this->position_repository = $position_repository ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->threshold           = null === $threshold ? AIMS_Plugin::get_low_stock_threshold() : max( 0, $threshold );
	}

	public function get_dashboard_snapshot( int $limit = 25 ): array {
		$rows = is_object( $this->position_repository ) && method_exists( $this->position_repository, 'get_all_positions' )
			? (array) $this->position_repository->get_all_positions()
			: array();

		$aggregate = array();
		$active_position_count = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( 'active' !== sanitize_key( (string) ( $row['position_status'] ?? 'active' ) ) ) {
				continue;
			}

			$product_id = (int) ( $row['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}

			++$active_position_count;
			$quantity          = (float) ( $row['quantity'] ?? 0 );
			$reserved_quantity = (float) ( $row['reserved_quantity'] ?? 0 );
			$bucket_id         = (int) ( $row['bucket_id'] ?? 0 );
			$vendor_id         = (int) ( $row['vendor_id'] ?? 0 );

			if ( ! isset( $aggregate[ $product_id ] ) ) {
				$aggregate[ $product_id ] = array(
					'product_id'         => $product_id,
					'product_name'       => $this->resolve_product_name( $product_id ),
					'total_quantity'     => 0.0,
					'reserved_quantity'  => 0.0,
					'available_quantity' => 0.0,
					'bucket_ids'         => array(),
					'vendor_ids'         => array(),
				);
			}

			$aggregate[ $product_id ]['total_quantity'] += $quantity;
			$aggregate[ $product_id ]['reserved_quantity'] += $reserved_quantity;
			$aggregate[ $product_id ]['available_quantity'] =
				$aggregate[ $product_id ]['total_quantity'] - $aggregate[ $product_id ]['reserved_quantity'];

			if ( $bucket_id > 0 ) {
				$aggregate[ $product_id ]['bucket_ids'][ $bucket_id ] = true;
			}
			if ( $vendor_id > 0 ) {
				$aggregate[ $product_id ]['vendor_ids'][ $vendor_id ] = true;
			}
		}

		$alerts = array();
		foreach ( $aggregate as $product_id => $item ) {
			if ( (float) $item['available_quantity'] > (float) $this->threshold ) {
				continue;
			}

			$alerts[] = array(
				'product_id'         => (int) $product_id,
				'product_name'       => (string) ( $item['product_name'] ?? ( 'Product #' . $product_id ) ),
				'total_quantity'     => (float) $item['total_quantity'],
				'reserved_quantity'  => (float) $item['reserved_quantity'],
				'available_quantity' => (float) $item['available_quantity'],
				'bucket_count'       => count( (array) ( $item['bucket_ids'] ?? array() ) ),
				'vendor_count'       => count( (array) ( $item['vendor_ids'] ?? array() ) ),
				'status'             => (float) $item['available_quantity'] <= 0 ? 'out' : 'low',
			);
		}

		usort(
			$alerts,
			static function ( array $a, array $b ): int {
				$cmp = ( $a['available_quantity'] <=> $b['available_quantity'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}

				return strcmp( (string) $a['product_name'], (string) $b['product_name'] );
			}
		);

		$total_low_stock_products = count( $alerts );

		if ( $limit > 0 ) {
			$alerts = array_slice( $alerts, 0, $limit );
		}

		return array(
			'threshold'          => (int) $this->threshold,
			'active_positions'   => $active_position_count,
			'tracked_products'   => count( $aggregate ),
			'low_stock_products' => $total_low_stock_products,
			'alerts'             => $alerts,
		);
	}

	private function resolve_product_name( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return 'Unknown Product';
		}

		if ( function_exists( 'get_the_title' ) ) {
			$title = (string) get_the_title( $product_id );
			if ( '' !== trim( $title ) ) {
				return $title;
			}
		}

		return 'Product #' . $product_id;
	}
}
