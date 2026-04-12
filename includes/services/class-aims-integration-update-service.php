<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Integration_Update_Service {
	public const OPTION_UPDATES = 'aims_integration_updates';
	private const MAX_STORED_UPDATES = 500;

	private $low_stock_alert_service;

	public function __construct( AIMS_Low_Stock_Alert_Service $low_stock_alert_service = null ) {
		$this->low_stock_alert_service = $low_stock_alert_service ?: new AIMS_Low_Stock_Alert_Service();
	}

	public function ingest_updates( array $payload, array $context = array() ): array {
		$items   = $this->extract_items( $payload );
		$stored  = $this->get_stored_updates();
		$accepted = 0;
		$skipped  = 0;

		foreach ( $items as $item ) {
			$normalized = $this->normalize_update( $item, $context );
			if ( null === $normalized ) {
				++$skipped;
				continue;
			}

			$stored[ (string) $normalized['dedupe_key'] ] = $normalized;
			++$accepted;
		}

		uasort(
			$stored,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) );
			}
		);

		if ( count( $stored ) > self::MAX_STORED_UPDATES ) {
			$stored = array_slice( $stored, 0, self::MAX_STORED_UPDATES, true );
		}

		$this->save_stored_updates( $stored );

		return array(
			'accepted'       => $accepted,
			'skipped'        => $skipped,
			'total_received' => count( $items ),
			'total_stored'   => count( $stored ),
			'latest_cursor'  => $this->resolve_latest_cursor( $stored ),
		);
	}

	public function get_feed_snapshot( string $since = '', int $limit = 50 ): array {
		$limit      = max( 1, min( 500, $limit ) );
		$normalized_since = $this->normalize_timestamp( $since );
		$updates    = $this->get_updates( $limit, $normalized_since );
		$low_stock  = is_object( $this->low_stock_alert_service ) && method_exists( $this->low_stock_alert_service, 'get_dashboard_snapshot' )
			? (array) $this->low_stock_alert_service->get_dashboard_snapshot( $limit )
			: array();

		return array(
			'generated_at'      => current_time( 'mysql' ),
			'latest_cursor'     => $this->resolve_latest_cursor_from_lists(
				(array) ( $updates['updates'] ?? array() ),
				(array) ( $low_stock['alerts'] ?? array() )
			),
			'since'             => $normalized_since,
			'updates'           => (array) ( $updates['updates'] ?? array() ),
			'updates_count'     => (int) ( $updates['count'] ?? 0 ),
			'low_stock_summary' => array(
				'threshold'          => (int) ( $low_stock['threshold'] ?? 0 ),
				'active_positions'   => (int) ( $low_stock['active_positions'] ?? 0 ),
				'tracked_products'   => (int) ( $low_stock['tracked_products'] ?? 0 ),
				'low_stock_products' => (int) ( $low_stock['low_stock_products'] ?? 0 ),
			),
			'low_stock_alerts'  => (array) ( $low_stock['alerts'] ?? array() ),
		);
	}

	public function get_updates( int $limit = 50, string $since = '' ): array {
		$limit            = max( 1, min( 500, $limit ) );
		$normalized_since = $this->normalize_timestamp( $since );
		$updates          = array_values( $this->get_stored_updates() );

		if ( '' !== $normalized_since ) {
			$updates = array_values(
				array_filter(
					$updates,
					static function ( $row ) use ( $normalized_since ): bool {
						if ( ! is_array( $row ) ) {
							return false;
						}

						return strcmp( (string) ( $row['updated_at'] ?? '' ), $normalized_since ) > 0;
					}
				)
			);
		}

		$updates = array_slice( $updates, 0, $limit );

		return array(
			'updates' => $updates,
			'count'   => count( $updates ),
		);
	}

	private function extract_items( array $payload ): array {
		if ( isset( $payload['updates'] ) && is_array( $payload['updates'] ) ) {
			return $payload['updates'];
		}

		if ( array_key_exists( 'sku', $payload ) || array_key_exists( 'product_id', $payload ) || array_key_exists( 'external_product_id', $payload ) ) {
			return array( $payload );
		}

		return array();
	}

	private function normalize_update( $item, array $context ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$product_id          = (int) ( $item['product_id'] ?? 0 );
		$sku                 = sanitize_text_field( (string) ( $item['sku'] ?? '' ) );
		$external_product_id = sanitize_text_field( (string) ( $item['external_product_id'] ?? '' ) );
		$source              = sanitize_key( (string) ( $item['source'] ?? $context['source'] ?? 'external' ) );
		$updated_at          = $this->normalize_timestamp( (string) ( $item['updated_at'] ?? '' ) );
		$available_quantity  = isset( $item['available_quantity'] ) ? (float) $item['available_quantity'] : null;
		$status              = sanitize_key( (string) ( $item['status'] ?? '' ) );

		if ( $product_id <= 0 && '' === $sku && '' === $external_product_id ) {
			return null;
		}

		if ( '' === $updated_at ) {
			$updated_at = current_time( 'mysql' );
		}

		if ( '' === $status ) {
			if ( null !== $available_quantity && $available_quantity <= 0 ) {
				$status = 'out';
			} elseif ( null !== $available_quantity && $available_quantity <= AIMS_Plugin::get_low_stock_threshold() ) {
				$status = 'low';
			} else {
				$status = 'ok';
			}
		}

		$dedupe_parts = array(
			$source,
			(string) $product_id,
			$sku,
			$external_product_id,
			$updated_at,
			null === $available_quantity ? '' : (string) $available_quantity,
			(string) ( $item['event_id'] ?? '' ),
		);

		$dedupe_key = md5( implode( '|', $dedupe_parts ) );

		return array(
			'dedupe_key'           => $dedupe_key,
			'event_id'             => sanitize_text_field( (string) ( $item['event_id'] ?? '' ) ),
			'product_id'           => $product_id,
			'sku'                  => $sku,
			'external_product_id'  => $external_product_id,
			'available_quantity'   => null === $available_quantity ? null : round( $available_quantity, 4 ),
			'total_quantity'       => isset( $item['total_quantity'] ) ? round( (float) $item['total_quantity'], 4 ) : null,
			'reserved_quantity'    => isset( $item['reserved_quantity'] ) ? round( (float) $item['reserved_quantity'], 4 ) : null,
			'status'               => $status,
			'source'               => '' !== $source ? $source : 'external',
			'notes'                => sanitize_textarea_field( (string) ( $item['notes'] ?? '' ) ),
			'updated_at'           => $updated_at,
			'received_at'          => current_time( 'mysql' ),
		);
	}

	private function get_stored_updates(): array {
		$stored = get_option( self::OPTION_UPDATES, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $stored as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				$key = sanitize_text_field( (string) ( $row['dedupe_key'] ?? '' ) );
			}

			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = $row;
		}

		return $normalized;
	}

	private function save_stored_updates( array $updates ): void {
		update_option( self::OPTION_UPDATES, $updates, false );
	}

	private function normalize_timestamp( string $timestamp ): string {
		$timestamp = trim( $timestamp );
		if ( '' === $timestamp ) {
			return '';
		}

		$unix = strtotime( $timestamp );
		if ( false === $unix ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', (int) $unix );
	}

	private function resolve_latest_cursor( array $stored ): string {
		$cursor = '';
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$updated_at = (string) ( $row['updated_at'] ?? '' );
			if ( '' !== $updated_at && ( '' === $cursor || strcmp( $updated_at, $cursor ) > 0 ) ) {
				$cursor = $updated_at;
			}
		}

		return '' !== $cursor ? $cursor : current_time( 'mysql' );
	}

	private function resolve_latest_cursor_from_lists( array $updates, array $alerts ): string {
		$cursor = '';

		foreach ( $updates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$updated_at = (string) ( $row['updated_at'] ?? '' );
			if ( '' !== $updated_at && ( '' === $cursor || strcmp( $updated_at, $cursor ) > 0 ) ) {
				$cursor = $updated_at;
			}
		}

		foreach ( $alerts as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$updated_at = $this->normalize_timestamp( (string) ( $row['updated_at'] ?? '' ) );
			if ( '' !== $updated_at && ( '' === $cursor || strcmp( $updated_at, $cursor ) > 0 ) ) {
				$cursor = $updated_at;
			}
		}

		return '' !== $cursor ? $cursor : current_time( 'mysql' );
	}
}