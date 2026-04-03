<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Bucket_Material_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_bucket_materials';
	}

	public function get_for_event_bucket( int $event_id, int $physical_bucket_id ): array {
		global $wpdb;

		if ( $event_id <= 0 || $physical_bucket_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND physical_bucket_id = %d ORDER BY sort_order ASC, label ASC, id ASC',
				$event_id,
				$physical_bucket_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_row' ), $rows );
	}

	public function save( array $data, int $material_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $material_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $material_id ),
				array( '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			return $material_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function delete_for_event_bucket( int $event_id, int $physical_bucket_id ): bool {
		global $wpdb;

		if ( $event_id <= 0 || $physical_bucket_id <= 0 ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND physical_bucket_id = %d',
				$event_id,
				$physical_bucket_id
			)
		);

		return true;
	}

	private function build_record( array $data ): array {
		$label = sanitize_text_field( (string) ( $data['label'] ?? '' ) );
		$key   = sanitize_key( (string) ( $data['material_key'] ?? '' ) );

		if ( '' === $key ) {
			$key = sanitize_key( $label );
		}

		return array(
			'event_id'           => max( 0, (int) ( $data['event_id'] ?? 0 ) ),
			'physical_bucket_id' => max( 0, (int) ( $data['physical_bucket_id'] ?? 0 ) ),
			'material_key'       => $key,
			'label'              => $label,
			'quantity'           => round( (float) ( $data['quantity'] ?? 0 ), 4 ),
			'unit'               => sanitize_text_field( (string) ( $data['unit'] ?? '' ) ),
			'is_required'        => ! empty( $data['is_required'] ) ? 1 : 0,
			'is_consumable'      => ! empty( $data['is_consumable'] ) ? 1 : 0,
			'packed_status'      => sanitize_key( (string) ( $data['packed_status'] ?? 'planned' ) ),
			'notes'              => isset( $data['notes'] ) ? wp_kses_post( (string) $data['notes'] ) : '',
			'sort_order'         => (int) ( $data['sort_order'] ?? 0 ),
			'updated_at'         => current_time( 'mysql' ),
		);
	}

	private function normalize_row( array $row ): array {
		return array(
			'id'                 => (int) ( $row['id'] ?? 0 ),
			'event_id'           => (int) ( $row['event_id'] ?? 0 ),
			'physical_bucket_id' => (int) ( $row['physical_bucket_id'] ?? 0 ),
			'material_key'       => sanitize_key( (string) ( $row['material_key'] ?? '' ) ),
			'label'              => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			'quantity'           => (float) ( $row['quantity'] ?? 0 ),
			'unit'               => sanitize_text_field( (string) ( $row['unit'] ?? '' ) ),
			'is_required'        => ! empty( $row['is_required'] ),
			'is_consumable'      => ! empty( $row['is_consumable'] ),
			'packed_status'      => sanitize_key( (string) ( $row['packed_status'] ?? '' ) ),
			'notes'              => isset( $row['notes'] ) ? wp_kses_post( (string) $row['notes'] ) : '',
			'sort_order'         => (int) ( $row['sort_order'] ?? 0 ),
			'created_at'         => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			'updated_at'         => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
		);
	}
}
