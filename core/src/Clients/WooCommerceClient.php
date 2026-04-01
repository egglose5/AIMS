<?php

declare( strict_types=1 );

namespace AIMS\Core\Clients;

final class WooCommerceClient {
	private string $baseUrl;
	private string $consumerKey;
	private string $consumerSecret;
	private HttpTransportInterface $transport;

	public function __construct( string $baseUrl, string $consumerKey, string $consumerSecret, ?HttpTransportInterface $transport = null ) {
		$this->baseUrl         = rtrim( $baseUrl, '/' );
		$this->consumerKey     = trim( $consumerKey );
		$this->consumerSecret  = trim( $consumerSecret );
		$this->transport       = $transport ?: new NativeHttpTransport();
	}

	public function fetchProducts( array $filters = array() ): array {
		$response = $this->request( 'GET', '/wp-json/wc/v3/products', array( 'query' => $filters ) );
		return $this->normalizeCollection( $response );
	}

	public function fetchCatalogTruth( array $filters = array() ): array {
		return array(
			'products' => $this->fetchProducts( $filters ),
		);
	}

	private function request( string $method, string $path, array $options = array() ): array {
		$auth = base64_encode( $this->consumerKey . ':' . $this->consumerSecret );
		$options['headers'] = array_merge(
			array(
				'authorization' => 'Basic ' . $auth,
				'accept'        => 'application/json',
			),
			(array) ( $options['headers'] ?? array() )
		);

		return $this->transport->send( $method, $this->baseUrl . $path, $options );
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<int,array<string,mixed>>
	 */
	private function normalizeCollection( array $response ): array {
		$payload = (array) ( $response['json'] ?? array() );

		return array_map(
			function ( array $item ): array {
				return array(
					'id'            => (int) ( $item['id'] ?? 0 ),
					'sku'           => (string) ( $item['sku'] ?? '' ),
					'name'          => (string) ( $item['name'] ?? '' ),
					'slug'          => (string) ( $item['slug'] ?? '' ),
					'price'         => (string) ( $item['price'] ?? '' ),
					'regular_price' => (string) ( $item['regular_price'] ?? '' ),
					'sale_price'    => (string) ( $item['sale_price'] ?? '' ),
					'stock_quantity'=> isset( $item['stock_quantity'] ) ? (float) $item['stock_quantity'] : null,
					'stock_status'  => (string) ( $item['stock_status'] ?? '' ),
					'catalog_visibility' => (string) ( $item['catalog_visibility'] ?? '' ),
					'raw'           => $item,
				);
			},
			$payload
		);
	}
}
