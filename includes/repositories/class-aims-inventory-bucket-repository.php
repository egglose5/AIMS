<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Bucket_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_buckets';
	}

	public function find_bucket_by_id( int $bucket_id ): ?array {
		global $wpdb;

		if ( $bucket_id <= 0 ) {
			return null;
		}

		$bucket = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$bucket_id
			),
			ARRAY_A
		);

		return is_array( $bucket ) ? $bucket : null;
	}

	public function get_all(): array {
		global $wpdb;

		return $wpdb->get_results(
			'SELECT * FROM ' . $this->get_table_name() . ' ORDER BY bucket_label ASC, id ASC',
			ARRAY_A
		);
	}

	public function get_buckets_by_type( string $bucket_type ): array {
		global $wpdb;

		$bucket_type = sanitize_key( $bucket_type );

		if ( '' === $bucket_type ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_type = %s ORDER BY bucket_label ASC, id ASC',
				$bucket_type
			),
			ARRAY_A
		);
	}

	public function get_event_buckets(): array {
		return $this->get_buckets_by_type( 'event' );
	}

	public function get_warehouse_buckets(): array {
		return $this->get_buckets_by_type( 'warehouse' );
	}

	public function get_by_ids( array $bucket_ids ): array {
		global $wpdb;

		$bucket_ids = array_values(
			array_filter(
				array_map( 'intval', $bucket_ids )
			)
		);

		if ( empty( $bucket_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $bucket_ids ), '%d' ) );
		$sql = $wpdb->prepare(
			'SELECT * FROM ' . $this->get_table_name() . ' WHERE id IN (' . $placeholders . ') ORDER BY bucket_label ASC, id ASC',
			...$bucket_ids
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function find_bucket_by_key( string $bucket_key ): ?array {
		global $wpdb;

		$bucket_key = sanitize_text_field( $bucket_key );

		if ( '' === $bucket_key ) {
			return null;
		}

		$bucket = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_key = %s',
				$bucket_key
			),
			ARRAY_A
		);

		return is_array( $bucket ) ? $bucket : null;
	}

	public function find_bucket_by_key_and_type( string $bucket_key, string $bucket_type = '' ): ?array {
		$bucket = $this->find_bucket_by_key( $bucket_key );

		if ( empty( $bucket ) ) {
			return null;
		}

		$bucket_type = sanitize_key( $bucket_type );
		if ( '' !== $bucket_type && $bucket_type !== sanitize_key( (string) ( $bucket['bucket_type'] ?? '' ) ) ) {
			return null;
		}

		return $bucket;
	}

	public function find_bucket_by_primary_identity( int $bucket_id = 0, string $bucket_key = '', string $bucket_type = '' ): ?array {
		if ( $bucket_id > 0 ) {
			return $this->find_bucket_by_id( $bucket_id );
		}

		$bucket_key  = sanitize_text_field( $bucket_key );
		$bucket_type = sanitize_key( $bucket_type );

		if ( '' !== $bucket_key ) {
			return $this->find_bucket_by_key_and_type( $bucket_key, $bucket_type );
		}

		return null;
	}

	public function find_bucket_by_identity(
		string $bucket_key,
		string $bucket_type = '',
		string $owner_entity_type = '',
		int $owner_entity_id = 0,
		string $square_location_id = '',
		int $vendor_id = 0,
		int $product_id = 0,
		string $bucket_code = ''
	): ?array {
		global $wpdb;

		$bucket_key         = sanitize_text_field( $bucket_key );
		$bucket_type        = sanitize_key( $bucket_type );
		$owner_entity_type  = sanitize_key( $owner_entity_type );
		$square_location_id = sanitize_text_field( $square_location_id );
		$bucket_code        = sanitize_text_field( $bucket_code );

		if ( '' !== $bucket_key ) {
			return $this->find_bucket_by_key_and_type( $bucket_key, $bucket_type );
		}

		$bucket = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE bucket_type = %s AND owner_entity_type = %s AND owner_entity_id = %d AND square_location_id = %s AND vendor_id = %d AND product_id = %d AND bucket_code = %s',
				$bucket_type,
				$owner_entity_type,
				$owner_entity_id,
				$square_location_id,
				$vendor_id,
				$product_id,
				$bucket_code
			),
			ARRAY_A
		);

		return is_array( $bucket ) ? $bucket : null;
	}

	public function find_bucket( int $vendor_id, int $product_id, string $bucket_code ): ?array {
		return $this->find_bucket_by_identity(
			'',
			'vendor',
			'vendor',
			$vendor_id,
			'',
			$vendor_id,
			$product_id,
			$bucket_code
		);
	}

	public function upsert_bucket( array $data ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		$existing = null;

		if ( ! empty( $data['bucket_id'] ) ) {
			$existing = $this->find_bucket_by_primary_identity( (int) $data['bucket_id'], $record['bucket_key'], $record['bucket_type'] );
		} elseif ( '' !== $record['bucket_key'] ) {
			$existing = $this->find_bucket_by_primary_identity( 0, $record['bucket_key'], $record['bucket_type'] );
		} else {
			$existing = $this->find_bucket_by_identity(
				'',
				$record['bucket_type'],
				$record['owner_entity_type'],
				(int) $record['owner_entity_id'],
				$record['square_location_id'],
				(int) $record['vendor_id'],
				(int) $record['product_id'],
				$record['bucket_code']
			);
		}

		if ( ! empty( $existing['id'] ) ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%f', '%f', '%s', '%s' ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function get_total_quantity_for_bucket_by_id( int $bucket_id ): float {
		global $wpdb;

		if ( $bucket_id <= 0 ) {
			return 0.0;
		}

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(quantity, 0) FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$bucket_id
			)
		);

		return (float) $total;
	}

	public function get_bucket_snapshot_by_id( int $bucket_id ): ?array {
		$bucket = $this->find_bucket_by_id( $bucket_id );

		if ( empty( $bucket ) ) {
			return null;
		}

		return $this->build_snapshot( $bucket );
	}

	public function get_bucket_snapshots_by_type( string $bucket_type ): array {
		$buckets = $this->get_buckets_by_type( $bucket_type );

		return array_map(
			array( $this, 'build_snapshot' ),
			$buckets
		);
	}

	public function get_total_quantity_for_bucket( int $vendor_id, int $product_id, string $bucket_code ): float {
		$bucket = $this->find_bucket_by_identity( '', 'vendor', 'vendor', $vendor_id, '', $vendor_id, $product_id, $bucket_code );

		return ! empty( $bucket['quantity'] ) ? (float) $bucket['quantity'] : 0.0;
	}

	private function build_record( array $data ): array {
		$bucket_type       = sanitize_key( $data['bucket_type'] ?? '' );
		$owner_entity_type = sanitize_key( $data['owner_entity_type'] ?? '' );
		$bucket_key        = sanitize_text_field( $data['bucket_key'] ?? '' );
		$bucket_label      = sanitize_text_field( $data['bucket_label'] ?? ( $data['bucket_name'] ?? '' ) );
		$square_location_id = sanitize_text_field( $data['square_location_id'] ?? '' );
		$vendor_id         = (int) ( $data['vendor_id'] ?? 0 );
		$product_id        = (int) ( $data['product_id'] ?? 0 );
		$bucket_code       = sanitize_text_field( $data['bucket_code'] ?? '' );
		$owner_entity_id   = (int) ( $data['owner_entity_id'] ?? 0 );

		if ( '' === $bucket_type ) {
			$bucket_type = ! empty( $data['owner_entity_type'] ) ? sanitize_key( (string) $data['owner_entity_type'] ) : ( ! empty( $square_location_id ) ? 'event' : 'warehouse' );
		}

		if ( '' === $owner_entity_type && 0 !== $owner_entity_id ) {
			$owner_entity_type = 'vendor';
		}

		if ( '' === $bucket_key ) {
			$bucket_key = $this->build_bucket_key(
				$bucket_type,
				$owner_entity_type,
				$owner_entity_id,
				$square_location_id,
				$vendor_id,
				$product_id,
				$bucket_code,
				$bucket_label
			);
		}

		if ( '' === $bucket_label ) {
			$bucket_label = $bucket_key;
		}

		return array(
			'bucket_key'         => $bucket_key,
			'bucket_type'        => $bucket_type,
			'bucket_label'       => $bucket_label,
			'owner_entity_type'  => $owner_entity_type,
			'owner_entity_id'    => $owner_entity_id,
			'square_location_id' => $square_location_id,
			'vendor_id'          => $vendor_id,
			'product_id'         => $product_id,
			'bucket_code'        => $bucket_code,
			'quantity'           => number_format( (float) ( $data['quantity'] ?? 0 ), 4, '.', '' ),
			'reserved_quantity'  => number_format( (float) ( $data['reserved_quantity'] ?? 0 ), 4, '.', '' ),
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);
	}

	private function build_bucket_key(
		string $bucket_type,
		string $owner_entity_type,
		int $owner_entity_id,
		string $square_location_id,
		int $vendor_id,
		int $product_id,
		string $bucket_code,
		string $bucket_label
	): string {
		$parts = array(
			$bucket_type,
			$owner_entity_type,
			(string) $owner_entity_id,
			$square_location_id,
			(string) $vendor_id,
			(string) $product_id,
			$bucket_code,
			$bucket_label,
		);

		$parts = array_filter(
			array_map(
				static function ( $value ) {
					return sanitize_key( (string) $value );
				},
				$parts
			)
		);

		if ( empty( $parts ) ) {
			return wp_generate_uuid4();
		}

		return implode( ':', $parts );
	}

	private function build_snapshot( array $bucket ): array {
		$quantity = (float) ( $bucket['quantity'] ?? 0 );
		$reserved = (float) ( $bucket['reserved_quantity'] ?? 0 );

		return array(
			'id'                => (int) ( $bucket['id'] ?? 0 ),
			'bucket_key'        => (string) ( $bucket['bucket_key'] ?? '' ),
			'bucket_type'       => (string) ( $bucket['bucket_type'] ?? 'warehouse' ),
			'bucket_label'      => (string) ( $bucket['bucket_label'] ?? '' ),
			'owner_entity_type' => (string) ( $bucket['owner_entity_type'] ?? '' ),
			'owner_entity_id'   => (int) ( $bucket['owner_entity_id'] ?? 0 ),
			'square_location_id' => (string) ( $bucket['square_location_id'] ?? '' ),
			'vendor_id'         => (int) ( $bucket['vendor_id'] ?? 0 ),
			'product_id'        => (int) ( $bucket['product_id'] ?? 0 ),
			'bucket_code'       => (string) ( $bucket['bucket_code'] ?? '' ),
			'quantity'          => $quantity,
			'reserved_quantity' => $reserved,
			'available_quantity'=> max( 0, $quantity - $reserved ),
			'updated_at'        => (string) ( $bucket['updated_at'] ?? '' ),
		);
	}
}
