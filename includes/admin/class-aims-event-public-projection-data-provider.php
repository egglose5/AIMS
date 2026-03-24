<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Public_Projection_Data_Provider {
	private const STATUS_DRAFT     = 'draft';
	private const STATUS_PUBLISHED = 'published';
	private const STATUS_ARCHIVED  = 'archived';

	private $events;

	public function __construct( AIMS_Event_Repository $events = null ) {
		$this->events = $events ?: new AIMS_Event_Repository();
	}

	public function get_rows(): array {
		$events = method_exists( $this->events, 'all' ) ? (array) $this->events->all() : array();
		$rows   = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$catalog_row = $this->get_catalog_row( (int) ( $event['id'] ?? 0 ) );
			$rows[]      = $this->build_row( $event, $catalog_row );
		}

		return $rows;
	}

	public function get_display_expectations(): array {
		return array(
			'Public visibility stays intentional. Draft is the safe default and nothing is public unless a catalog row is explicitly marked visible.',
			'Public catalog and detail views should only show public-safe fields: slug, title, summary, venue, city/state, dates, featured state, hero image reference, and request-intake availability.',
			'Financial fields, vendor/internal notes, Square imports, and inventory ledgers stay out of public projection.',
		);
	}

	public function get_status_options(): array {
		return array(
			self::STATUS_DRAFT => 'Draft',
			self::STATUS_PUBLISHED => 'Published',
			self::STATUS_ARCHIVED => 'Archived',
		);
	}

	public function save_projection( array $payload ): array {
		global $wpdb;

		$event_id = max( 0, (int) ( $payload['event_id'] ?? 0 ) );
		$event    = $this->find_event( $event_id );

		if ( null === $event ) {
			return array(
				'success' => false,
				'message' => 'A valid event is required before saving public projection settings.',
			);
		}

		$existing = $this->get_catalog_row( $event_id );
		$now      = current_time( 'mysql' );
		$record   = $this->build_catalog_record( $event, $payload, $existing, $now );
		$table    = $this->get_catalog_table_name();

		if ( ! empty( $existing ) ) {
			$updated = $wpdb->update(
				$table,
				$record,
				array( 'event_id' => $event_id ),
				array(
					'%d',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%d',
					'%s',
					'%s',
				),
				array( '%d' )
			);

			if ( false === $updated ) {
				return array(
					'success' => false,
					'message' => 'Public projection could not be updated.',
				);
			}

			return array(
				'success' => true,
				'message' => 'Public projection updated.',
			);
		}

		$record['created_at'] = $now;

		$inserted = $wpdb->insert(
			$table,
			$record,
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return array(
				'success' => false,
				'message' => 'Public projection could not be created.',
			);
		}

		return array(
			'success' => true,
			'message' => 'Public projection created.',
		);
	}

	private function find_event( int $event_id ): ?array {
		if ( $event_id <= 0 ) {
			return null;
		}

		foreach ( (array) $this->events->all() as $event ) {
			if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
				return $event;
			}
		}

		return null;
	}

	private function get_catalog_row( int $event_id ): ?array {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_catalog_table_name() . ' WHERE event_id = %d LIMIT 1',
				$event_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function build_row( array $event, ?array $catalog_row ): array {
		$event_name = sanitize_text_field( (string) ( $event['event_name'] ?? '' ) );
		$event_code = sanitize_text_field( (string) ( $event['event_code'] ?? '' ) );
		$catalog_row = is_array( $catalog_row ) ? $catalog_row : array();

		$public_title = sanitize_text_field( (string) ( $catalog_row['public_title'] ?? '' ) );
		$slug         = sanitize_title( (string) ( $catalog_row['slug'] ?? '' ) );
		$venue_name   = sanitize_text_field( (string) ( $catalog_row['venue_name'] ?? '' ) );

		return array(
			'event_id'                => (int) ( $event['id'] ?? 0 ),
			'event_code'              => $event_code,
			'event_name'              => $event_name,
			'preview_event_name'      => '' !== $public_title ? $public_title : $event_name,
			'preview_slug'            => '' !== $slug ? $slug : ( '' !== $event_code ? sanitize_title( $event_code ) : sanitize_title( $event_name ) ),
			'preview_venue_name'      => '' !== $venue_name ? $venue_name : sanitize_text_field( (string) ( $event['location_name'] ?? '' ) ),
			'public_status'           => $this->normalize_public_status( (string) ( $catalog_row['public_status'] ?? self::STATUS_DRAFT ) ),
			'public_summary'          => wp_kses_post( (string) ( $catalog_row['public_summary'] ?? '' ) ),
			'city'                    => sanitize_text_field( (string) ( $catalog_row['city'] ?? '' ) ),
			'state_region'            => sanitize_text_field( (string) ( $catalog_row['state_region'] ?? '' ) ),
			'hero_image_reference'    => sanitize_text_field( (string) ( $catalog_row['hero_image_reference'] ?? '' ) ),
			'is_featured'             => ! empty( $catalog_row['is_featured'] ) ? 1 : 0,
			'request_intake_enabled'  => ! empty( $catalog_row['request_intake_enabled'] ) ? 1 : 0,
			'sort_date'               => sanitize_text_field( (string) ( $catalog_row['sort_date'] ?? '' ) ),
			'last_projected_at'       => sanitize_text_field( (string) ( $catalog_row['last_projected_at'] ?? '' ) ),
			'created_at'              => sanitize_text_field( (string) ( $catalog_row['created_at'] ?? '' ) ),
			'updated_at'              => sanitize_text_field( (string) ( $catalog_row['updated_at'] ?? '' ) ),
			'date_range_label'        => $this->format_date_range(
				(string) ( $event['start_date'] ?? '' ),
				(string) ( $event['end_date'] ?? '' )
			),
			'preview_venue_location'  => '' !== $venue_name ? $venue_name : sanitize_text_field( (string) ( $event['location_name'] ?? '' ) ),
		);
	}

	private function build_catalog_record( array $event, array $payload, ?array $existing, string $now ): array {
		$event_name = sanitize_text_field( (string) ( $event['event_name'] ?? '' ) );
		$event_code = sanitize_text_field( (string) ( $event['event_code'] ?? '' ) );
		$slug       = sanitize_title( (string) ( $payload['slug'] ?? '' ) );
		$title      = sanitize_text_field( (string) ( $payload['public_title'] ?? '' ) );
		$venue      = sanitize_text_field( (string) ( $payload['venue_name'] ?? '' ) );

		if ( '' === $slug ) {
			$slug = '' !== $event_code ? sanitize_title( $event_code ) : sanitize_title( $event_name );
		}

		if ( '' === $title ) {
			$title = $event_name;
		}

		if ( '' === $venue ) {
			$venue = sanitize_text_field( (string) ( $event['location_name'] ?? '' ) );
		}

		$sort_date = sanitize_text_field( (string) ( $existing['sort_date'] ?? '' ) );
		if ( '' === $sort_date ) {
			$sort_date = $this->build_sort_date( (string) ( $event['start_date'] ?? '' ) );
		}

		return array(
			'event_id'               => (int) ( $event['id'] ?? 0 ),
			'slug'                   => $slug,
			'public_title'           => $title,
			'public_summary'         => wp_kses_post( (string) ( $payload['public_summary'] ?? '' ) ),
			'venue_name'             => $venue,
			'city'                   => sanitize_text_field( (string) ( $payload['city'] ?? '' ) ),
			'state_region'           => sanitize_text_field( (string) ( $payload['state_region'] ?? '' ) ),
			'start_date'             => sanitize_text_field( (string) ( $event['start_date'] ?? '' ) ),
			'end_date'               => sanitize_text_field( (string) ( $event['end_date'] ?? '' ) ),
			'public_status'          => $this->normalize_public_status( (string) ( $payload['public_status'] ?? '' ) ),
			'hero_image_reference'   => sanitize_text_field( (string) ( $payload['hero_image_reference'] ?? '' ) ),
			'is_featured'            => ! empty( $payload['is_featured'] ) ? 1 : 0,
			'sort_date'              => $sort_date,
			'request_intake_enabled'  => ! empty( $payload['request_intake_enabled'] ) ? 1 : 0,
			'last_projected_at'      => $now,
			'updated_at'             => $now,
		);
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

	private function build_sort_date( string $start_date ): string {
		$timestamp = strtotime( $start_date );

		if ( ! $timestamp ) {
			return current_time( 'mysql' );
		}

		return gmdate( 'Y-m-d 00:00:00', $timestamp );
	}

	private function get_catalog_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_public_event_catalog';
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
