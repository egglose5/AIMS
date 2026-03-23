<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Sync_Action_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_sync_actions';
	}

	public function save( array $data, int $action_id = 0 ): int {
		global $wpdb;

		$record = array(
			'run_id'             => (int) ( $data['run_id'] ?? 0 ),
			'external_record_id' => sanitize_text_field( $data['external_record_id'] ?? '' ),
			'action_type'        => sanitize_key( $data['action_type'] ?? '' ),
			'entity_type'        => sanitize_key( $data['entity_type'] ?? '' ),
			'entity_id'          => (int) ( $data['entity_id'] ?? 0 ),
			'status'             => sanitize_key( $data['status'] ?? 'success' ),
			'quantity_delta'     => $this->normalize_quantity_delta( $data['quantity_delta'] ?? 0 ),
			'message'            => sanitize_textarea_field( $data['message'] ?? '' ),
			'occurred_at'        => $this->normalize_datetime( $data['occurred_at'] ?? current_time( 'mysql' ) ),
		);

		if ( $action_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $action_id ) );
			return $action_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function get_for_run( int $run_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE run_id = %d ORDER BY occurred_at ASC, id ASC',
				$run_id
			),
			ARRAY_A
		);
	}

	public function get_for_entity( string $entity_type, int $entity_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE entity_type = %s AND entity_id = %d ORDER BY occurred_at ASC, id ASC',
				sanitize_key( $entity_type ),
				$entity_id
			),
			ARRAY_A
		);
	}

	public function find_by_external_record_id( string $external_record_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE external_record_id = %s ORDER BY id DESC LIMIT 1',
				sanitize_text_field( $external_record_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function normalize_quantity_delta( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		return round( (float) $value, 4 );
	}

	private function normalize_datetime( $value ): string {
		if ( is_array( $value ) ) {
			return current_time( 'mysql' );
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : current_time( 'mysql' );
	}
}
