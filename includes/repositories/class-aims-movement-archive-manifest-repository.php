<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Movement_Archive_Manifest_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_movement_archive_manifests';
	}

	public function create( array $data ): int {
		global $wpdb;

		$record = array(
			'archive_key'        => sanitize_text_field( (string) ( $data['archive_key'] ?? wp_generate_uuid4() ) ),
			'movement_batch_id'  => (int) ( $data['movement_batch_id'] ?? 0 ),
			'archive_status'     => sanitize_key( (string) ( $data['archive_status'] ?? 'prepared' ) ),
			'storage_backend'    => sanitize_key( (string) ( $data['storage_backend'] ?? 'local_wp' ) ),
			'archive_format'     => sanitize_key( (string) ( $data['archive_format'] ?? 'json' ) ),
			'compression_codec'  => sanitize_key( (string) ( $data['compression_codec'] ?? 'gzip' ) ),
			'storage_path'       => sanitize_text_field( (string) ( $data['storage_path'] ?? '' ) ),
			'payload_checksum'   => sanitize_text_field( (string) ( $data['payload_checksum'] ?? '' ) ),
			'line_count'         => (int) ( $data['line_count'] ?? 0 ),
			'payload_bytes'      => (int) ( $data['payload_bytes'] ?? 0 ),
			'manifest_json'      => isset( $data['manifest_json'] ) ? wp_json_encode( $data['manifest_json'] ) : null,
			'payload_json'       => isset( $data['payload_json'] ) ? wp_json_encode( $data['payload_json'] ) : null,
			'exported_at'        => $data['exported_at'] ?? null,
			'archived_at'        => $data['archived_at'] ?? null,
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find_for_batch( int $movement_batch_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE movement_batch_id = %d', $movement_batch_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
