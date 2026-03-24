<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Public_Event_Catalog_Repository {
	public const STATUS_DRAFT = 'draft';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_ARCHIVED = 'archived';

	public function get_public_events( array $filters = array() ): array {
		$rows = $this->get_public_catalog_rows( $filters );

		return array_map( array( $this, 'project_public_event_row' ), $rows );
	}

	public function find_public_event( int $event_id = 0, string $slug = '' ): ?array {
		$row = null;

		if ( $event_id > 0 ) {
			$row = $this->find_by_event_id( $event_id );
		} elseif ( '' !== $slug ) {
			$row = $this->find_by_slug( $slug );
		}

		if ( ! is_array( $row ) || ! $this->is_row_publicly_visible( $row ) ) {
			return null;
		}

		return $this->project_public_event_row( $row );
	}

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_public_event_catalog';
	}

	public function save( array $data, int $projection_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $projection_id <= 0 && ! empty( $record['event_id'] ) ) {
			$existing = $this->find_by_event_id( (int) $record['event_id'] );
			if ( ! empty( $existing['id'] ) ) {
				$projection_id = (int) $existing['id'];
			}
		}

		if ( $projection_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $projection_id ) );
			return $projection_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function upsert_for_event( int $event_id, array $data ): int {
		$data['event_id'] = $event_id;

		return $this->save( $data );
	}

	public function find( int $projection_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$projection_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_event_id( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d LIMIT 1',
				$event_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function find_by_slug( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE slug = %s LIMIT 1',
				sanitize_title( $slug )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_public_catalog_list( array $args = array() ): array {
		global $wpdb;

		$status               = $this->normalize_status( (string) ( $args['public_status'] ?? self::STATUS_PUBLISHED ) );
		$intake_enabled_only  = ! empty( $args['request_intake_enabled_only'] );
		$limit                = max( 1, (int) ( $args['limit'] ?? 100 ) );
		$offset               = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$statuses = $this->get_query_statuses_for_canonical_status( $status );
		$sql      = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE public_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params   = $statuses;

		if ( $intake_enabled_only ) {
			$sql      .= ' AND request_intake_enabled = %d';
			$params[] = 1;
		}

		$sql      .= ' ORDER BY COALESCE(sort_date, CONCAT(start_date, " 00:00:00")) ASC, start_date ASC, id ASC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);
	}

	public function get_featured_list( array $args = array() ): array {
		global $wpdb;

		$status = $this->normalize_status( (string) ( $args['public_status'] ?? self::STATUS_PUBLISHED ) );
		$limit  = max( 1, (int) ( $args['limit'] ?? 10 ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE public_status = %s AND is_featured = %d ORDER BY COALESCE(sort_date, CONCAT(start_date, " 00:00:00")) ASC, start_date ASC, id ASC LIMIT %d',
				$status,
				1,
				$limit
			),
			ARRAY_A
		);
	}

	public function get_single_by_event_id( int $event_id ): ?array {
		$row = $this->find_by_event_id( $event_id );

		return is_array( $row ) ? $row : null;
	}

	public function get_single_by_slug( string $slug ): ?array {
		$row = $this->find_by_slug( $slug );

		return is_array( $row ) ? $row : null;
	}

	private function get_public_catalog_rows( array $filters = array() ): array {
		global $wpdb;

		$allowed_statuses     = $this->get_public_projection_statuses();
		$status_placeholders  = implode( ', ', array_fill( 0, count( $allowed_statuses ), '%s' ) );
		$sql                  = 'SELECT * FROM ' . $this->get_table_name() . ' WHERE public_status IN (' . $status_placeholders . ')';
		$params               = $allowed_statuses;

		if ( ! empty( $filters['event_id'] ) ) {
			$sql      .= ' AND event_id = %d';
			$params[] = (int) $filters['event_id'];
		}

		if ( ! empty( $filters['slug'] ) ) {
			$sql      .= ' AND slug = %s';
			$params[] = sanitize_title( (string) $filters['slug'] );
		}

		if ( ! empty( $filters['request_intake_enabled_only'] ) ) {
			$sql      .= ' AND request_intake_enabled = %d';
			$params[] = 1;
		}

		$limit = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 100;
		$sql  .= ' ORDER BY COALESCE(sort_date, CONCAT(start_date, " 00:00:00")) ASC, start_date ASC, id ASC LIMIT %d';
		$params[] = $limit;

		if ( isset( $filters['offset'] ) ) {
			$sql      .= ' OFFSET %d';
			$params[] = max( 0, (int) $filters['offset'] );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	private function build_record( array $data ): array {
		return array(
			'event_id'               => (int) ( $data['event_id'] ?? 0 ),
			'slug'                   => sanitize_title( $data['slug'] ?? '' ),
			'public_title'           => sanitize_text_field( $data['public_title'] ?? '' ),
			'public_summary'         => isset( $data['public_summary'] ) ? wp_kses_post( $data['public_summary'] ) : '',
			'venue_name'             => sanitize_text_field( $data['venue_name'] ?? '' ),
			'city'                   => sanitize_text_field( $data['city'] ?? '' ),
			'state_region'           => sanitize_text_field( $data['state_region'] ?? '' ),
			'start_date'             => sanitize_text_field( $data['start_date'] ?? '' ),
			'end_date'               => sanitize_text_field( $data['end_date'] ?? '' ),
			'public_status'          => $this->normalize_status( (string) ( $data['public_status'] ?? self::STATUS_DRAFT ) ),
			'hero_image_reference'   => sanitize_text_field( $data['hero_image_reference'] ?? '' ),
			'is_featured'            => ! empty( $data['is_featured'] ) ? 1 : 0,
			'sort_date'              => $this->normalize_datetime( $data['sort_date'] ?? null ),
			'request_intake_enabled' => ! empty( $data['request_intake_enabled'] ) ? 1 : 0,
			'last_projected_at'      => $this->normalize_datetime( $data['last_projected_at'] ?? current_time( 'mysql' ) ),
			'updated_at'             => current_time( 'mysql' ),
		);
	}

	private function normalize_status( string $status ): string {
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
				return self::STATUS_DRAFT;
		}
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}

	private function project_public_event_row( array $row ): array {
		$public_title = sanitize_text_field( (string) ( $row['public_title'] ?? '' ) );
		$slug         = sanitize_title( (string) ( $row['slug'] ?? '' ) );
		$venue_name   = sanitize_text_field( (string) ( $row['venue_name'] ?? '' ) );
		$event_id     = (int) ( $row['event_id'] ?? 0 );
		$status       = $this->normalize_status( (string) ( $row['public_status'] ?? '' ) );

		return array(
			'event_id'               => $event_id,
			'event_code'             => '',
			'event_slug'             => $slug,
			'event_name'             => $public_title,
			'status'                 => $status,
			'public_status'          => $status,
			'start_date'             => sanitize_text_field( (string) ( $row['start_date'] ?? '' ) ),
			'end_date'               => sanitize_text_field( (string) ( $row['end_date'] ?? '' ) ),
			'location_name'          => $venue_name,
			'public_summary'         => wp_kses_post( (string) ( $row['public_summary'] ?? '' ) ),
			'hero_image_reference'   => sanitize_text_field( (string) ( $row['hero_image_reference'] ?? '' ) ),
			'is_featured'            => ! empty( $row['is_featured'] ) ? 1 : 0,
			'request_intake_enabled' => ! empty( $row['request_intake_enabled'] ) ? 1 : 0,
			'date_range_label'       => $this->format_date_range(
				(string) ( $row['start_date'] ?? '' ),
				(string) ( $row['end_date'] ?? '' )
			),
		);
	}

	private function is_row_publicly_visible( array $row ): bool {
		return in_array(
			sanitize_key( (string) ( $row['public_status'] ?? '' ) ),
			$this->get_public_projection_statuses(),
			true
		);
	}

	private function get_public_projection_statuses(): array {
		return array( self::STATUS_PUBLISHED, 'public', 'active', 'live' );
	}

	private function get_query_statuses_for_canonical_status( string $status ): array {
		if ( self::STATUS_PUBLISHED === $status ) {
			return $this->get_public_projection_statuses();
		}

		return array( $status );
	}

	private function format_date_range( string $start_date, string $end_date ): string {
		$start = strtotime( $start_date );
		$end   = strtotime( $end_date );

		if ( ! $start && ! $end ) {
			return '';
		}

		if ( $start && $end && gmdate( 'Y-m-d', $start ) === gmdate( 'Y-m-d', $end ) ) {
			return gmdate( 'F j, Y', $start );
		}

		if ( $start && $end ) {
			return gmdate( 'F j, Y', $start ) . ' - ' . gmdate( 'F j, Y', $end );
		}

		return $start ? gmdate( 'F j, Y', $start ) : gmdate( 'F j, Y', $end );
	}
}
