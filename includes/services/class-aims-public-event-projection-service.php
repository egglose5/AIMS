<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Public_Event_Projection_Service {
	private const STATUS_DRAFT     = 'draft';
	private const STATUS_PUBLISHED = 'published';
	private const STATUS_ARCHIVED  = 'archived';

	private $events;

	public function __construct( $events ) {
		$this->events = $events;
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
}
