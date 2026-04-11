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

	public function createLocation( array $payload ): array {
		$location = isset( $payload['location'] ) && is_array( $payload['location'] )
			? (array) $payload['location']
			: $payload;

		$response = $this->request(
			'POST',
			'/v2/locations',
			array(
				'body' => array(
					'idempotency_key' => (string) ( $payload['idempotency_key'] ?? $this->idempotencyKey() ),
					'location'        => $location,
				),
			)
		);

		$location = (array) ( $response['json']['location'] ?? array() );

		return array(
			'id'            => (string) ( $location['id'] ?? '' ),
			'name'          => (string) ( $location['name'] ?? '' ),
			'status'        => (string) ( $location['status'] ?? '' ),
			'country'       => (string) ( $location['country'] ?? '' ),
			'capabilities'  => (array) ( $location['capabilities'] ?? array() ),
			'raw'           => $location,
			'success'       => (bool) ( $response['success'] ?? false ),
			'response_json' => $response['json'] ?? null,
		);
	}

	public function createTeamMember( array $payload ): array {
		$teamMember = isset( $payload['team_member'] ) && is_array( $payload['team_member'] )
			? (array) $payload['team_member']
			: $payload;

		$body = array(
			'idempotency_key' => (string) ( $payload['idempotency_key'] ?? $this->idempotencyKey() ),
			'team_member'     => $teamMember,
		);

		if ( isset( $payload['assigned_locations'] ) && is_array( $payload['assigned_locations'] ) ) {
			$body['assigned_locations'] = $payload['assigned_locations'];
		}

		$response   = $this->request( 'POST', '/v2/team-members', array( 'body' => $body ) );
		$teamMember = (array) ( $response['json']['team_member'] ?? array() );

		return array(
			'id'              => (string) ( $teamMember['id'] ?? '' ),
			'display_name'    => (string) ( $teamMember['display_name'] ?? '' ),
			'given_name'      => (string) ( $teamMember['given_name'] ?? '' ),
			'family_name'     => (string) ( $teamMember['family_name'] ?? '' ),
			'email_address'   => (string) ( $teamMember['email_address'] ?? '' ),
			'phone_number'    => (string) ( $teamMember['phone_number'] ?? '' ),
			'reference_id'    => (string) ( $teamMember['reference_id'] ?? '' ),
			'status'          => (string) ( $teamMember['status'] ?? '' ),
			'assigned_locations' => (array) ( $body['assigned_locations'] ?? array() ),
			'raw'             => $teamMember,
			'success'         => (bool) ( $response['success'] ?? false ),
			'response_json'   => $response['json'] ?? null,
		);
	}

	public function fetchInventoryCounts( array $filters = array() ): array {
		$locationIds = array_values(
			array_filter(
				array_map(
					static fn( $value ): string => trim( (string) $value ),
					(array) ( $filters['location_ids'] ?? array() )
				)
			)
		);

		$body = array(
			'location_ids' => $locationIds,
			'states'       => (array) ( $filters['states'] ?? array( 'IN_STOCK' ) ),
		);

		if ( ! empty( $filters['catalog_object_ids'] ) ) {
			$body['catalog_object_ids'] = array_values( (array) $filters['catalog_object_ids'] );
		}

		if ( ! empty( $filters['updated_after'] ) ) {
			$body['updated_after'] = (string) $filters['updated_after'];
		}

		if ( ! empty( $filters['cursor'] ) ) {
			$body['cursor'] = (string) $filters['cursor'];
		}

		$response = $this->request( 'POST', '/v2/inventory/counts/batch-retrieve', array( 'body' => $body ) );
		$counts   = (array) ( $response['json']['counts'] ?? array() );

		return array_map(
			static function ( array $count ): array {
				return array(
					'catalog_object_id' => (string) ( $count['catalog_object_id'] ?? '' ),
					'location_id'       => (string) ( $count['location_id'] ?? '' ),
					'state'             => (string) ( $count['state'] ?? '' ),
					'quantity'          => (string) ( $count['quantity'] ?? '' ),
					'calculated_at'     => (string) ( $count['calculated_at'] ?? '' ),
					'raw'               => $count,
				);
			},
			$counts
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

	private function idempotencyKey(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}
