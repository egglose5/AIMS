<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Job_Item_Repository {
	public const STATUS_QUEUED      = 'queued';
	public const STATUS_ASSIGNED    = 'assigned';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED   = 'completed';
	public const STATUS_RECEIVED_BACK = 'received_back';
	public const STATUS_CLOSED      = 'closed';
	public const STATUS_VOID        = 'void';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_stitch_job_items';
	}

	public function save( array $data, int $item_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $item_id > 0 ) {
			$record['updated_at'] = current_time( 'mysql' );
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $item_id )
			);

			return $item_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find( int $item_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$item_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_job( int $stitch_job_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE stitch_job_id = %d ORDER BY line_number ASC, id ASC',
				$stitch_job_id
			),
			ARRAY_A
		);
	}

	public function find_by_job_line( int $stitch_job_id, int $line_number ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE stitch_job_id = %d AND line_number = %d LIMIT 1',
				$stitch_job_id,
				$line_number
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function mark_completed( int $item_id, float $quantity_completed, array $data = array() ): bool {
		return $this->update_item_progress(
			$item_id,
			array(
				'quantity_completed' => $this->normalize_decimal( $quantity_completed ),
				'completed_at'       => $this->normalize_datetime( $data['completed_at'] ?? current_time( 'mysql' ) ),
				'status'             => $this->normalize_status( (string) ( $data['status'] ?? self::STATUS_COMPLETED ) ),
				'notes'              => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : null,
			)
		);
	}

	public function mark_received_back( int $item_id, float $quantity_received_back, array $data = array() ): bool {
		return $this->update_item_progress(
			$item_id,
			array(
				'quantity_received_back' => $this->normalize_decimal( $quantity_received_back ),
				'received_back_at'       => $this->normalize_datetime( $data['received_back_at'] ?? current_time( 'mysql' ) ),
				'status'                 => $this->normalize_status( (string) ( $data['status'] ?? self::STATUS_RECEIVED_BACK ) ),
				'notes'                  => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : null,
			)
		);
	}

	public function mark_labels_prepared( int $item_id, array $data = array() ): bool {
		return $this->update_item_progress(
			$item_id,
			array(
				'labels_prepared_at'       => $this->normalize_datetime( $data['labels_prepared_at'] ?? current_time( 'mysql' ) ),
				'labels_prepared_by'       => (int) ( $data['labels_prepared_by'] ?? 0 ),
				'labels_prepared_quantity' => $this->normalize_decimal( $data['labels_prepared_quantity'] ?? 0 ),
				'label_template_key'       => sanitize_key( (string) ( $data['label_template_key'] ?? '' ) ),
			)
		);
	}

	public function set_payout_snapshot( int $item_id, array $snapshot ): bool {
		global $wpdb;

		if ( $item_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->get_table_name(),
			array(
				'unit_payout_snapshot'    => $this->normalize_decimal( $snapshot['unit_payout_snapshot'] ?? 0 ),
				'payout_snapshot_source'   => sanitize_key( $snapshot['snapshot_source'] ?? '' ),
				'payout_snapshot_rule_id'  => (int) ( $snapshot['snapshot_rule_id'] ?? 0 ),
				'snapshot_taken_at'        => $this->normalize_datetime( $snapshot['captured_at'] ?? current_time( 'mysql' ) ),
				'updated_at'              => current_time( 'mysql' ),
			),
			array( 'id' => $item_id )
		);
	}

	private function build_record( array $data ): array {
		return array(
			'stitch_job_id'           => (int) ( $data['stitch_job_id'] ?? 0 ),
			'line_number'             => max( 1, (int) ( $data['line_number'] ?? 1 ) ),
			'product_id'              => (int) ( $data['product_id'] ?? 0 ),
			'vendor_id'               => (int) ( $data['vendor_id'] ?? 0 ),
			'producer_user_id'        => (int) ( $data['producer_user_id'] ?? 0 ),
			'stitcher_user_id'        => (int) ( $data['stitcher_user_id'] ?? 0 ),
			'stitch_job_type'         => sanitize_key( $data['stitch_job_type'] ?? '' ),
			'status'                  => $this->normalize_status( (string) ( $data['status'] ?? self::STATUS_QUEUED ) ),
			'quantity_requested'      => $this->normalize_decimal( $data['quantity_requested'] ?? 0 ),
			'quantity_completed'      => $this->normalize_decimal( $data['quantity_completed'] ?? 0 ),
			'quantity_received_back'  => $this->normalize_decimal( $data['quantity_received_back'] ?? 0 ),
			'unit_payout_snapshot'    => $this->normalize_decimal( $data['unit_payout_snapshot'] ?? 0 ),
			'payout_snapshot_source'  => sanitize_key( $data['payout_snapshot_source'] ?? '' ),
			'payout_snapshot_rule_id' => (int) ( $data['payout_snapshot_rule_id'] ?? 0 ),
			'snapshot_taken_at'       => $this->normalize_datetime( $data['snapshot_taken_at'] ?? null ),
			'labels_prepared_at'      => $this->normalize_datetime( $data['labels_prepared_at'] ?? null ),
			'labels_prepared_by'      => (int) ( $data['labels_prepared_by'] ?? 0 ),
			'labels_prepared_quantity' => $this->normalize_decimal( $data['labels_prepared_quantity'] ?? 0 ),
			'label_template_key'      => sanitize_key( (string) ( $data['label_template_key'] ?? '' ) ),
			'assigned_at'             => $this->normalize_datetime( $data['assigned_at'] ?? null ),
			'completed_at'            => $this->normalize_datetime( $data['completed_at'] ?? null ),
			'received_back_at'        => $this->normalize_datetime( $data['received_back_at'] ?? null ),
			'notes'                   => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'updated_at'              => current_time( 'mysql' ),
		);
	}

	private function update_item_progress( int $item_id, array $data ): bool {
		global $wpdb;

		if ( $item_id <= 0 ) {
			return false;
		}

		$record = array_filter(
			$data,
			static function ( $value ): bool {
				return null !== $value;
			}
		);
		$record['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update(
			$this->get_table_name(),
			$record,
			array( 'id' => $item_id )
		);
	}

	private function normalize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array(
			self::STATUS_QUEUED,
			self::STATUS_ASSIGNED,
			self::STATUS_IN_PROGRESS,
			self::STATUS_COMPLETED,
			self::STATUS_RECEIVED_BACK,
			self::STATUS_CLOSED,
			self::STATUS_VOID,
		);

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_QUEUED;
	}

	private function normalize_decimal( $value ): string {
		return number_format( (float) $value, 4, '.', '' );
	}

	private function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );

		return false === $timestamp ? sanitize_text_field( (string) $value ) : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
