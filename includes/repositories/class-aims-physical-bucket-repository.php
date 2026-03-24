<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Physical_Bucket_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_physical_buckets';
	}

	public function get_all( array $args = array() ): array {
		global $wpdb;

		$query = $this->build_bucket_query( $args );

		return $wpdb->get_results(
			$wpdb->prepare( $query['sql'], $query['params'] ),
			ARRAY_A
		);
	}

	public function get_all_with_context( array $args = array() ): array {
		return array_map( array( $this, 'hydrate_bucket_context' ), $this->get_all( $args ) );
	}

	public function get_available_for_planning( array $args = array() ): array {
		$args = array_merge(
			array(
				'status' => 'available',
			),
			$args
		);

		return $this->get_all_with_context( $args );
	}

	public function save( array $data, int $bucket_id = 0 ): int {
		global $wpdb;

		$bucket_type = AIMS_Physical_Bucket_Types::normalize( $data['bucket_type'] ?? '' );
		$status      = $this->normalize_status( $data['status'] ?? 'available', $bucket_type );

		$record = array(
			'bucket_code'                => sanitize_text_field( $data['bucket_code'] ?? '' ),
			'bucket_label'               => sanitize_text_field( $data['bucket_label'] ?? '' ),
			'bucket_type'                => $bucket_type,
			'status'                     => $status,
			'current_storage_location_id' => (int) ( $data['current_storage_location_id'] ?? 0 ),
			'home_storage_location_id'   => (int) ( $data['home_storage_location_id'] ?? 0 ),
			'vendor_id'                  => (int) ( $data['vendor_id'] ?? 0 ),
			'barcode_value'              => sanitize_text_field( $data['barcode_value'] ?? '' ),
			'tare_weight'                => number_format( (float) ( $data['tare_weight'] ?? 0 ), 4, '.', '' ),
			'notes'                      => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'                 => current_time( 'mysql' ),
		);

		if ( $bucket_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $bucket_id ) );
			return $bucket_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $bucket_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $bucket_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_code( string $bucket_code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_code = %s', sanitize_text_field( $bucket_code ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_barcode( string $barcode ): ?array {
		global $wpdb;

		if ( '' === trim( $barcode ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE barcode_value = %s', sanitize_text_field( $barcode ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_with_context( int $bucket_id ): ?array {
		global $wpdb;

		$query = $this->build_bucket_query(
			array(
				'bucket_ids' => array( $bucket_id ),
				'limit'      => 1,
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare( $query['sql'], $query['params'] ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate_bucket_context( $row ) : null;
	}

	public function get_for_location( int $location_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE current_storage_location_id = %d ORDER BY bucket_label ASC, bucket_code ASC, id ASC',
				$location_id
			),
			ARRAY_A
		);
	}

	public function get_for_vendor( int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d ORDER BY bucket_label ASC, bucket_code ASC, id ASC',
				$vendor_id
			),
			ARRAY_A
		);
	}

	public function update_current_location( int $bucket_id, int $location_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'current_storage_location_id' => $location_id,
				'updated_at'                  => current_time( 'mysql' ),
			),
			array( 'id' => $bucket_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function update_status( int $bucket_id, string $status ): bool {
		global $wpdb;

		// Fetch the bucket to get its current type for validation.
		$bucket = $this->find( $bucket_id );
		if ( ! is_array( $bucket ) ) {
			return false;
		}

		// Validate status against bucket type constraints.
		$normalized_status = $this->normalize_status( $status, $bucket['bucket_type'] );

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'status'     => $normalized_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $bucket_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function build_bucket_query( array $args ): array {
		global $wpdb;

		$bucket_table  = $this->get_table_name();
		$location_table = $wpdb->prefix . 'aims_storage_locations';

		$sql = 'SELECT b.*, current_loc.id AS current_location_id, current_loc.location_code AS current_location_code, current_loc.location_name AS current_location_name, current_loc.location_type AS current_location_type, current_loc.parent_location_id AS current_location_parent_id, current_loc.sort_order AS current_location_sort_order, current_loc.is_pickable AS current_location_is_pickable, current_loc.is_staging AS current_location_is_staging, current_loc.status AS current_location_status, current_loc.barcode_value AS current_location_barcode, home_loc.id AS home_location_id, home_loc.location_code AS home_location_code, home_loc.location_name AS home_location_name, home_loc.location_type AS home_location_type, home_loc.parent_location_id AS home_location_parent_id, home_loc.sort_order AS home_location_sort_order, home_loc.is_pickable AS home_location_is_pickable, home_loc.is_staging AS home_location_is_staging, home_loc.status AS home_location_status, home_loc.barcode_value AS home_location_barcode FROM ' . $bucket_table . ' b LEFT JOIN ' . $location_table . ' current_loc ON current_loc.id = b.current_storage_location_id LEFT JOIN ' . $location_table . ' home_loc ON home_loc.id = b.home_storage_location_id WHERE 1=1';
		$params = array();

		if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
			$status = $args['status'];

			if ( is_array( $status ) ) {
				$status = array_values(
					array_filter(
						array_map(
							static function ( $value ): string {
								return sanitize_key( (string) $value );
							},
							$status
						)
					)
				);

				if ( ! empty( $status ) ) {
					$sql .= ' AND b.status IN (' . implode( ', ', array_fill( 0, count( $status ), '%s' ) ) . ')';
					$params = array_merge( $params, $status );
				}
			} else {
				$sql      .= ' AND b.status = %s';
				$params[] = sanitize_key( (string) $status );
			}
		}

		if ( ! empty( $args['bucket_type'] ) ) {
			$sql      .= ' AND b.bucket_type = %s';
			$params[] = sanitize_key( (string) $args['bucket_type'] );
		}

		if ( ! empty( $args['vendor_id'] ) ) {
			$sql      .= ' AND b.vendor_id = %d';
			$params[] = (int) $args['vendor_id'];
		}

		if ( ! empty( $args['vendor_ids'] ) && is_array( $args['vendor_ids'] ) ) {
			$vendor_ids = array_values(
				array_filter(
					array_map(
						static function ( $value ): int {
							return (int) $value;
						},
						$args['vendor_ids']
					)
				)
			);

			if ( ! empty( $vendor_ids ) ) {
				$sql .= ' AND b.vendor_id IN (' . implode( ', ', array_fill( 0, count( $vendor_ids ), '%d' ) ) . ')';
				$params = array_merge( $params, $vendor_ids );
			}
		}

		if ( ! empty( $args['location_id'] ) ) {
			$sql      .= ' AND b.current_storage_location_id = %d';
			$params[] = (int) $args['location_id'];
		}

		if ( ! empty( $args['bucket_ids'] ) && is_array( $args['bucket_ids'] ) ) {
			$bucket_ids = array_values(
				array_filter(
					array_map(
						static function ( $value ): int {
							return (int) $value;
						},
						$args['bucket_ids']
					)
				)
			);

			if ( ! empty( $bucket_ids ) ) {
				$sql .= ' AND b.id IN (' . implode( ', ', array_fill( 0, count( $bucket_ids ), '%d' ) ) . ')';
				$params = array_merge( $params, $bucket_ids );
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$sql   .= ' AND (b.bucket_code LIKE %s OR b.bucket_label LIKE %s OR b.barcode_value LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$sql .= ' ORDER BY b.bucket_label ASC, b.bucket_code ASC, b.id ASC';

		if ( isset( $args['limit'] ) ) {
			$sql      .= ' LIMIT %d';
			$params[] = max( 1, (int) $args['limit'] );
		}

		if ( isset( $args['offset'] ) ) {
			$sql      .= ' OFFSET %d';
			$params[] = max( 0, (int) $args['offset'] );
		}

		return array(
			'sql'    => $sql,
			'params' => $params,
		);
	}

	private function hydrate_bucket_context( array $row ): array {
		return array_merge(
			$row,
			array(
				'current_storage_location' => $this->build_location_context(
					(int) ( $row['current_location_id'] ?? 0 ),
					(string) ( $row['current_location_code'] ?? '' ),
					(string) ( $row['current_location_name'] ?? '' ),
					(string) ( $row['current_location_type'] ?? '' ),
					(int) ( $row['current_location_parent_id'] ?? 0 ),
					(int) ( $row['current_location_sort_order'] ?? 0 ),
					! empty( $row['current_location_is_pickable'] ),
					! empty( $row['current_location_is_staging'] ),
					(string) ( $row['current_location_status'] ?? '' ),
					(string) ( $row['current_location_barcode'] ?? '' )
				),
				'home_storage_location'    => $this->build_location_context(
					(int) ( $row['home_location_id'] ?? 0 ),
					(string) ( $row['home_location_code'] ?? '' ),
					(string) ( $row['home_location_name'] ?? '' ),
					(string) ( $row['home_location_type'] ?? '' ),
					(int) ( $row['home_location_parent_id'] ?? 0 ),
					(int) ( $row['home_location_sort_order'] ?? 0 ),
					! empty( $row['home_location_is_pickable'] ),
					! empty( $row['home_location_is_staging'] ),
					(string) ( $row['home_location_status'] ?? '' ),
					(string) ( $row['home_location_barcode'] ?? '' )
				),
			)
		);
	}

	private function build_location_context(
		int $location_id,
		string $location_code,
		string $location_name,
		string $location_type,
		int $parent_location_id,
		int $sort_order,
		bool $is_pickable,
		bool $is_staging,
		string $status,
		string $barcode_value
	): array {
		if ( 0 === $location_id && '' === $location_code && '' === $location_name ) {
			return array();
		}

		return array(
			'id'                => $location_id,
			'location_code'     => $location_code,
			'location_name'     => $location_name,
			'location_type'     => $location_type,
			'parent_location_id'=> $parent_location_id,
			'sort_order'        => $sort_order,
			'is_pickable'       => $is_pickable ? 1 : 0,
			'is_staging'        => $is_staging ? 1 : 0,
			'status'            => sanitize_key( $status ),
			'barcode_value'     => $barcode_value,
		);
	}

	/**
	 * Normalize bucket type using canonical constants.
	 *
	 * @param string $bucket_type The bucket type to normalize.
	 * @return string Normalized bucket type.
	 */
	private function normalize_bucket_type( string $bucket_type ): string {
		return AIMS_Physical_Bucket_Types::normalize( $bucket_type );
	}

	/**
	 * Normalize bucket status with validation against bucket type.
	 * Ensures status is allowed for the given bucket type.
	 *
	 * @param string $status The status to normalize.
	 * @param string $bucket_type The bucket type to validate against.
	 * @return string Normalized status.
	 */
	private function normalize_status( string $status, string $bucket_type ): string {
		$status      = sanitize_key( $status );
		$bucket_type = sanitize_key( $bucket_type );

		if ( AIMS_Physical_Bucket_Types::is_valid_status_for_type( $bucket_type, $status ) ) {
			return $status;
		}

		// Default to the first allowed status for this bucket type.
		$allowed = AIMS_Physical_Bucket_Types::allowed_statuses_for_type( $bucket_type );
		return ! empty( $allowed[0] ) ? $allowed[0] : 'available';
	}
}

