<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Custody_Endpoint_Relationship_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_custody_endpoint_relationships';
	}

	public function save( array $data, int $relationship_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $relationship_id > 0 ) {
			$record['updated_at'] = current_time( 'mysql' );
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $relationship_id )
			);

			return $relationship_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $relationship_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d LIMIT 1', $relationship_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_endpoints( int $source_endpoint_id, int $target_endpoint_id, string $relationship_key = 'default_route' ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE source_endpoint_id = %d AND target_endpoint_id = %d AND relationship_key = %s LIMIT 1',
				$source_endpoint_id,
				$target_endpoint_id,
				sanitize_key( $relationship_key )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_source_endpoint( int $source_endpoint_id, array $args = array() ): array {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE source_endpoint_id = %d';
		$params = array( $source_endpoint_id );

		if ( ! empty( $args['relationship_key'] ) ) {
			$sql      .= ' AND relationship_key = %s';
			$params[] = sanitize_key( (string) $args['relationship_key'] );
		}

		if ( ! empty( $args['relationship_type'] ) ) {
			$sql      .= ' AND relationship_type = %s';
			$params[] = sanitize_key( (string) $args['relationship_type'] );
		}

		if ( isset( $args['is_active'] ) ) {
			$sql      .= ' AND is_active = %d';
			$params[] = ! empty( $args['is_active'] ) ? 1 : 0;
		}

		if ( isset( $args['is_default_route'] ) ) {
			$sql      .= ' AND is_default_route = %d';
			$params[] = ! empty( $args['is_default_route'] ) ? 1 : 0;
		}

		$sql .= ' ORDER BY is_default_route DESC, route_priority ASC, id ASC';

		if ( isset( $args['limit'] ) ) {
			$sql      .= ' LIMIT %d';
			$params[] = max( 1, (int) $args['limit'] );
		}

		$results = $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	public function get_default_route_for_source_endpoint( int $source_endpoint_id, string $relationship_key = '' ): ?array {
		$args = array(
			'is_active'         => 1,
			'is_default_route'  => 1,
			'limit'             => 1,
		);

		if ( '' !== trim( $relationship_key ) ) {
			$args['relationship_key'] = $relationship_key;
		}

		$rows = $this->get_for_source_endpoint( $source_endpoint_id, $args );

		return ! empty( $rows ) && is_array( $rows[0] ) ? $rows[0] : null;
	}

	private function build_record( array $data ): array {
		return array(
			'source_endpoint_id' => (int) ( $data['source_endpoint_id'] ?? 0 ),
			'target_endpoint_id' => (int) ( $data['target_endpoint_id'] ?? 0 ),
			'relationship_key'   => sanitize_key( (string) ( $data['relationship_key'] ?? 'default_route' ) ),
			'relationship_type'  => sanitize_key( (string) ( $data['relationship_type'] ?? 'default_route' ) ),
			'route_priority'     => (int) ( $data['route_priority'] ?? 0 ),
			'route_policy'       => $this->normalize_policy( (string) ( $data['route_policy'] ?? 'guidance' ) ),
			'is_default_route'   => array_key_exists( 'is_default_route', $data ) ? ( ! empty( $data['is_default_route'] ) ? 1 : 0 ) : 1,
			'is_active'          => array_key_exists( 'is_active', $data ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'guidance_label'     => sanitize_text_field( (string) ( $data['guidance_label'] ?? '' ) ),
			'guidance_notes'     => isset( $data['guidance_notes'] ) ? sanitize_textarea_field( (string) $data['guidance_notes'] ) : '',
			'updated_at'         => current_time( 'mysql' ),
		);
	}

	private function normalize_policy( string $policy ): string {
		$policy = sanitize_key( $policy );

		if ( '' === $policy ) {
			return 'guidance';
		}

		return $policy;
	}
}
