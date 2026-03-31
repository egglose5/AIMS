<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Transfer_Items_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_inventory_transfer_items';
	}

	public function create( array $data ): int {
		global $wpdb;

		$record = array(
			'transfer_id'         => (int) ( $data['transfer_id'] ?? 0 ),
			'transfer_uuid'       => sanitize_text_field( $data['transfer_uuid'] ?? '' ),
			'line_number'         => (int) ( $data['line_number'] ?? 1 ),
			'product_id'          => (int) ( $data['product_id'] ?? 0 ),
			'vendor_id'           => (int) ( $data['vendor_id'] ?? 0 ),
			'requested_quantity'  => number_format( (float) ( $data['requested_quantity'] ?? 0 ), 4, '.', '' ),
			'line_status'         => sanitize_key( $data['line_status'] ?? 'pending' ),
			'source_bucket_id'    => (int) ( $data['source_bucket_id'] ?? 0 ),
			'target_bucket_id'    => (int) ( $data['target_bucket_id'] ?? 0 ),
			'source_bucket_code'  => sanitize_text_field( $data['source_bucket_code'] ?? '' ),
			'target_bucket_code'  => sanitize_text_field( $data['target_bucket_code'] ?? '' ),
			'notes'               => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);

		$format = array( '%d', '%s', '%d', '%d', '%d', '%f', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

		$wpdb->insert( $this->get_table_name(), $record, $format );

		return (int) $wpdb->insert_id;
	}

	public function find( int $item_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $item_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_transfer( int $transfer_id ): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE transfer_id = %d ORDER BY line_number ASC',
				$transfer_id
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	public function get_for_transfer_uuid( string $transfer_uuid ): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE transfer_uuid = %s ORDER BY line_number ASC',
				$transfer_uuid
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	public function next_line_number( int $transfer_id ): int {
		global $wpdb;

		$max_line = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(line_number) FROM ' . $this->get_table_name() . ' WHERE transfer_id = %d',
				$transfer_id
			)
		);

		return ( (int) $max_line ) + 1;
	}

	public function update_status( int $item_id, string $status, array $extra_data = array() ): bool {
		global $wpdb;

		$record = array(
			'line_status' => sanitize_key( $status ),
			'updated_at'  => current_time( 'mysql' ),
		);

		// Add quantity fields for status transitions
		if ( isset( $extra_data['dispatched_quantity'] ) ) {
			$record['dispatched_quantity'] = number_format( (float) $extra_data['dispatched_quantity'], 4, '.', '' );
		}

		if ( isset( $extra_data['received_quantity'] ) ) {
			$record['received_quantity'] = number_format( (float) $extra_data['received_quantity'], 4, '.', '' );
		}

		if ( isset( $extra_data['variance_quantity'] ) ) {
			$record['variance_quantity'] = number_format( (float) $extra_data['variance_quantity'], 4, '.', '' );
		}

		// Record movement IDs
		if ( 'dispatched' === $status && isset( $extra_data['dispatch_movement_id'] ) ) {
			$record['dispatch_movement_id'] = (int) $extra_data['dispatch_movement_id'];
		}

		if ( 'received' === $status && isset( $extra_data['receipt_movement_id'] ) ) {
			$record['receipt_movement_id'] = (int) $extra_data['receipt_movement_id'];
		}

		if ( isset( $extra_data['variance_movement_id'] ) ) {
			$record['variance_movement_id'] = (int) $extra_data['variance_movement_id'];
		}

		$result = $wpdb->update(
			$this->get_table_name(),
			$record,
			array( 'id' => $item_id ),
			null,
			array( '%d' )
		);

		return false !== $result;
	}

	public function update( int $item_id, array $data ): bool {
		global $wpdb;

		$record = array(
			'updated_at' => current_time( 'mysql' ),
		);

		if ( isset( $data['requested_quantity'] ) ) {
			$record['requested_quantity'] = number_format( (float) $data['requested_quantity'], 4, '.', '' );
		}

		if ( isset( $data['source_bucket_id'] ) ) {
			$record['source_bucket_id'] = (int) $data['source_bucket_id'];
		}

		if ( isset( $data['target_bucket_id'] ) ) {
			$record['target_bucket_id'] = (int) $data['target_bucket_id'];
		}

		if ( isset( $data['notes'] ) ) {
			$record['notes'] = sanitize_textarea_field( $data['notes'] );
		}

		$result = $wpdb->update(
			$this->get_table_name(),
			$record,
			array( 'id' => $item_id ),
			null,
			array( '%d' )
		);

		return false !== $result;
	}

	public function delete( int $item_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			$this->get_table_name(),
			array( 'id' => $item_id ),
			array( '%d' )
		);
	}
}
