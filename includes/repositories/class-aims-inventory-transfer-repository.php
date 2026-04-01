<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Transfer_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_transfers';
	}

	public function create( array $data ): int {
		global $wpdb;

		$source_node_type = sanitize_key( (string) ( $data['source_node_type'] ?? 'vendor' ) );
		$source_node_id   = (int) ( $data['source_node_id'] ?? ( $data['source_vendor_id'] ?? 0 ) );
		$target_node_type = sanitize_key( (string) ( $data['target_node_type'] ?? 'vendor' ) );
		$target_node_id   = (int) ( $data['target_node_id'] ?? ( $data['target_vendor_id'] ?? 0 ) );

		$source_vendor_id = (int) ( $data['source_vendor_id'] ?? $this->resolve_vendor_id_from_endpoint( $source_node_type, $source_node_id ) );
		$target_vendor_id = (int) ( $data['target_vendor_id'] ?? $this->resolve_vendor_id_from_endpoint( $target_node_type, $target_node_id ) );

		$record = array(
			'transfer_uuid'        => sanitize_text_field( $data['transfer_uuid'] ?? wp_generate_uuid4() ),
			'transfer_code'        => sanitize_text_field( $data['transfer_code'] ?? $this->generate_transfer_code() ),
			'source_node_type'     => $source_node_type,
			'source_node_id'       => $source_node_id,
			'target_node_type'     => $target_node_type,
			'target_node_id'       => $target_node_id,
			'source_vendor_id'     => $source_vendor_id,
			'target_vendor_id'     => $target_vendor_id,
			'transfer_status'      => sanitize_key( $data['transfer_status'] ?? 'pending' ),
			'transfer_type'        => sanitize_key( $data['transfer_type'] ?? 'standard' ),
			'dispatch_requested_at' => $this->normalize_datetime( $data['dispatch_requested_at'] ?? null ),
			'initiated_by'         => (int) ( $data['initiated_by'] ?? get_current_user_id() ),
			'reference_type'       => sanitize_key( $data['reference_type'] ?? '' ),
			'reference_id'         => sanitize_text_field( $data['reference_id'] ?? '' ),
			'override_route'       => sanitize_key( (string) ( $data['override_route'] ?? '' ) ),
			'override_reason'      => sanitize_text_field( (string) ( $data['override_reason'] ?? '' ) ),
			'override_note'        => isset( $data['override_note'] ) ? sanitize_textarea_field( (string) $data['override_note'] ) : null,
			'override_actor_id'    => (int) ( $data['override_actor_id'] ?? 0 ),
			'override_at'          => $this->normalize_datetime( $data['override_at'] ?? null ),
			'notes'                => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		);

		$format = array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

		$wpdb->insert( $this->get_table_name(), $record, $format );

		return (int) $wpdb->insert_id;
	}

	public function find( int $transfer_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $transfer_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE transfer_uuid = %s LIMIT 1', $uuid ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_by_code( string $code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE transfer_code = %s LIMIT 1', $code ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function update_status( int $transfer_id, string $status, array $extra_data = array() ): bool {
		global $wpdb;

		$record = array(
			'transfer_status' => sanitize_key( $status ),
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( isset( $extra_data['transfer_type'] ) && '' !== sanitize_key( (string) $extra_data['transfer_type'] ) ) {
			$record['transfer_type'] = sanitize_key( (string) $extra_data['transfer_type'] );
		}

		if ( isset( $extra_data['notes'] ) ) {
			$record['notes'] = sanitize_textarea_field( (string) $extra_data['notes'] );
		}

		// Add timestamp fields for status transitions
		if ( 'dispatched' === $status ) {
			$record['dispatch_confirmed_at'] = current_time( 'mysql' );
		} elseif ( 'received' === $status ) {
			$record['receipt_confirmed_at'] = current_time( 'mysql' );
			if ( isset( $extra_data['received_by'] ) ) {
				$record['received_by'] = (int) $extra_data['received_by'];
			}
		} elseif ( 'cancelled' === $status ) {
			$record['cancelled_at'] = current_time( 'mysql' );
			if ( isset( $extra_data['cancelled_by'] ) ) {
				$record['cancelled_by'] = (int) $extra_data['cancelled_by'];
			}
		}

		$result = $wpdb->update(
			$this->get_table_name(),
			$record,
			array( 'id' => $transfer_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function get_for_node( int $node_id, array $filters = array(), string $node_type = 'vendor' ): array {
		global $wpdb;

		$where_parts  = array( '( (source_node_id = %d AND source_node_type = %s) OR (target_node_id = %d AND target_node_type = %s) )' );
		$where_values = array( $node_id, sanitize_key( $node_type ), $node_id, sanitize_key( $node_type ) );

		if ( isset( $filters['transfer_status'] ) && '' !== $filters['transfer_status'] ) {
			$where_parts[] = 'transfer_status = %s';
			$where_values[] = sanitize_key( $filters['transfer_status'] );
		}

		if ( isset( $filters['transfer_type'] ) && '' !== $filters['transfer_type'] ) {
			$where_parts[] = 'transfer_type = %s';
			$where_values[] = sanitize_key( $filters['transfer_type'] );
		}

		if ( isset( $filters['initiated_by'] ) && (int) $filters['initiated_by'] > 0 ) {
			$where_parts[] = 'initiated_by = %d';
			$where_values[] = (int) $filters['initiated_by'];
		}

		$where_clause = implode( ' AND ', $where_parts );

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->get_table_name()} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			array_merge(
				$where_values,
				array(
					(int) ( $filters['limit'] ?? 100 ),
					(int) ( $filters['offset'] ?? 0 ),
				)
			)
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	public function get_incoming_for_node( int $node_id, array $statuses = array( 'pending', 'in_transit' ), string $node_type = 'vendor' ): array {
		global $wpdb;

		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'pending', 'in_transit' );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $node_id, sanitize_key( $node_type ) ), $statuses );

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->get_table_name()} WHERE target_node_id = %d AND target_node_type = %s AND transfer_status IN ({$placeholders}) ORDER BY created_at DESC",
			$params
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	public function get_outgoing_for_node( int $node_id, array $statuses = array( 'pending', 'dispatched', 'in_transit' ), string $node_type = 'vendor' ): array {
		global $wpdb;

		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'pending', 'dispatched', 'in_transit' );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $node_id, sanitize_key( $node_type ) ), $statuses );

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->get_table_name()} WHERE source_node_id = %d AND source_node_type = %s AND transfer_status IN ({$placeholders}) ORDER BY created_at DESC",
			$params
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	public function get_for_vendor( int $vendor_id, array $filters = array() ): array {
		return $this->get_for_node( $vendor_id, $filters, 'vendor' );
	}

	public function get_incoming_for_vendor( int $vendor_id, array $statuses = array( 'pending', 'in_transit' ) ): array {
		return $this->get_incoming_for_node( $vendor_id, $statuses, 'vendor' );
	}

	public function get_outgoing_for_vendor( int $vendor_id, array $statuses = array( 'pending', 'dispatched', 'in_transit' ) ): array {
		return $this->get_outgoing_for_node( $vendor_id, $statuses, 'vendor' );
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}

		return sanitize_text_field( (string) $value );
	}

	private function generate_transfer_code(): string {
		return 'TRANSFER-' . strtoupper( substr( uniqid(), -8 ) );
	}

	private function resolve_vendor_id_from_endpoint( string $node_type, int $node_id ): int {
		if ( 'vendor' === $node_type && $node_id > 0 ) {
			return $node_id;
		}

		return 0;
	}
}
