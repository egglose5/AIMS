<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Event_Checkin_Media_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendor_event_checkin_media';
	}

	public function save( array $data, int $media_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $media_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $media_id ) );
			return $media_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $media_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $media_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_checkin( int $checkin_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE checkin_id = %d ORDER BY is_primary DESC, sort_order ASC, id ASC',
				$checkin_id
			),
			ARRAY_A
		);
	}

	public function get_for_public_event_update( int $public_event_update_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE public_event_update_id = %d ORDER BY is_primary DESC, sort_order ASC, id ASC',
				$public_event_update_id
			),
			ARRAY_A
		);
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY uploaded_at DESC, id DESC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function attach_to_public_event_update( int $media_id, int $public_event_update_id ): bool {
		global $wpdb;

		if ( $media_id <= 0 || $public_event_update_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'public_event_update_id' => $public_event_update_id,
				'visibility_status'      => 'public',
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'id' => $media_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_primary( int $media_id ): bool {
		global $wpdb;

		if ( $media_id <= 0 ) {
			return false;
		}

		$media = $this->find( $media_id );
		if ( ! is_array( $media ) ) {
			return false;
		}

		$checkin_id = (int) ( $media['checkin_id'] ?? 0 );
		$public_event_update_id = (int) ( $media['public_event_update_id'] ?? 0 );

		if ( $checkin_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $this->get_table_name() . ' SET is_primary = 0, updated_at = %s WHERE checkin_id = %d',
					current_time( 'mysql' ),
					$checkin_id
				)
			);
		}

		if ( $public_event_update_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $this->get_table_name() . ' SET is_primary = 0, updated_at = %s WHERE public_event_update_id = %d',
					current_time( 'mysql' ),
					$public_event_update_id
				)
			);
		}

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'is_primary' => 1,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $media_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	private function build_record( array $data ): array {
		return array(
			'checkin_id'             => (int) ( $data['checkin_id'] ?? 0 ),
			'public_event_update_id' => (int) ( $data['public_event_update_id'] ?? 0 ),
			'event_id'               => (int) ( $data['event_id'] ?? 0 ),
			'vendor_id'              => (int) ( $data['vendor_id'] ?? 0 ),
			'media_type'             => sanitize_key( $data['media_type'] ?? 'photo' ),
			'media_source'           => sanitize_key( $data['media_source'] ?? 'mobile_upload' ),
			'media_reference'        => sanitize_text_field( $data['media_reference'] ?? '' ),
			'media_url'              => esc_url_raw( $data['media_url'] ?? '' ),
			'attachment_id'          => (int) ( $data['attachment_id'] ?? 0 ),
			'caption'                => isset( $data['caption'] ) ? wp_kses_post( $data['caption'] ) : '',
			'alt_text'               => sanitize_text_field( $data['alt_text'] ?? '' ),
			'visibility_status'      => sanitize_key( $data['visibility_status'] ?? 'internal' ),
			'is_primary'             => ! empty( $data['is_primary'] ) ? 1 : 0,
			'sort_order'             => (int) ( $data['sort_order'] ?? 0 ),
			'uploaded_by'            => (int) ( $data['uploaded_by'] ?? get_current_user_id() ),
			'uploaded_at'            => $this->normalize_datetime( $data['uploaded_at'] ?? current_time( 'mysql' ) ),
			'updated_at'             => current_time( 'mysql' ),
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
