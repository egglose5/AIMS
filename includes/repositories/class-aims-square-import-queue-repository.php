<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Import_Queue_Repository {
	public const STATUS_PENDING   = 'pending';
	public const STATUS_PROCESSED = 'processed';
	public const STATUS_ERROR     = 'error';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_square_import_queue';
	}

	public function allowed_statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_PROCESSED,
			self::STATUS_ERROR,
		);
	}

	public function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, $this->allowed_statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	public function save( array $data, int $queue_id = 0 ): int {
		global $wpdb;

		if ( $queue_id <= 0 && ! empty( $data['square_order_id'] ) ) {
			$existing = $this->find_by_square_order_id( (string) $data['square_order_id'] );

			if ( ! empty( $existing['id'] ) ) {
				$queue_id = (int) $existing['id'];
			}
		}

		$record = array(
			'square_order_id' => sanitize_text_field( $data['square_order_id'] ?? '' ),
			'location_id'     => sanitize_text_field( $data['location_id'] ?? '' ),
			'import_status'   => $this->normalize_status( (string) ( $data['import_status'] ?? self::STATUS_PENDING ) ),
			'payload'         => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
			'imported_at'     => $data['imported_at'] ?? null,
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( $queue_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $queue_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $queue_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function update_status( int $queue_id, string $status, ?string $imported_at = null ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'import_status' => $this->normalize_status( $status ),
				'imported_at'   => $imported_at,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function mark_processed( int $queue_id, ?string $imported_at = null ): bool {
		return $this->update_status( $queue_id, self::STATUS_PROCESSED, $imported_at );
	}

	public function mark_error( int $queue_id ): bool {
		return $this->update_status( $queue_id, self::STATUS_ERROR, null );
	}

	public function find_by_square_order_id( string $square_order_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE square_order_id = %s',
				$square_order_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
