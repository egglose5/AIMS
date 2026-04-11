<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Bucket_Sourcing_Service {
	private $bucket_repository;
	private $endpoint_directory;
	private $vendor_service;
	private $headless_client;
	private $vendor_inventory_cache = array();

	public function __construct(
		AIMS_Physical_Bucket_Repository $bucket_repository = null,
		AIMS_Inventory_Endpoint_Directory_Service $endpoint_directory = null,
		AIMS_Vendor_Service $vendor_service = null,
		AIMS_Headless_Api_Client $headless_client = null
	) {
		$this->bucket_repository = $bucket_repository ?: new AIMS_Physical_Bucket_Repository();
		$this->endpoint_directory = $endpoint_directory ?: new AIMS_Inventory_Endpoint_Directory_Service();
		$this->vendor_service     = $vendor_service ?: ( class_exists( 'AIMS_Vendor_Service' ) ? new AIMS_Vendor_Service() : null );
		$this->headless_client    = $headless_client ?: ( class_exists( 'AIMS_Headless_Api_Client' ) ? AIMS_Headless_Api_Client::from_plugin_options() : null );
	}

	public function get_source_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		return $this->get_buckets_for_endpoint( $node_id, $node_type, 'source', $context );
	}

	public function get_target_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		return $this->get_buckets_for_endpoint( $node_id, $node_type, 'target', $context );
	}

	public function get_bucket_sourcing_context( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		$normalized_context = $this->normalize_context( $context );
		$source_endpoint    = $this->endpoint_directory->resolve_endpoint_from_node( $node_id, $node_type );
		$target_endpoint    = $this->resolve_target_endpoint( $normalized_context, $source_endpoint );
		$inventory_context  = $this->resolve_endpoint_inventory_context( $node_id, $node_type, $source_endpoint, $normalized_context );

		return array_merge(
			array(
				'node_id'           => max( 0, $node_id ),
				'node_type'         => sanitize_key( $node_type ),
				'source_endpoint'   => $source_endpoint,
				'target_endpoint'   => $target_endpoint,
				'route_suggestions' => $this->get_route_suggestions( $source_endpoint, $target_endpoint ),
				'source_buckets'    => $this->get_source_buckets( $node_id, $node_type, $normalized_context ),
				'target_buckets'    => $this->get_target_buckets( $node_id, $node_type, $normalized_context ),
			),
			$inventory_context
		);
	}

	public function get_route_suggestions( array $source_endpoint, array $target_endpoint = array() ): array {
		$source_key = sanitize_key( (string) ( $source_endpoint['endpoint_key'] ?? '' ) );
		$target_key = sanitize_key( (string) ( $target_endpoint['endpoint_key'] ?? '' ) );

		if ( '' === $source_key ) {
			return array();
		}

		$suggestions = array();
		$candidates  = (array) ( $source_endpoint['suggested_targets'] ?? array() );
		if ( '' !== $target_key ) {
			$candidates[] = $target_key;
		}

		$candidates = array_values( array_unique( array_filter( array_map( 'sanitize_key', $candidates ) ) ) );
		foreach ( $candidates as $candidate_key ) {
			if ( $candidate_key === $source_key ) {
				continue;
			}

			$endpoint = $this->endpoint_directory->get_endpoint( $candidate_key );
			if ( ! is_array( $endpoint ) ) {
				continue;
			}

			$suggestions[] = array(
				'source_endpoint_key' => $source_key,
				'source_label'        => (string) ( $source_endpoint['endpoint_label'] ?? $source_key ),
				'target_endpoint_key' => $candidate_key,
				'target_label'        => (string) ( $endpoint['endpoint_label'] ?? $candidate_key ),
				'label'               => sprintf(
					'%s -> %s',
					(string) ( $source_endpoint['endpoint_label'] ?? $source_key ),
					(string) ( $endpoint['endpoint_label'] ?? $candidate_key )
				),
			);
		}

		return $suggestions;
	}

	private function get_buckets_for_endpoint( int $node_id, string $node_type, string $direction, array $context ): array {
		$direction      = 'target' === sanitize_key( $direction ) ? 'target' : 'source';
		$endpoint       = $this->endpoint_directory->resolve_endpoint_from_node( $node_id, $node_type );
		$endpoint_key   = sanitize_key( (string) ( $endpoint['endpoint_type'] ?? $endpoint['endpoint_key'] ?? '' ) );
		$bucket_context = $this->normalize_context( $context );
		$inventory_context = $this->get_default_inventory_context();

		if ( 'supervisor' === $endpoint_key && empty( $bucket_context['vendor_ids'] ) && empty( $bucket_context['vendor_id'] ) ) {
			$endpoint_vendor_ids = array_values( array_filter( array_map( 'intval', (array) ( $endpoint['vendor_ids'] ?? array() ) ) ) );
			if ( ! empty( $endpoint_vendor_ids ) ) {
				$bucket_context['vendor_ids'] = $endpoint_vendor_ids;
			} elseif ( $node_id > 0 ) {
				$bucket_context['vendor_id'] = $node_id;
			} else {
				return array();
			}
		}

		$bucket_context = array_merge(
			$bucket_context,
			array(
				'status'                 => $this->normalize_status_filter( $endpoint['bucket_statuses'] ?? array(), $direction ),
				'current_location_types' => (array) ( $endpoint['current_location_types'] ?? array() ),
			)
		);

		$records = array();

		if ( 'vendor' === $endpoint_key ) {
			$vendor_id = $this->resolve_vendor_id_for_context( $node_id, $node_type, $bucket_context );
			if ( $vendor_id <= 0 ) {
				return array();
			}

			$inventory_context = $this->get_vendor_inventory_context( $vendor_id );
			$records           = $this->bucket_repository->get_for_vendor( $vendor_id );
			$records           = $this->filter_bucket_records( $records, $bucket_context, $endpoint, $direction );
		} elseif ( 'supervisor' === $endpoint_key && ! empty( $bucket_context['vendor_ids'] ) ) {
			$records = $this->bucket_repository->get_for_endpoint( $endpoint_key, $bucket_context );
		} else {
			$records = $this->bucket_repository->get_for_endpoint( $endpoint_key, $bucket_context );
		}

		$records = $this->apply_inventory_context_to_records( $records, $inventory_context );

		return $this->decorate_records( $records, $endpoint, $direction );
	}

	private function resolve_target_endpoint( array $context, array $source_endpoint ): array {
		$preferred_key = sanitize_key( (string) ( $context['target_endpoint_key'] ?? '' ) );
		if ( '' !== $preferred_key ) {
			$endpoint = $this->endpoint_directory->get_endpoint( $preferred_key );
			if ( is_array( $endpoint ) ) {
				return $endpoint;
			}
		}

		$source_key = sanitize_key( (string) ( $source_endpoint['endpoint_key'] ?? '' ) );
		$target_key = (string) ( $source_endpoint['suggested_targets'][0] ?? '' );
		if ( '' !== $target_key && $target_key !== $source_key ) {
			$endpoint = $this->endpoint_directory->get_endpoint( $target_key );
			if ( is_array( $endpoint ) ) {
				return $endpoint;
			}
		}

		return $source_endpoint;
	}

	private function normalize_context( array $context ): array {
		$normalized = array();

		if ( ! empty( $context['vendor_id'] ) ) {
			$normalized['vendor_id'] = (int) $context['vendor_id'];
		}

		if ( ! empty( $context['vendor_ids'] ) && is_array( $context['vendor_ids'] ) ) {
			$normalized['vendor_ids'] = array_values( array_filter( array_map( 'intval', $context['vendor_ids'] ) ) );
		}

		if ( ! empty( $context['bucket_ids'] ) && is_array( $context['bucket_ids'] ) ) {
			$normalized['bucket_ids'] = array_values( array_filter( array_map( 'intval', $context['bucket_ids'] ) ) );
		}

		if ( ! empty( $context['limit'] ) ) {
			$normalized['limit'] = max( 1, (int) $context['limit'] );
		}

		if ( ! empty( $context['offset'] ) ) {
			$normalized['offset'] = max( 0, (int) $context['offset'] );
		}

		if ( ! empty( $context['search'] ) ) {
			$normalized['search'] = sanitize_text_field( (string) $context['search'] );
		}

		return $normalized;
	}

	private function normalize_status_filter( array $statuses, string $direction ): array {
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );

		if ( 'target' === $direction ) {
			$statuses = array_values( array_diff( $statuses, array( 'in_transit' ) ) );
		}

		return ! empty( $statuses ) ? $statuses : array( 'available' );
	}

	private function resolve_vendor_id_for_context( int $node_id, string $node_type, array $context ): int {
		if ( ! empty( $context['vendor_id'] ) ) {
			return (int) $context['vendor_id'];
		}

		if ( ! empty( $context['vendor_ids'][0] ) ) {
			return (int) $context['vendor_ids'][0];
		}

		if ( 'vendor' === sanitize_key( $node_type ) && $node_id > 0 ) {
			return $node_id;
		}

		return 0;
	}

	private function filter_bucket_records( array $records, array $context, array $endpoint, string $direction ): array {
		$filtered = array();
		$statuses = $this->normalize_status_filter( $endpoint['bucket_statuses'] ?? array(), $direction );
		$allowed_location_types = (array) ( $endpoint['current_location_types'] ?? array() );

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$status = sanitize_key( (string) ( $record['status'] ?? '' ) );
			if ( ! empty( $statuses ) && ! in_array( $status, $statuses, true ) ) {
				continue;
			}

			if ( ! empty( $allowed_location_types ) ) {
				$current_location_type = sanitize_key( (string) ( $record['current_location_type'] ?? '' ) );
				$home_location_type    = sanitize_key( (string) ( $record['home_location_type'] ?? '' ) );

				if ( ! in_array( $current_location_type, $allowed_location_types, true ) && ! in_array( $home_location_type, $allowed_location_types, true ) ) {
					continue;
				}
			}

			if ( ! empty( $context['vendor_id'] ) && (int) ( $record['vendor_id'] ?? 0 ) !== (int) $context['vendor_id'] ) {
				continue;
			}

			$filtered[] = $record;
		}

		return $filtered;
	}

	private function decorate_records( array $records, array $endpoint, string $direction ): array {
		$decorated = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$decorated[] = array_merge(
				$record,
				array(
					'endpoint_key'       => (string) ( $endpoint['endpoint_key'] ?? '' ),
					'endpoint_label'     => (string) ( $endpoint['endpoint_label'] ?? '' ),
					'sourcing_direction' => sanitize_key( $direction ),
				)
			);
		}

		return $decorated;
	}

	private function resolve_endpoint_inventory_context( int $node_id, string $node_type, array $endpoint, array $context ): array {
		$endpoint_key = sanitize_key( (string) ( $endpoint['endpoint_type'] ?? $endpoint['endpoint_key'] ?? '' ) );
		if ( 'vendor' !== $endpoint_key ) {
			return $this->get_default_inventory_context();
		}

		$vendor_id = $this->resolve_vendor_id_for_context( $node_id, $node_type, $context );
		if ( $vendor_id <= 0 ) {
			return $this->get_default_inventory_context();
		}

		return $this->get_vendor_inventory_context( $vendor_id );
	}

	private function get_vendor_inventory_context( int $vendor_id ): array {
		$vendor_id = max( 0, $vendor_id );
		if ( $vendor_id <= 0 ) {
			return $this->get_default_inventory_context();
		}

		if ( isset( $this->vendor_inventory_cache[ $vendor_id ] ) && is_array( $this->vendor_inventory_cache[ $vendor_id ] ) ) {
			return $this->vendor_inventory_cache[ $vendor_id ];
		}

		$context = array_merge(
			$this->get_default_inventory_context(),
			array(
				'vendor_id' => $vendor_id,
			)
		);

		if ( ! is_object( $this->vendor_service ) || ! method_exists( $this->vendor_service, 'get_vendor' ) ) {
			$this->vendor_inventory_cache[ $vendor_id ] = $context;
			return $context;
		}

		$vendor = $this->vendor_service->get_vendor( $vendor_id );
		if ( ! is_array( $vendor ) || empty( $vendor ) ) {
			$this->vendor_inventory_cache[ $vendor_id ] = $context;
			return $context;
		}

		$square_location_id = sanitize_text_field( (string) ( $vendor['square_location_id'] ?? '' ) );
		$context['square_location_id'] = $square_location_id;

		if ( '' === $square_location_id || ! is_object( $this->headless_client ) || ! method_exists( $this->headless_client, 'get_square_holdings' ) ) {
			$this->vendor_inventory_cache[ $vendor_id ] = $context;
			return $context;
		}

		$response = $this->headless_client->get_square_holdings(
			array(
				'location_ids' => array( $square_location_id ),
			)
		);

		$context['square_holdings']       = $this->normalize_square_holdings( $this->extract_square_counts( $response ), $square_location_id );
		$context['square_holdings_count'] = count( $context['square_holdings'] );
		$context['inventory_source']      = ! empty( $response['success'] ) ? 'square' : 'local';
		$context['square_sync_success']   = ! empty( $response['success'] );
		$context['square_sync_message']   = sanitize_text_field( (string) ( $response['message'] ?? '' ) );

		$this->vendor_inventory_cache[ $vendor_id ] = $context;

		return $context;
	}

	private function get_default_inventory_context(): array {
		return array(
			'inventory_source'    => 'local',
			'square_location_id'  => '',
			'square_holdings'     => array(),
			'square_holdings_count' => 0,
			'square_sync_success' => false,
			'square_sync_message' => '',
		);
	}

	private function apply_inventory_context_to_records( array $records, array $inventory_context ): array {
		if ( empty( $records ) || empty( $inventory_context ) ) {
			return $records;
		}

		$metadata = array(
			'inventory_source'     => sanitize_key( (string) ( $inventory_context['inventory_source'] ?? 'local' ) ),
			'square_location_id'   => sanitize_text_field( (string) ( $inventory_context['square_location_id'] ?? '' ) ),
			'square_holdings'      => is_array( $inventory_context['square_holdings'] ?? null ) ? array_values( $inventory_context['square_holdings'] ) : array(),
			'square_holdings_count'=> (int) ( $inventory_context['square_holdings_count'] ?? 0 ),
			'square_sync_success'  => ! empty( $inventory_context['square_sync_success'] ),
			'square_sync_message'  => sanitize_text_field( (string) ( $inventory_context['square_sync_message'] ?? '' ) ),
		);

		return array_map(
			static function ( $record ) use ( $metadata ) {
				if ( ! is_array( $record ) ) {
					return $record;
				}

				return array_merge( $record, $metadata );
			},
			$records
		);
	}

	private function extract_square_counts( array $response ): array {
		if ( is_array( $response['counts'] ?? null ) ) {
			return (array) $response['counts'];
		}

		if ( is_array( $response['json']['counts'] ?? null ) ) {
			return (array) $response['json']['counts'];
		}

		return array();
	}

	private function normalize_square_holdings( array $counts, string $square_location_id ): array {
		$summary = array();
		$square_location_id = sanitize_text_field( $square_location_id );

		foreach ( $counts as $count ) {
			if ( ! is_array( $count ) ) {
				continue;
			}

			$location_id = sanitize_text_field( (string) ( $count['location_id'] ?? '' ) );
			if ( '' !== $square_location_id && '' !== $location_id && $location_id !== $square_location_id ) {
				continue;
			}

			$catalog_object_id = sanitize_text_field( (string) ( $count['catalog_object_id'] ?? '' ) );
			$state             = sanitize_text_field( (string) ( $count['state'] ?? 'IN_STOCK' ) );
			$key               = $catalog_object_id . '|' . $state;

			if ( ! isset( $summary[ $key ] ) ) {
				$summary[ $key ] = array(
					'catalog_object_id' => $catalog_object_id,
					'location_id'       => '' !== $location_id ? $location_id : $square_location_id,
					'state'             => $state,
					'quantity'          => 0.0,
				);
			}

			$summary[ $key ]['quantity'] += (float) ( $count['quantity'] ?? 0 );
		}

		return array_values( $summary );
	}
}
