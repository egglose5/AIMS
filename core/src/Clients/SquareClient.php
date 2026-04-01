<?php

declare( strict_types=1 );

namespace AIMS\Core\Clients;

final class SquareClient {
	private string $baseUrl;
	private string $accessToken;
	private HttpTransportInterface $transport;

	public function __construct( string $baseUrl, string $accessToken, ?HttpTransportInterface $transport = null ) {
		$this->baseUrl     = rtrim( $baseUrl, '/' );
		$this->accessToken = trim( $accessToken );
		$this->transport   = $transport ?: new NativeHttpTransport();
	}

	public function fetchPayments( array $filters = array() ): array {
		$response = $this->request( 'GET', '/v2/payments', array( 'query' => $filters ) );
		return $this->normalizeCollection( $response, 'payments' );
	}

	public function fetchOrders( array $filters = array() ): array {
		$response = $this->request( 'GET', '/v2/orders', array( 'query' => $filters ) );
		return $this->normalizeCollection( $response, 'orders' );
	}

	public function fetchTransactionalTruth( array $filters = array() ): array {
		return array(
			'payments' => $this->fetchPayments( $filters ),
			'orders'   => $this->fetchOrders( $filters ),
		);
	}

	private function request( string $method, string $path, array $options = array() ): array {
		$options['headers'] = array_merge(
			array(
				'authorization' => 'Bearer ' . $this->accessToken,
				'square-version' => $options['square_version'] ?? '2024-12-18',
			),
			(array) ( $options['headers'] ?? array() )
		);

		return $this->transport->send( $method, $this->baseUrl . $path, $options );
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<int,array<string,mixed>>
	 */
	private function normalizeCollection( array $response, string $key ): array {
		$payload = (array) ( $response['json'] ?? array() );
		$items   = (array) ( $payload[ $key ] ?? array() );

		return array_map(
			function ( array $item ): array {
				return array(
					'id'             => (string) ( $item['id'] ?? '' ),
					'status'         => (string) ( $item['status'] ?? '' ),
					'created_at'     => (string) ( $item['created_at'] ?? '' ),
					'updated_at'     => (string) ( $item['updated_at'] ?? '' ),
					'location_id'    => (string) ( $item['location_id'] ?? '' ),
					'catalog_object' => $item['order'] ?? $item['payment'] ?? $item,
					'raw'            => $item,
				);
			},
			$items
		);
	}
}
