<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Public_Event_Update_Repository {
	public const STATUS_DRAFT     = 'draft';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_ARCHIVED  = 'archived';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_public_event_updates';
	}

	public function save( array $data, int $update_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $update_id <= 0 && ! empty( $record['event_id'] ) && '' !== (string) $record['update_slug'] ) {
			$existing = $this->find_by_event_and_slug( (int) $record['event_id'], (string) $record['update_slug'] );
			if ( ! empty( $existing['id'] ) ) {
				$update_id = (int) $existing['id'];
			}
		}

		if ( $update_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $update_id ) );
			return $update_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $update_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d', $update_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_public_updates_for_event( int $event_id, array $args = array() ): array {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return array();
		}

		$statuses = $this->get_public_statuses();
		$sql      = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND public_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params   = array_merge( array( $event_id ), $statuses );

		if ( ! empty( $args['update_type'] ) ) {
			$sql     .= ' AND update_type = %s';
			$params[] = sanitize_key( (string) $args['update_type'] );
		}

		$limit = max( 1, (int) ( $args['limit'] ?? 10 ) );
		$sql  .= ' ORDER BY is_pinned DESC, COALESCE(published_at, last_projected_at, created_at) DESC, id DESC LIMIT %d';
		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		$updates = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$updates[] = $this->project_public_update_row( $row );
			}
		}

		return $updates;
	}

	public function get_public_update_count_for_event( int $event_id ): int {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return 0;
		}

		$statuses = $this->get_public_statuses();
		$count    = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND public_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
				array_merge( array( $event_id ), $statuses )
			)
		);

		return (int) $count;
	}

	private function build_record( array $data ): array {
		return array(
			'event_id'               => (int) ( $data['event_id'] ?? 0 ),
			'update_slug'            => sanitize_title( (string) ( $data['update_slug'] ?? '' ) ),
			'update_type'            => $this->normalize_update_type( (string) ( $data['update_type'] ?? 'update' ) ),
			'update_title'           => sanitize_text_field( (string) ( $data['update_title'] ?? '' ) ),
			'update_summary'         => isset( $data['update_summary'] ) ? wp_kses_post( $data['update_summary'] ) : '',
			'update_body'            => isset( $data['update_body'] ) ? wp_kses_post( $data['update_body'] ) : '',
			'public_status'          => $this->normalize_public_status( (string) ( $data['public_status'] ?? self::STATUS_PUBLISHED ) ),
			'is_pinned'              => ! empty( $data['is_pinned'] ) ? 1 : 0,
			'hero_image_reference'   => sanitize_text_field( (string) ( $data['hero_image_reference'] ?? '' ) ),
			'source_label'           => sanitize_text_field( (string) ( $data['source_label'] ?? '' ) ),
			'source_reference'       => sanitize_text_field( (string) ( $data['source_reference'] ?? '' ) ),
			'published_at'           => $this->normalize_datetime( $data['published_at'] ?? null ),
			'last_projected_at'      => $this->normalize_datetime( $data['last_projected_at'] ?? current_time( 'mysql' ) ),
			'updated_at'             => current_time( 'mysql' ),
		);
	}

	private function find_by_event_and_slug( int $event_id, string $update_slug ): ?array {
		global $wpdb;

		if ( $event_id <= 0 || '' === $update_slug ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND update_slug = %s LIMIT 1',
				$event_id,
				sanitize_title( $update_slug )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function project_public_update_row( array $row ): array {
		$published_at = $this->normalize_datetime( $row['published_at'] ?? $row['last_projected_at'] ?? $row['created_at'] ?? null );
		$update_type   = $this->normalize_update_type( (string) ( $row['update_type'] ?? 'update' ) );
		$title         = sanitize_text_field( (string) ( $row['update_title'] ?? '' ) );
		$summary       = wp_kses_post( (string) ( $row['update_summary'] ?? '' ) );
		$body          = wp_kses_post( (string) ( $row['update_body'] ?? '' ) );

		return array(
			'update_id'            => (int) ( $row['id'] ?? 0 ),
			'event_id'             => (int) ( $row['event_id'] ?? 0 ),
			'update_slug'          => sanitize_title( (string) ( $row['update_slug'] ?? '' ) ),
			'update_type'          => $update_type,
			'update_type_label'    => $this->format_update_type_label( $update_type ),
			'update_title'         => '' !== $title ? $title : $this->format_update_type_label( $update_type ),
			'update_summary'       => $summary,
			'update_body'          => $body,
			'public_status'        => $this->normalize_public_status( (string) ( $row['public_status'] ?? self::STATUS_PUBLISHED ) ),
			'is_pinned'            => ! empty( $row['is_pinned'] ) ? 1 : 0,
			'hero_image_reference' => sanitize_text_field( (string) ( $row['hero_image_reference'] ?? '' ) ),
			'source_label'         => sanitize_text_field( (string) ( $row['source_label'] ?? '' ) ),
			'source_reference'     => sanitize_text_field( (string) ( $row['source_reference'] ?? '' ) ),
			'published_at'         => $published_at,
			'published_at_label'    => $this->format_date_time_label( $published_at ),
		);
	}

	private function get_public_statuses(): array {
		return array( self::STATUS_PUBLISHED, 'public', 'active', 'live' );
	}

	private function normalize_public_status( string $status ): string {
		$status = sanitize_key( $status );

		switch ( $status ) {
			case 'public':
			case 'active':
			case 'live':
				return self::STATUS_PUBLISHED;
			case 'hidden':
				return self::STATUS_DRAFT;
			case self::STATUS_DRAFT:
			case self::STATUS_PUBLISHED:
			case self::STATUS_ARCHIVED:
				return $status;
			default:
				return self::STATUS_PUBLISHED;
		}
	}

	private function normalize_update_type( string $type ): string {
		$type = sanitize_key( $type );
		$allowed = array(
			'update',
			'announcement',
			'alert',
			'schedule',
			'reminder',
			'photo',
			'note',
		);

		return in_array( $type, $allowed, true ) ? $type : 'update';
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}

	private function format_update_type_label( string $type ): string {
		switch ( $this->normalize_update_type( $type ) ) {
			case 'announcement':
				return 'Announcement';
			case 'alert':
				return 'Alert';
			case 'schedule':
				return 'Schedule';
			case 'reminder':
				return 'Reminder';
			case 'photo':
				return 'Photo';
			case 'note':
				return 'Note';
			default:
				return 'Update';
		}
	}

	private function format_date_time_label( ?string $datetime ): string {
		if ( '' === (string) $datetime ) {
			return '';
		}

		$time = strtotime( (string) $datetime );
		if ( ! $time ) {
			return sanitize_text_field( (string) $datetime );
		}

		return gmdate( 'F j, Y g:i A', $time );
	}
}
