<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Customer_Request_Repository {
	public const STATUS_PLANNED = 'planned';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_ARCHIVED = 'archived';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_event_customer_requests';
	}

	public function save( array $data, int $request_id = 0 ): int {
		global $wpdb;

		$requested_at = $this->normalize_datetime( $data['requested_at'] ?? current_time( 'mysql' ) );
		$submitted_at = $this->normalize_datetime( $data['submitted_at'] ?? $requested_at );
		$approved_at  = $this->normalize_datetime( $data['approved_at'] ?? $requested_at );

		$record = array(
			'event_id'       => (int) ( $data['event_id'] ?? 0 ),
			'wp_user_id'     => (int) ( $data['wp_user_id'] ?? $data['user_id'] ?? 0 ),
			'vendor_id'      => (int) ( $data['vendor_id'] ?? 0 ),
			'customer_id'    => (int) ( $data['customer_id'] ?? 0 ),
			'customer_name'  => sanitize_text_field( $data['customer_name'] ?? '' ),
			'customer_email' => sanitize_email( $data['customer_email'] ?? '' ),
			'customer_phone' => sanitize_text_field( $data['customer_phone'] ?? '' ),
			'status'         => $this->normalize_status( (string) ( $data['status'] ?? self::STATUS_PLANNED ) ),
			'request_source' => sanitize_key( $data['request_source'] ?? 'event_customer_request' ),
			'request_status' => $this->normalize_request_status( (string) ( $data['request_status'] ?? 'approved' ) ),
			'approval_mode'  => sanitize_key( $data['approval_mode'] ?? 'auto_planning_signal' ),
			'submitted_at'   => $submitted_at,
			'requested_at'   => $requested_at,
			'approved_at'    => $approved_at,
			'notes'          => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'     => current_time( 'mysql' ),
		);

		if ( $request_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $request_id ) );
			return $request_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $request_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$request_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY requested_at DESC, id DESC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_for_wp_user_id( int $wp_user_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE wp_user_id = %d ORDER BY requested_at DESC, id DESC',
				$wp_user_id
			),
			ARRAY_A
		);
	}

	public function get_history_for_wp_user_id( int $wp_user_id, int $limit = 50 ): array {
		global $wpdb;

		$limit       = max( 1, $limit );
		$events_table = $wpdb->prefix . 'aims_events';
		$items_table  = $wpdb->prefix . 'aims_event_customer_request_items';

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT requests.*, events.event_name, events.start_date, events.end_date, COALESCE(items.item_count, 0) AS item_count, COALESCE(items.total_quantity_requested, 0) AS total_quantity_requested
				FROM ' . $this->get_table_name() . ' requests
				LEFT JOIN ' . $events_table . ' events ON events.id = requests.event_id
				LEFT JOIN (
					SELECT request_id, COUNT(*) AS item_count, COALESCE(SUM(quantity_requested), 0) AS total_quantity_requested
					FROM ' . $items_table . '
					WHERE item_status != %s
					GROUP BY request_id
				) items ON items.request_id = requests.id
				WHERE requests.wp_user_id = %d
				ORDER BY requests.requested_at DESC, requests.id DESC
				LIMIT %d',
				AIMS_Event_Customer_Request_Item_Repository::STATUS_CANCELLED,
				$wp_user_id,
				$limit
			),
			ARRAY_A
		);
	}

	public function find_for_wp_user_id( int $request_id, int $wp_user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d AND wp_user_id = %d',
				$request_id,
				$wp_user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function update_status( int $request_id, string $status ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'status'     => $this->normalize_status( $status ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $request_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function get_recent_for_event( int $event_id, int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, $limit );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY requested_at DESC, id DESC LIMIT %d',
				$event_id,
				$limit
			),
			ARRAY_A
		);
	}

	public function get_recent_for_wp_user_id( int $wp_user_id, int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, $limit );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE wp_user_id = %d ORDER BY requested_at DESC, id DESC LIMIT %d',
				$wp_user_id,
				$limit
			),
			ARRAY_A
		);
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		$allowed = array(
			self::STATUS_PLANNED,
			self::STATUS_CANCELLED,
			self::STATUS_ARCHIVED,
		);

		if ( in_array( $status, array( 'approved', 'active', 'pending' ), true ) ) {
			return self::STATUS_PLANNED;
		}

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_PLANNED;
	}

	private function normalize_request_status( string $status ): string {
		$status = sanitize_key( $status );

		if ( in_array( $status, array( 'approved', 'active', 'pending', 'auto_accepted' ), true ) ) {
			return self::STATUS_PLANNED;
		}

		return in_array( $status, array( self::STATUS_PLANNED, self::STATUS_CANCELLED, self::STATUS_ARCHIVED ), true ) ? $status : self::STATUS_PLANNED;
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : sanitize_text_field( (string) $value );
	}
}
