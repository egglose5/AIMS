<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Sync;

use AIMS\Core\Clients\NativeHttpTransport;
use AIMS\Core\Clients\SquareClient;
use AIMS\Core\Clients\WooCommerceClient;
use AIMS\Core\Sync\ManifestGenerator;
use AIMS\Core\Sync\SyncOrchestrator;
use AmesCore\Headless\CoreConfig;
use AmesCore\Headless\Storage\SqliteLedgerRepository;

final class RemoteTruthService {
	private CoreConfig $config;
	private NativeHttpTransport $transport;

	public function __construct( CoreConfig $config, ?NativeHttpTransport $transport = null ) {
		$this->config    = $config;
		$this->transport = $transport ?: new NativeHttpTransport();
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public function buildManifest( SqliteLedgerRepository $ledger, array $query = array() ): array {
		$showId             = trim( (string) ( $query['show_id'] ?? '' ) );
		$catalogTruth       = $this->fetchCatalogTruth();
		$transactionalTruth = $this->fetchTransactionalTruth();
		$positionalTruth    = array( 'ledger' => $ledger->inventorySummary( $showId ) );

		if ( $this->config->hasWooCredentials() && $this->config->hasSquareCredentials() ) {
			$generator = new ManifestGenerator( $this->wooClient(), $this->squareClient() );
			$manifest  = ( new SyncOrchestrator( $generator ) )->buildSingleClickManifest(
				array(
					'show_id'             => '' !== $showId ? $showId : null,
					'catalog_truth'       => $catalogTruth,
					'transactional_truth' => $transactionalTruth,
					'positional_truth'    => $positionalTruth,
				)
			);
		} else {
			$manifest = array(
				'manifest_id'         => 'manifest-' . gmdate( 'YmdHis' ),
				'generated_at'        => gmdate( 'c' ),
				'catalog_truth'       => $catalogTruth,
				'transactional_truth' => $transactionalTruth,
				'positional_truth'    => $positionalTruth,
				'resolved_truth'      => array(
					'catalog'      => $catalogTruth['products'] ?? array(),
					'transactions' => $transactionalTruth['orders'] ?? array(),
					'ledger'       => $positionalTruth['ledger'],
				),
				'sync_mode'           => 'single_click',
				'consistency_model'   => 'all_or_nothing_manifest',
			);
		}

		$manifest['manifest_uuid'] = (string) ( $manifest['manifest_id'] ?? ( 'manifest-' . gmdate( 'YmdHis' ) ) );
		$manifest['summary']       = array(
			'merged_items'      => count( (array) ( $manifest['resolved_truth']['catalog'] ?? array() ) ),
			'transaction_count' => count( (array) ( $manifest['resolved_truth']['transactions'] ?? array() ) ),
			'reconciliation'    => $ledger->reconcileAgainstCatalog( (array) ( $catalogTruth['products'] ?? array() ), $showId ),
		);

		return $manifest;
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	public function pushManifest( array $manifest ): array {
		$catalogRows   = (array) ( $manifest['resolved_truth']['catalog'] ?? $manifest['items'] ?? array() );
		$wooResults    = array();
		$squareResults = array();

		foreach ( $catalogRows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$sku      = trim( (string) ( $row['sku'] ?? '' ) );
			$quantity = isset( $row['stock_quantity'] ) ? (float) $row['stock_quantity'] : (float) ( $row['quantity'] ?? 0 );

			if ( '' !== $sku && $this->config->hasWooCredentials() ) {
				$wooResults[] = $this->pushWooStock( $row, $quantity );
			}

			if ( '' !== $sku && $this->config->hasSquareCredentials() ) {
				$squareResults[] = $this->pushSquareStock( $sku, $quantity, (string) ( $manifest['manifest_uuid'] ?? $manifest['manifest_id'] ?? '' ) );
			}
		}

		return array(
			'woo'    => $wooResults,
			'square' => $squareResults,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchCatalogTruth(): array {
		if ( ! $this->config->hasWooCredentials() ) {
			return array( 'products' => array() );
		}

		return $this->wooClient()->fetchCatalogTruth( array( 'per_page' => 100 ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchTransactionalTruth(): array {
		if ( ! $this->config->hasSquareCredentials() ) {
			return array(
				'payments' => array(),
				'orders'   => array(),
			);
		}

		return $this->squareClient()->fetchTransactionalTruth();
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function pushWooStock( array $row, float $quantity ): array {
		$productId = isset( $row['id'] ) ? (int) $row['id'] : 0;

		if ( $productId <= 0 ) {
			return array(
				'sku'     => (string) ( $row['sku'] ?? '' ),
				'status'  => 'skipped',
				'message' => 'Missing Woo product ID.',
			);
		}

		$response = $this->transport->send(
			'PUT',
			$this->config->wooUrl() . '/wp-json/wc/v3/products/' . $productId,
			array(
				'headers' => array(
					'authorization' => 'Basic ' . base64_encode( $this->config->wooConsumerKey() . ':' . $this->config->wooConsumerSecret() ),
					'content-type'  => 'application/json',
					'accept'        => 'application/json',
				),
				'body'    => array(
					'manage_stock'   => true,
					'stock_quantity' => $quantity,
				),
			)
		);

		return array(
			'sku'       => (string) ( $row['sku'] ?? '' ),
			'product_id'=> $productId,
			'quantity'  => $quantity,
			'success'   => (bool) ( $response['success'] ?? false ),
			'status'    => (int) ( $response['status'] ?? 0 ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pushSquareStock( string $sku, float $quantity, string $referenceId ): array {
		$variationId = $this->findSquareVariationIdBySku( $sku );

		if ( '' === $variationId ) {
			return array(
				'sku'     => $sku,
				'status'  => 'skipped',
				'message' => 'Square catalog variation not found for SKU.',
			);
		}

		$response = $this->transport->send(
			'POST',
			$this->config->squareUrl() . '/v2/inventory/changes/batch-create',
			array(
				'headers' => array(
					'authorization'  => 'Bearer ' . $this->config->squareToken(),
					'square-version' => $this->config->squareVersion(),
					'content-type'   => 'application/json',
					'accept'         => 'application/json',
				),
				'body'    => array(
					'idempotency_key'         => $this->uuid(),
					'ignore_unchanged_counts' => true,
					'changes'                 => array(
						array(
							'type'           => 'PHYSICAL_COUNT',
							'physical_count' => array(
								'reference_id'      => '' !== $referenceId ? $referenceId : $this->uuid(),
								'catalog_object_id' => $variationId,
								'state'             => 'IN_STOCK',
								'location_id'       => $this->config->squareLocationId(),
								'quantity'          => $this->stringifyQuantity( $quantity ),
								'occurred_at'       => gmdate( 'c' ),
							),
						),
					),
				),
			)
		);

		return array(
			'sku'          => $sku,
			'variation_id' => $variationId,
			'quantity'     => $quantity,
			'success'      => (bool) ( $response['success'] ?? false ),
			'status'       => (int) ( $response['status'] ?? 0 ),
		);
	}

	private function findSquareVariationIdBySku( string $sku ): string {
		if ( '' === $this->config->squareLocationId() ) {
			return '';
		}

		$response = $this->transport->send(
			'POST',
			$this->config->squareUrl() . '/v2/catalog/search-catalog-items',
			array(
				'headers' => array(
					'authorization'  => 'Bearer ' . $this->config->squareToken(),
					'square-version' => $this->config->squareVersion(),
					'content-type'   => 'application/json',
					'accept'         => 'application/json',
				),
				'body'    => array(
					'text_filter'          => $sku,
					'enabled_location_ids' => array( $this->config->squareLocationId() ),
				),
			)
		);

		foreach ( (array) ( $response['json']['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			foreach ( (array) ( $item['item_data']['variations'] ?? array() ) as $variation ) {
				$variationData = (array) ( $variation['item_variation_data'] ?? array() );
				if ( $sku === (string) ( $variationData['sku'] ?? '' ) ) {
					return (string) ( $variation['id'] ?? '' );
				}
			}
		}

		return '';
	}

	private function wooClient(): WooCommerceClient {
		return new WooCommerceClient(
			$this->config->wooUrl(),
			$this->config->wooConsumerKey(),
			$this->config->wooConsumerSecret(),
			$this->transport
		);
	}

	private function squareClient(): SquareClient {
		return new SquareClient(
			$this->config->squareUrl(),
			$this->config->squareToken(),
			$this->transport
		);
	}

	private function stringifyQuantity( float $quantity ): string {
		$formatted = number_format( $quantity, 4, '.', '' );
		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	private function uuid(): string {
		$bytes = random_bytes( 16 );
		$hex   = bin2hex( $bytes );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
