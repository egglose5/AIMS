<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Bucket_Planning_Availability_Service {
	private $buckets;
	private $bucket_positions;
	private $assignments;

	public function __construct(
		AIMS_Physical_Bucket_Repository $buckets = null,
		AIMS_Bucket_Inventory_Position_Repository $bucket_positions = null,
		AIMS_Event_Bucket_Assignment_Repository $assignments = null
	) {
		$this->buckets         = $buckets ?: new AIMS_Physical_Bucket_Repository();
		$this->bucket_positions = $bucket_positions ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->assignments     = $assignments ?: new AIMS_Event_Bucket_Assignment_Repository();
	}

	public function get_event_planning_context( int $event_id, array $args = array() ): array {
		$event_id = max( 0, $event_id );

		if ( $event_id <= 0 ) {
			return array(
				'event_id'              => 0,
				'assigned_buckets'      => array(),
				'available_buckets'     => array(),
				'assigned_bucket_ids'   => array(),
				'available_bucket_ids'  => array(),
				'assigned_count'        => 0,
				'available_count'       => 0,
				'filters'               => $this->normalize_filters( $args ),
			);
		}

		$assigned_buckets  = $this->get_assigned_buckets_for_event( $event_id );
		$available_buckets = $this->get_available_buckets_for_event( $event_id, $args );

		return array(
			'event_id'             => $event_id,
			'assigned_buckets'     => $assigned_buckets,
			'available_buckets'    => $available_buckets,
			'assigned_bucket_ids'  => $this->extract_bucket_ids( $assigned_buckets ),
			'available_bucket_ids' => $this->extract_bucket_ids( $available_buckets ),
			'assigned_count'       => count( $assigned_buckets ),
			'available_count'      => count( $available_buckets ),
			'filters'              => $this->normalize_filters( $args ),
		);
	}

	public function get_available_buckets_for_event( int $event_id, array $args = array() ): array {
		$event_id = max( 0, $event_id );

		if ( $event_id <= 0 ) {
			return array();
		}

		$assigned_bucket_ids = $this->normalize_id_list( $args['assigned_bucket_ids'] ?? array() );
		$bucket_rows = $this->get_candidate_buckets( $args );
		$available   = array();

		foreach ( $bucket_rows as $bucket_row ) {
			if ( ! is_array( $bucket_row ) || empty( $bucket_row['id'] ) ) {
				continue;
			}

			if ( in_array( (int) $bucket_row['id'], $assigned_bucket_ids, true ) ) {
				continue;
			}

			$assignment = $this->get_active_assignment_for_bucket( (int) $bucket_row['id'] );
			if ( ! empty( $assignment ) ) {
				continue;
			}

			$available[] = $this->build_bucket_context( $bucket_row, array(), $event_id, 'available' );
		}

		return $available;
	}

	public function get_assigned_buckets_for_event( int $event_id ): array {
		$event_id = max( 0, $event_id );

		if ( $event_id <= 0 ) {
			return array();
		}

		$assigned = array();

		foreach ( $this->assignments->get_active_for_event( $event_id ) as $assignment_row ) {
			if ( ! is_array( $assignment_row ) ) {
				continue;
			}

			$bucket_id = (int) ( $assignment_row['physical_bucket_id'] ?? 0 );
			if ( $bucket_id <= 0 ) {
				continue;
			}

			$bucket = $this->find_bucket_with_context( $bucket_id );
			if ( empty( $bucket ) ) {
				continue;
			}

			$assigned[] = $this->build_bucket_context( $bucket, $assignment_row, $event_id, 'assigned_to_event' );
		}

		return $assigned;
	}

	public function get_bucket_context( int $bucket_id, int $event_id = 0 ): array {
		$bucket_id = max( 0, $bucket_id );

		if ( $bucket_id <= 0 ) {
			return array();
		}

		$bucket = $this->find_bucket_with_context( $bucket_id );
		if ( empty( $bucket ) ) {
			return array();
		}

		$assignment = $this->get_active_assignment_for_bucket( $bucket_id );

		if ( ! empty( $assignment ) && $event_id > 0 && (int) ( $assignment['event_id'] ?? 0 ) !== $event_id ) {
			return $this->build_bucket_context( $bucket, $assignment, $event_id, 'assigned_elsewhere' );
		}

		return $this->build_bucket_context(
			$bucket,
			$assignment,
			$event_id,
			! empty( $assignment ) ? 'assigned' : 'available'
		);
	}

	public function get_bucket_contents( int $bucket_id ): array {
		return $this->enrich_bucket_contents( $bucket_id );
	}

	private function get_candidate_buckets( array $args ): array {
		$vendor_ids = $this->normalize_id_list( $args['vendor_ids'] ?? array() );
		$filters = array(
			'status'     => $args['status'] ?? 'available',
			'vendor_id'  => $args['vendor_id'] ?? 0,
			'vendor_ids' => $vendor_ids,
			'location_id'=> $args['location_id'] ?? 0,
			'bucket_type'=> $args['bucket_type'] ?? '',
			'search'     => $args['search'] ?? '',
			'limit'      => $args['limit'] ?? 250,
			'offset'     => $args['offset'] ?? 0,
		);

		if ( ! empty( $args['bucket_ids'] ) && is_array( $args['bucket_ids'] ) ) {
			$filters['bucket_ids'] = $args['bucket_ids'];
		}

		return $this->buckets->get_available_for_planning( $filters );
	}

	private function find_bucket_with_context( int $bucket_id ): array {
		if ( method_exists( $this->buckets, 'find_with_context' ) ) {
			$bucket = $this->buckets->find_with_context( $bucket_id );
			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		if ( method_exists( $this->buckets, 'find' ) ) {
			$bucket = $this->buckets->find( $bucket_id );
			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		return array();
	}

	private function get_active_assignment_for_bucket( int $bucket_id ): ?array {
		if ( $bucket_id <= 0 ) {
			return null;
		}

		$assignment = $this->assignments->get_active_for_bucket( $bucket_id );

		return is_array( $assignment ) ? $assignment : null;
	}

	private function build_bucket_context( array $bucket, array $assignment = array(), int $event_id = 0, string $state = 'available' ): array {
		$bucket_id = (int) ( $bucket['id'] ?? $bucket['bucket_id'] ?? 0 );
		$contents  = $this->enrich_bucket_contents( $bucket_id );

		return array_merge(
			$bucket,
			array(
				'bucket_id'               => $bucket_id,
				'physical_bucket_id'      => $bucket_id,
				'current_storage_location' => $this->extract_location_context( $bucket, 'current' ),
				'home_storage_location'    => $this->extract_location_context( $bucket, 'home' ),
				'storage'                 => array(
					'current' => $this->extract_location_context( $bucket, 'current' ),
					'home'    => $this->extract_location_context( $bucket, 'home' ),
				),
				'contents'                 => $contents,
				'content_count'            => count( $contents ),
				'total_quantity'           => $this->sum_content_field( $contents, 'quantity' ),
				'total_reserved_quantity'  => $this->sum_content_field( $contents, 'reserved_quantity' ),
				'content_summary'          => $this->summarize_contents( $contents ),
				'active_assignment'        => ! empty( $assignment ) ? $this->normalize_assignment( $assignment ) : null,
				'active_assignment_event_id'=> ! empty( $assignment ) ? (int) ( $assignment['event_id'] ?? 0 ) : 0,
				'planning_state'           => sanitize_key( $state ),
				'available_for_event_id'    => $event_id,
			)
		);
	}

	private function enrich_bucket_contents( int $bucket_id ): array {
		if ( $bucket_id <= 0 ) {
			return array();
		}

		$rows = method_exists( $this->bucket_positions, 'get_bucket_contents_summary' )
			? (array) $this->bucket_positions->get_bucket_contents_summary( $bucket_id )
			: (array) $this->bucket_positions->get_for_bucket( $bucket_id );

		$enriched = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = (int) ( $row['product_id'] ?? 0 );
			$product    = $this->resolve_product_context( $product_id );

			$enriched[] = array_merge(
				$row,
				$product
			);
		}

		usort(
			$enriched,
			static function ( array $left, array $right ): int {
				$left_sku = sanitize_text_field( (string) ( $left['product_sku'] ?? '' ) );
				$right_sku = sanitize_text_field( (string) ( $right['product_sku'] ?? '' ) );

				if ( '' !== $left_sku || '' !== $right_sku ) {
					$result = strcasecmp( $left_sku, $right_sku );
					if ( 0 !== $result ) {
						return $result;
					}
				}

				return (int) ( $left['product_id'] ?? 0 ) <=> (int) ( $right['product_id'] ?? 0 );
			}
		);

		return $enriched;
	}

	private function resolve_product_context( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return array(
				'product_sku'  => '',
				'product_name' => '',
			);
		}

		$product_sku  = '';
		$product_name = '';

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );

			if ( $product && is_object( $product ) ) {
				if ( method_exists( $product, 'get_sku' ) ) {
					$product_sku = sanitize_text_field( (string) $product->get_sku() );
				}

				if ( method_exists( $product, 'get_name' ) ) {
					$product_name = sanitize_text_field( (string) $product->get_name() );
				}
			}
		}

		if ( '' === $product_sku && function_exists( 'get_post_meta' ) ) {
			$product_sku = sanitize_text_field( (string) get_post_meta( $product_id, '_sku', true ) );
		}

		if ( '' === $product_name && function_exists( 'get_the_title' ) ) {
			$product_name = sanitize_text_field( (string) get_the_title( $product_id ) );
		}

		if ( '' === $product_name && '' !== $product_sku ) {
			$product_name = $product_sku;
		}

		if ( '' === $product_name ) {
			$product_name = 'Product #' . $product_id;
		}

		return array(
			'product_sku'  => $product_sku,
			'product_name' => $product_name,
		);
	}

	private function extract_location_context( array $bucket, string $prefix ): array {
		$location_id = (int) ( $bucket[ $prefix . '_location_id' ] ?? 0 );
		$location_code = (string) ( $bucket[ $prefix . '_location_code' ] ?? '' );
		$location_name = (string) ( $bucket[ $prefix . '_location_name' ] ?? '' );
		$location_type = (string) ( $bucket[ $prefix . '_location_type' ] ?? '' );

		if ( 0 === $location_id && '' === $location_code && '' === $location_name ) {
			return array();
		}

		return array(
			'id'                => $location_id,
			'location_code'     => $location_code,
			'location_name'     => $location_name,
			'location_type'     => $location_type,
			'parent_location_id'=> (int) ( $bucket[ $prefix . '_location_parent_id' ] ?? 0 ),
			'sort_order'        => (int) ( $bucket[ $prefix . '_location_sort_order' ] ?? 0 ),
			'is_pickable'       => ! empty( $bucket[ $prefix . '_location_is_pickable' ] ) ? 1 : 0,
			'is_staging'        => ! empty( $bucket[ $prefix . '_location_is_staging' ] ) ? 1 : 0,
			'status'            => sanitize_key( (string) ( $bucket[ $prefix . '_location_status' ] ?? '' ) ),
			'barcode_value'     => (string) ( $bucket[ $prefix . '_location_barcode' ] ?? '' ),
		);
	}

	private function normalize_assignment( array $assignment ): array {
		return array(
			'id'                 => (int) ( $assignment['id'] ?? 0 ),
			'event_id'           => (int) ( $assignment['event_id'] ?? 0 ),
			'physical_bucket_id' => (int) ( $assignment['physical_bucket_id'] ?? 0 ),
			'assignment_status'  => sanitize_key( (string) ( $assignment['assignment_status'] ?? '' ) ),
			'assignment_type'    => sanitize_key( (string) ( $assignment['assignment_type'] ?? '' ) ),
			'assigned_at'        => $this->normalize_datetime( $assignment['assigned_at'] ?? null ),
			'released_at'        => $this->normalize_datetime( $assignment['released_at'] ?? null ),
			'is_active'          => ! empty( $assignment['is_active'] ) ? 1 : 0,
			'display_order'      => (int) ( $assignment['display_order'] ?? 0 ),
			'notes'              => sanitize_text_field( (string) ( $assignment['notes'] ?? '' ) ),
		);
	}

	private function sum_content_field( array $contents, string $field ): float {
		$total = 0.0;

		foreach ( $contents as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$total += (float) ( $row[ $field ] ?? 0 );
		}

		return round( $total, 4 );
	}

	private function extract_bucket_ids( array $rows ): array {
		$ids = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$ids[] = (int) ( $row['bucket_id'] ?? $row['id'] ?? 0 );
		}

		return array_values( array_filter( array_unique( $ids ) ) );
	}

	private function normalize_filters( array $args ): array {
		return array(
			'status'      => $args['status'] ?? 'available',
			'vendor_id'   => (int) ( $args['vendor_id'] ?? 0 ),
			'vendor_ids'  => $this->normalize_id_list( $args['vendor_ids'] ?? array() ),
			'assigned_bucket_ids' => $this->normalize_id_list( $args['assigned_bucket_ids'] ?? array() ),
			'location_id' => (int) ( $args['location_id'] ?? 0 ),
			'bucket_type' => sanitize_key( (string) ( $args['bucket_type'] ?? '' ) ),
			'search'      => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'limit'       => max( 1, (int) ( $args['limit'] ?? 250 ) ),
			'offset'      => max( 0, (int) ( $args['offset'] ?? 0 ) ),
		);
	}

	private function normalize_id_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $value ): int {
							return (int) $value;
						},
						$values
					)
				)
			)
		);
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
