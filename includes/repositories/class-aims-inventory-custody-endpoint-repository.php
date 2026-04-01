<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Custody_Endpoint_Repository {
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_INACTIVE = 'inactive';
	public const STATUS_ARCHIVED = 'archived';

	public const TYPE_WAREHOUSE = 'warehouse';
	public const TYPE_SUPERVISOR = 'supervisor';
	public const TYPE_VENDOR    = 'vendor';
	public const TYPE_STITCHER  = 'stitcher';
	public const TYPE_EVENT     = 'event';
	public const TYPE_CUSTOM    = 'custom';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_custody_endpoints';
	}

	public function save( array $data, int $endpoint_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $endpoint_id > 0 ) {
			$record['updated_at'] = current_time( 'mysql' );
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $endpoint_id )
			);

			return $endpoint_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $endpoint_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d LIMIT 1', $endpoint_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_key( string $endpoint_key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE endpoint_key = %s LIMIT 1', sanitize_key( $endpoint_key ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_directory( array $args = array() ): array {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE 1=1';
		$params = array();

		if ( ! empty( $args['endpoint_type'] ) ) {
			$sql      .= ' AND endpoint_type = %s';
			$params[] = sanitize_key( (string) $args['endpoint_type'] );
		}

		if ( ! empty( $args['endpoint_status'] ) ) {
			$sql      .= ' AND endpoint_status = %s';
			$params[] = sanitize_key( (string) $args['endpoint_status'] );
		}

		if ( ! empty( $args['node_ref_type'] ) ) {
			$sql      .= ' AND node_ref_type = %s';
			$params[] = sanitize_key( (string) $args['node_ref_type'] );
		}

		if ( isset( $args['node_ref_id'] ) && (int) $args['node_ref_id'] > 0 ) {
			$sql      .= ' AND node_ref_id = %d';
			$params[] = (int) $args['node_ref_id'];
		}

		if ( isset( $args['parent_endpoint_id'] ) && (int) $args['parent_endpoint_id'] > 0 ) {
			$sql      .= ' AND parent_endpoint_id = %d';
			$params[] = (int) $args['parent_endpoint_id'];
		}

		$sql .= ' ORDER BY endpoint_type ASC, endpoint_name ASC, id ASC';

		$results = $wpdb->get_results(
			empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	public function get_for_node( string $node_ref_type, int $node_ref_id, array $args = array() ): array {
		$args['node_ref_type'] = $node_ref_type;
		$args['node_ref_id']   = $node_ref_id;

		return $this->get_directory( $args );
	}

	public function get_active_for_node( string $node_ref_type, int $node_ref_id ): array {
		return $this->get_for_node(
			$node_ref_type,
			$node_ref_id,
			array(
				'endpoint_status' => self::STATUS_ACTIVE,
			)
		);
	}

	private function build_record( array $data ): array {
		return array(
			'endpoint_key'           => sanitize_key( (string) ( $data['endpoint_key'] ?? '' ) ),
			'endpoint_name'          => sanitize_text_field( (string) ( $data['endpoint_name'] ?? '' ) ),
			'endpoint_type'          => $this->normalize_type( (string) ( $data['endpoint_type'] ?? self::TYPE_CUSTOM ) ),
			'endpoint_status'        => $this->normalize_status( (string) ( $data['endpoint_status'] ?? self::STATUS_ACTIVE ) ),
			'node_ref_type'          => sanitize_key( (string) ( $data['node_ref_type'] ?? '' ) ),
			'node_ref_id'            => (int) ( $data['node_ref_id'] ?? 0 ),
			'parent_endpoint_id'      => (int) ( $data['parent_endpoint_id'] ?? 0 ),
			'default_route_policy'    => $this->normalize_policy( (string) ( $data['default_route_policy'] ?? 'guidance' ) ),
			'allows_direct_collection' => array_key_exists( 'allows_direct_collection', $data ) ? ( ! empty( $data['allows_direct_collection'] ) ? 1 : 0 ) : 1,
			'allows_direct_recovery'  => array_key_exists( 'allows_direct_recovery', $data ) ? ( ! empty( $data['allows_direct_recovery'] ) ? 1 : 0 ) : 1,
			'notes'                   => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
			'updated_at'              => current_time( 'mysql' ),
		);
	}

	private function normalize_type( string $type ): string {
		$type = sanitize_key( $type );
		$allowed = array(
			self::TYPE_WAREHOUSE,
			self::TYPE_SUPERVISOR,
			self::TYPE_VENDOR,
			self::TYPE_STITCHER,
			self::TYPE_EVENT,
			self::TYPE_CUSTOM,
		);

		return in_array( $type, $allowed, true ) ? $type : self::TYPE_CUSTOM;
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		$allowed = array(
			self::STATUS_ACTIVE,
			self::STATUS_INACTIVE,
			self::STATUS_ARCHIVED,
		);

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_ACTIVE;
	}

	private function normalize_policy( string $policy ): string {
		$policy = sanitize_key( $policy );

		if ( '' === $policy ) {
			return 'guidance';
		}

		return $policy;
	}
}
