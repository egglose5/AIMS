<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Public_Event_Projection_Service {
	private const STATUS_DRAFT     = 'draft';
	private const STATUS_PUBLISHED = 'published';
	private const STATUS_ARCHIVED  = 'archived';

	private $events;
	private $updates;

	public function __construct( $events, $updates = null ) {
		$this->events = $events;
		$this->updates = $updates ?: new AIMS_Public_Event_Update_Repository();
	}

	public function refresh_event_projection( int $event_id, array $overrides = array() ) {
		$event = $this->find_event( $event_id );
		if ( empty( $event ) ) {
			return new WP_Error( 'aims_unknown_event', 'Public event projection requires an existing event.' );
		}

		$current = $this->find_projection_by_event_id( $event_id );
		$record  = $this->build_projection_record( $event, $current, $overrides );

		return $this->save_projection_record( $record, (int) ( $current['id'] ?? 0 ) );
	}

	public function refresh_all_event_projections( array $overrides_by_event = array() ): array {
		$results = array();

		foreach ( $this->load_events() as $event ) {
			if ( ! is_array( $event ) || empty( $event['id'] ) ) {
				continue;
			}

			$event_id             = (int) $event['id'];
			$results[ $event_id ] = $this->refresh_event_projection(
				$event_id,
				(array) ( $overrides_by_event[ $event_id ] ?? array() )
			);
		}

		return $results;
	}

	public function get_public_event_updates( int $event_id, array $args = array() ): array {
		if ( $event_id <= 0 || ! is_object( $this->updates ) || ! method_exists( $this->updates, 'get_published_for_event' ) ) {
			return array();
		}

		$rows = array();
		foreach ( (array) $this->updates->get_published_for_event( $event_id ) as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		$limit = max( 1, (int) ( $args['limit'] ?? 10 ) );
		$rows  = array_slice( $rows, 0, $limit );

		return array_map( array( $this, 'project_public_event_update_row' ), $rows );
	}

	public function save_public_event_update( array $payload ): array {
		$event_id = max( 0, (int) ( $payload['event_id'] ?? 0 ) );
		$event    = $this->find_event( $event_id );

		if ( empty( $event ) ) {
			return array(
				'success' => false,
				'message' => 'A valid event is required before saving a public update.',
			);
		}

		$record = $this->build_public_event_update_record( $event, $payload );
		$update_id = 0;

		if ( is_object( $this->updates ) && method_exists( $this->updates, 'save' ) ) {
			$update_id = (int) $this->updates->save( $record, (int) ( $payload['update_id'] ?? 0 ) );
		}

		if ( $update_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'Public event update could not be saved.',
				'event_id' => $event_id,
			);
		}

		return array(
			'success'   => true,
			'message'   => 'Public event update saved.',
			'event_id'  => $event_id,
			'update_id' => $update_id,
		);
	}

	public function build_projection_record( array $event, array $current = array(), array $overrides = array() ): array {
		$event_id = (int) ( $event['id'] ?? 0 );
		$slug     = $this->resolve_projection_slug( $event, $current, $overrides );

		return array(
			'event_id'                => $event_id,
			'slug'                    => $slug,
			'public_title'            => $this->resolve_public_value( 'public_title', (string) ( $event['event_name'] ?? '' ), $current, $overrides ),
			'public_summary'          => $this->resolve_public_value( 'public_summary', '', $current, $overrides ),
			'venue_name'              => $this->resolve_public_value( 'venue_name', (string) ( $event['location_name'] ?? '' ), $current, $overrides ),
			'city'                    => $this->resolve_public_value( 'city', '', $current, $overrides ),
			'state_region'            => $this->resolve_public_value( 'state_region', '', $current, $overrides ),
			'start_date'              => sanitize_text_field( $event['start_date'] ?? '' ),
			'end_date'                => sanitize_text_field( $event['end_date'] ?? '' ),
			'public_status'           => $this->resolve_public_status( $event, $current, $overrides ),
			'hero_image_reference'    => $this->resolve_public_value( 'hero_image_reference', '', $current, $overrides ),
			'is_featured'             => $this->resolve_featured_flag( $current, $overrides ),
			'sort_date'               => $this->resolve_sort_date( $event, $current, $overrides ),
			'request_intake_enabled'  => $this->resolve_request_intake_enabled( $current, $overrides ),
			'last_projected_at'       => current_time( 'mysql' ),
		);
	}

	private function save_projection_record( array $record, int $projection_id = 0 ) {
		global $wpdb;

		$table = $this->get_projection_table_name();
		$data  = array(
			'event_id'               => (int) $record['event_id'],
			'slug'                   => sanitize_title( $record['slug'] ?? '' ),
			'public_title'           => sanitize_text_field( $record['public_title'] ?? '' ),
			'public_summary'         => isset( $record['public_summary'] ) ? wp_kses_post( $record['public_summary'] ) : '',
			'venue_name'             => sanitize_text_field( $record['venue_name'] ?? '' ),
			'city'                   => sanitize_text_field( $record['city'] ?? '' ),
			'state_region'           => sanitize_text_field( $record['state_region'] ?? '' ),
			'start_date'             => sanitize_text_field( $record['start_date'] ?? '' ),
			'end_date'               => sanitize_text_field( $record['end_date'] ?? '' ),
			'public_status'          => $this->normalize_public_status( (string) ( $record['public_status'] ?? self::STATUS_DRAFT ) ),
			'hero_image_reference'   => sanitize_text_field( $record['hero_image_reference'] ?? '' ),
			'is_featured'            => ! empty( $record['is_featured'] ) ? 1 : 0,
			'sort_date'              => $this->normalize_datetime( $record['sort_date'] ?? null ),
			'request_intake_enabled' => ! empty( $record['request_intake_enabled'] ) ? 1 : 0,
			'last_projected_at'      => $this->normalize_datetime( $record['last_projected_at'] ?? current_time( 'mysql' ) ),
			'updated_at'             => current_time( 'mysql' ),
		);

		if ( $projection_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $projection_id ) );

			return $projection_id;
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );

		return (int) $wpdb->insert_id;
	}

	private function find_event( int $event_id ): ?array {
		if ( $event_id <= 0 ) {
			return null;
		}

		if ( is_object( $this->events ) ) {
			foreach ( array( 'find', 'get', 'get_event' ) as $method ) {
				if ( method_exists( $this->events, $method ) ) {
					$event = $this->events->{$method}( $event_id );
					if ( is_array( $event ) ) {
						return $event;
					}
				}
			}
		}

		foreach ( $this->load_events() as $event ) {
			if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
				return $event;
			}
		}

		return null;
	}

	private function load_events(): array {
		if ( is_object( $this->events ) && method_exists( $this->events, 'all' ) ) {
			return (array) $this->events->all();
		}

		return array();
	}

	private function build_public_event_update_record( array $event, array $payload ): array {
		$event_name = sanitize_text_field( (string) ( $event['event_name'] ?? '' ) );
		$event_code = sanitize_text_field( (string) ( $event['event_code'] ?? '' ) );
		$slug       = sanitize_title( (string) ( $payload['update_slug'] ?? '' ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( (string) ( $payload['public_title'] ?? '' ) );
		}

		if ( '' === $slug ) {
			$slug = '' !== $event_code ? sanitize_title( $event_code ) . '-update' : sanitize_title( $event_name ) . '-update';
		}

		return array(
			'event_id'             => (int) ( $event['id'] ?? 0 ),
			'update_slug'          => $slug,
			'update_type'          => $this->normalize_update_type( (string) ( $payload['update_type'] ?? 'update' ) ),
			'update_title'         => sanitize_text_field( (string) ( $payload['update_title'] ?? '' ) ),
			'update_summary'       => isset( $payload['update_summary'] ) ? wp_kses_post( $payload['update_summary'] ) : '',
			'update_body'          => isset( $payload['update_body'] ) ? wp_kses_post( $payload['update_body'] ) : '',
			'public_status'        => $this->normalize_update_status( (string) ( $payload['public_status'] ?? self::STATUS_PUBLISHED ) ),
			'is_pinned'            => ! empty( $payload['is_pinned'] ) ? 1 : 0,
			'hero_image_reference' => sanitize_text_field( (string) ( $payload['hero_image_reference'] ?? '' ) ),
			'source_label'         => sanitize_text_field( (string) ( $payload['source_label'] ?? '' ) ),
			'source_reference'     => sanitize_text_field( (string) ( $payload['source_reference'] ?? '' ) ),
			'published_at'         => $this->normalize_datetime( $payload['published_at'] ?? null ),
			'last_projected_at'    => current_time( 'mysql' ),
		);
	}

	private function project_public_event_update_row( array $row ): array {
		$published_at = $this->normalize_datetime( $row['published_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? null );
		$title        = sanitize_text_field( (string) ( $row['headline'] ?? '' ) );
		$summary      = isset( $row['excerpt'] ) ? wp_kses_post( $row['excerpt'] ) : '';
		$body         = isset( $row['post_body'] ) ? wp_kses_post( $row['post_body'] ) : '';
		$update_type  = 'update';

		return array(
			'update_id'           => (int) ( $row['id'] ?? 0 ),
			'event_id'            => (int) ( $row['event_id'] ?? 0 ),
			'update_slug'         => $this->resolve_public_update_slug( $row, $title ),
			'update_type'         => $update_type,
			'update_type_label'   => $this->format_update_type_label( $update_type ),
			'update_title'        => '' !== $title ? $title : $this->format_update_type_label( $update_type ),
			'update_summary'      => $summary,
			'update_body'         => $body,
			'public_status'       => $this->normalize_update_status( (string) ( $row['post_status'] ?? self::STATUS_PUBLISHED ) ),
			'is_pinned'           => 0,
			'hero_image_reference' => sanitize_text_field( (string) ( $row['cover_media_reference'] ?? '' ) ),
			'published_at'        => $published_at,
			'published_at_label'   => $this->format_date_time_label( $published_at ),
		);
	}

	private function find_projection_by_event_id( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_projection_table_name() . ' WHERE event_id = %d',
				$event_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function get_projection_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_public_event_catalog';
	}

	private function resolve_projection_slug( array $event, array $current, array $overrides ): string {
		if ( isset( $overrides['slug'] ) ) {
			return sanitize_title( (string) $overrides['slug'] );
		}

		if ( ! empty( $current['slug'] ) ) {
			return sanitize_title( (string) $current['slug'] );
		}

		if ( ! empty( $event['event_code'] ) ) {
			return sanitize_title( (string) $event['event_code'] );
		}

		return sanitize_title( (string) ( $event['event_name'] ?? '' ) );
	}

	private function resolve_public_value( string $key, string $derived_default, array $current, array $overrides ): string {
		if ( array_key_exists( $key, $overrides ) ) {
			return sanitize_text_field( (string) $overrides[ $key ] );
		}

		if ( ! empty( $current[ $key ] ) ) {
			return sanitize_text_field( (string) $current[ $key ] );
		}

		return sanitize_text_field( $derived_default );
	}

	private function resolve_public_status( array $event, array $current, array $overrides ): string {
		if ( isset( $overrides['public_status'] ) ) {
			return $this->normalize_public_status( (string) $overrides['public_status'] );
		}

		if ( ! empty( $current['public_status'] ) ) {
			return $this->normalize_public_status( (string) $current['public_status'] );
		}

		$event_status = sanitize_key( (string) ( $event['status'] ?? 'draft' ) );

		return in_array( $event_status, array( self::STATUS_PUBLISHED, 'public', 'active', 'live' ), true ) ? self::STATUS_PUBLISHED : self::STATUS_DRAFT;
	}

	private function resolve_featured_flag( array $current, array $overrides ): int {
		if ( array_key_exists( 'is_featured', $overrides ) ) {
			return ! empty( $overrides['is_featured'] ) ? 1 : 0;
		}

		return ! empty( $current['is_featured'] ) ? 1 : 0;
	}

	private function resolve_sort_date( array $event, array $current, array $overrides ): ?string {
		if ( array_key_exists( 'sort_date', $overrides ) ) {
			return $this->normalize_datetime( $overrides['sort_date'] );
		}

		if ( ! empty( $current['sort_date'] ) ) {
			return $this->normalize_datetime( $current['sort_date'] );
		}

		$start_date = sanitize_text_field( $event['start_date'] ?? '' );

		return '' !== $start_date ? $start_date . ' 00:00:00' : null;
	}

	private function resolve_request_intake_enabled( array $current, array $overrides ): int {
		if ( array_key_exists( 'request_intake_enabled', $overrides ) ) {
			return ! empty( $overrides['request_intake_enabled'] ) ? 1 : 0;
		}

		return ! empty( $current['request_intake_enabled'] ) ? 1 : 0;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
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
				return self::STATUS_DRAFT;
		}
	}

	private function normalize_update_status( string $status ): string {
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

	private function resolve_public_update_slug( array $row, string $title ): string {
		if ( ! empty( $row['update_slug'] ) ) {
			return sanitize_title( (string) $row['update_slug'] );
		}

		if ( '' !== $title ) {
			return sanitize_title( $title );
		}

		return 'event-update-' . (int) ( $row['id'] ?? 0 );
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
