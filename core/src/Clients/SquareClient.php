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
		$locationIds = array_values(
			array_filter(
				array_map(
					static fn( $value ): string => trim( (string) $value ),
					(array) ( $filters['location_ids'] ?? array() )
				)
			)
		);
		$limit      = max( 1, min( 1000, (int) ( $filters['limit'] ?? 100 ) ) );
		$cursor     = trim( (string) ( $filters['cursor'] ?? '' ) );
		$startAt    = trim( (string) ( $filters['begin_time'] ?? $filters['updated_at_begin_time'] ?? $filters['start_at'] ?? '' ) );
		$endAt      = trim( (string) ( $filters['end_time'] ?? $filters['updated_at_end_time'] ?? $filters['end_at'] ?? '' ) );
		$sortField  = trim( (string) ( $filters['sort_field'] ?? 'UPDATED_AT' ) );
		$sortOrder  = trim( (string) ( $filters['sort_order'] ?? 'ASC' ) );

		$body = array(
			'limit' => $limit,
			'sort'  => array(
				'sort_field' => '' !== $sortField ? $sortField : 'UPDATED_AT',
				'sort_order' => '' !== $sortOrder ? $sortOrder : 'ASC',
			),
		);

		if ( ! empty( $locationIds ) ) {
			$body['location_ids'] = $locationIds;
		}

		$dateTimeFilter = array();
		if ( '' !== $startAt ) {
			$dateTimeFilter['updated_at'] = array_merge(
				(array) ( $dateTimeFilter['updated_at'] ?? array() ),
				array( 'start_at' => $startAt )
			);
		}

		if ( '' !== $endAt ) {
			$dateTimeFilter['updated_at'] = array_merge(
				(array) ( $dateTimeFilter['updated_at'] ?? array() ),
				array( 'end_at' => $endAt )
			);
		}

		if ( ! empty( $dateTimeFilter ) ) {
			$body['query'] = array(
				'filter' => array(
					'date_time_filter' => $dateTimeFilter,
				),
			);
		}

		if ( '' !== $cursor ) {
			$body['cursor'] = $cursor;
		}

		$response = $this->request( 'POST', '/v2/orders/search', array( 'body' => $body ) );
		return $this->normalizeCollection( $response, 'orders' );
	}

	public function fetchTransactionalTruth( array $filters = array() ): array {
		return array(
			'payments' => $this->fetchPayments( $filters ),
			'orders'   => $this->fetchOrders( $filters ),
		);
	}

	public function fetchOrdersWindow( array $locationIds, string $beginTime, string $endTime, int $limit = 100, string $cursor = '' ): array {
		return $this->fetchOrders(
			array(
				'location_ids' => $locationIds,
				'begin_time'   => $beginTime,
				'end_time'     => $endTime,
				'limit'        => $limit,
				'cursor'       => $cursor,
			)
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
