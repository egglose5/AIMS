<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Bucket_Sourcing_Service {
	private $bucket_repository;
	private $endpoint_directory;

	public function __construct(
		AIMS_Physical_Bucket_Repository $bucket_repository = null,
		AIMS_Inventory_Endpoint_Directory_Service $endpoint_directory = null
	) {
		$this->bucket_repository = $bucket_repository ?: new AIMS_Physical_Bucket_Repository();
		$this->endpoint_directory = $endpoint_directory ?: new AIMS_Inventory_Endpoint_Directory_Service();
	}

	public function get_source_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		return $this->get_buckets_for_endpoint( $node_id, $node_type, 'source', $context );
	}

	public function get_target_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		return $this->get_buckets_for_endpoint( $node_id, $node_type, 'target', $context );
	}

	public function get_bucket_sourcing_context( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		$source_endpoint = $this->endpoint_directory->resolve_endpoint_from_node( $node_id, $node_type );
		$target_endpoint = $this->resolve_target_endpoint( $context, $source_endpoint );

		return array(
			'node_id'           => max( 0, $node_id ),
			'node_type'         => sanitize_key( $node_type ),
			'source_endpoint'   => $source_endpoint,
			'target_endpoint'   => $target_endpoint,
			'route_suggestions'  => $this->get_route_suggestions( $source_endpoint, $target_endpoint ),
			'source_buckets'    => $this->get_source_buckets( $node_id, $node_type, $context ),
			'target_buckets'    => $this->get_target_buckets( $node_id, $node_type, $context ),
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
		$direction = 'target' === sanitize_key( $direction ) ? 'target' : 'source';
		$endpoint  = $this->endpoint_directory->resolve_endpoint_from_node( $node_id, $node_type );
		$endpoint_key = sanitize_key( (string) ( $endpoint['endpoint_type'] ?? $endpoint['endpoint_key'] ?? '' ) );
		$bucket_context = $this->normalize_context( $context );

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

			$records = $this->bucket_repository->get_for_vendor( $vendor_id );
			$records = $this->filter_bucket_records( $records, $bucket_context, $endpoint, $direction );
		} elseif ( 'supervisor' === $endpoint_key && ! empty( $bucket_context['vendor_ids'] ) ) {
			$records = $this->bucket_repository->get_for_endpoint( $endpoint_key, $bucket_context );
		} else {
			$records = $this->bucket_repository->get_for_endpoint( $endpoint_key, $bucket_context );
		}

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
					'endpoint_key'      => (string) ( $endpoint['endpoint_key'] ?? '' ),
					'endpoint_label'    => (string) ( $endpoint['endpoint_label'] ?? '' ),
					'sourcing_direction'=> sanitize_key( $direction ),
				)
			);
		}

		return $decorated;
	}
}
