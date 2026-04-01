<?php

declare( strict_types=1 );

namespace AIMS\Core\Sync;

final class ConflictResolver {
	public function resolveProductData( array $wooProduct, array $squareProduct = array() ): array {
		$resolved = array_merge( $squareProduct, $wooProduct );
		$resolved['source_of_truth'] = 'wp_woo';

		return array(
			'sku'            => (string) ( $wooProduct['sku'] ?? $squareProduct['sku'] ?? '' ),
			'name'           => (string) ( $wooProduct['name'] ?? $squareProduct['name'] ?? '' ),
			'price'          => $this->normalizeMoney( $wooProduct['price'] ?? $squareProduct['price'] ?? 0 ),
			'regular_price'  => $this->normalizeMoney( $wooProduct['regular_price'] ?? $squareProduct['regular_price'] ?? 0 ),
			'sale_price'     => $this->normalizeMoney( $wooProduct['sale_price'] ?? $squareProduct['sale_price'] ?? 0 ),
			'catalog_status' => (string) ( $wooProduct['catalog_status'] ?? $squareProduct['catalog_status'] ?? 'active' ),
			'resolved'       => $resolved,
			'conflicts'      => $this->buildProductConflicts( $wooProduct, $squareProduct ),
		);
	}

	public function resolveStockLevels( array $wooProduct, array $ledgerPosition = array() ): array {
		$resolvedQuantity = isset( $ledgerPosition['quantity'] ) ? (float) $ledgerPosition['quantity'] : (float) ( $wooProduct['stock_quantity'] ?? 0 );

		return array(
			'sku'             => (string) ( $wooProduct['sku'] ?? $ledgerPosition['sku'] ?? '' ),
			'stock_quantity'  => $resolvedQuantity,
			'stock_status'    => $this->deriveStockStatus( $resolvedQuantity, (string) ( $wooProduct['stock_status'] ?? '' ) ),
			'resolved'        => array_merge( $wooProduct, array( 'stock_quantity' => $resolvedQuantity ) ),
			'conflicts'       => $this->buildStockConflicts( $wooProduct, $ledgerPosition ),
			'source_of_truth' => 'aims',
		);
	}

	public function resolveManifestTruth( array $catalogTruth, array $transactionalTruth, array $positionalTruth ): array {
		$products = array();
		$transactions = array();
		$positions = array();

		foreach ( (array) ( $catalogTruth['products'] ?? array() ) as $product ) {
			$sku = (string) ( $product['sku'] ?? '' );
			$products[ $sku ] = $product;
		}

		foreach ( (array) ( $transactionalTruth['orders'] ?? array() ) as $transaction ) {
			$transactions[] = $transaction;
		}

		foreach ( (array) ( $positionalTruth['ledger'] ?? array() ) as $row ) {
			$sku = (string) ( $row['sku'] ?? '' );
			$positions[ $sku ] = $row;
		}

		$mergedCatalog = array();
		foreach ( $products as $sku => $product ) {
			$mergedCatalog[] = array_merge(
				$product,
				$this->resolveStockLevels( $product, $positions[ $sku ] ?? array() )
			);
		}

		return array(
			'catalog'       => $mergedCatalog,
			'transactions'  => $transactions,
			'ledger'        => array_values( $positions ),
		);
	}

	private function buildProductConflicts( array $wooProduct, array $squareProduct ): array {
		$conflicts = array();

		foreach ( array( 'sku', 'name', 'price', 'regular_price', 'sale_price' ) as $field ) {
			if ( array_key_exists( $field, $wooProduct ) && array_key_exists( $field, $squareProduct ) && (string) $wooProduct[ $field ] !== (string) $squareProduct[ $field ] ) {
				$conflicts[] = array(
					'field'   => $field,
					'winner'  => 'woo',
					'woo'     => $wooProduct[ $field ],
					'square'  => $squareProduct[ $field ],
				);
			}
		}

		return $conflicts;
	}

	private function buildStockConflicts( array $wooProduct, array $ledgerPosition ): array {
		if ( ! array_key_exists( 'stock_quantity', $wooProduct ) && ! array_key_exists( 'quantity', $ledgerPosition ) ) {
			return array();
		}

		$wooQuantity    = isset( $wooProduct['stock_quantity'] ) ? (float) $wooProduct['stock_quantity'] : 0.0;
		$ledgerQuantity = isset( $ledgerPosition['quantity'] ) ? (float) $ledgerPosition['quantity'] : $wooQuantity;

		if ( abs( $wooQuantity - $ledgerQuantity ) < 0.0001 ) {
			return array();
		}

		return array(
			array(
				'field'  => 'stock_quantity',
				'winner' => 'aims',
				'woo'    => $wooQuantity,
				'aims'   => $ledgerQuantity,
			),
		);
	}

	private function normalizeMoney( $value ): string {
		return number_format( (float) $value, 2, '.', '' );
	}

	private function deriveStockStatus( float $quantity, string $fallback ): string {
		if ( $quantity <= 0 ) {
			return 'outofstock';
		}

		return '' !== $fallback ? $fallback : 'instock';
	}
}
