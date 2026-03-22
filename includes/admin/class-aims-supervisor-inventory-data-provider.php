<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Supervisor_Inventory_Data_Provider {
	private $bucket_repository;
	private $vendor_repository;
	private $event_repository;

	public function __construct(
		AIMS_Inventory_Bucket_Repository $bucket_repository = null,
		AIMS_Vendor_Repository $vendor_repository = null,
		AIMS_Event_Repository $event_repository = null
	) {
		$this->bucket_repository = $bucket_repository ?: new AIMS_Inventory_Bucket_Repository();
		$this->vendor_repository  = $vendor_repository ?: new AIMS_Vendor_Repository();
		$this->event_repository   = $event_repository ?: new AIMS_Event_Repository();
	}

	public function get_rows(): array {
		$buckets = $this->get_bucket_rows();

		return array_map(
			array( $this, 'normalize_row' ),
			$buckets
		);
	}

	public function get_summary(): array {
		$rows = $this->get_rows();

		$summary = array(
			'total'     => 0,
			'event'     => 0,
			'vendor'    => 0,
			'warehouse' => 0,
		);

		foreach ( $rows as $row ) {
			$summary['total']++;
			$type = ! empty( $row['bucket_type'] ) ? (string) $row['bucket_type'] : 'warehouse';
			if ( isset( $summary[ $type ] ) ) {
				$summary[ $type ]++;
			}
		}

		return $summary;
	}

	private function get_bucket_rows(): array {
		global $wpdb;

		$table = $this->bucket_repository->get_table_name();
		$allowed_vendor_ids = $this->get_allowed_vendor_ids();

		if ( false === $allowed_vendor_ids ) {
			$sql = "
				SELECT *
				FROM {$table}
				ORDER BY
					CASE bucket_type
						WHEN 'event' THEN 1
						WHEN 'vendor' THEN 2
						WHEN 'warehouse' THEN 3
						ELSE 4
					END,
					bucket_label ASC,
					id ASC
				LIMIT 50
			";

			$rows = $wpdb->get_results( $sql, ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		}

		if ( empty( $allowed_vendor_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $allowed_vendor_ids ), '%d' ) );
		$sql = $wpdb->prepare(
			"
				SELECT *
				FROM {$table}
				WHERE vendor_id IN ({$placeholders})
				ORDER BY
					CASE bucket_type
						WHEN 'event' THEN 1
						WHEN 'vendor' THEN 2
						WHEN 'warehouse' THEN 3
						ELSE 4
					END,
					bucket_label ASC,
					id ASC
				LIMIT 50
			",
			$allowed_vendor_ids
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function normalize_row( array $bucket ): array {
		$quantity  = (float) ( $bucket['quantity'] ?? 0 );
		$reserved  = (float) ( $bucket['reserved_quantity'] ?? 0 );
		$available = max( 0, $quantity - $reserved );
		$type      = ! empty( $bucket['bucket_type'] ) ? (string) $bucket['bucket_type'] : 'warehouse';

		return array(
			'bucket_label'       => ! empty( $bucket['bucket_label'] ) ? (string) $bucket['bucket_label'] : 'Unlabeled bucket',
			'scope_label'        => $this->build_scope_label( $bucket ),
			'bucket_type'        => $type,
			'access_label'       => $this->build_access_label(),
			'quantity'           => number_format( $quantity, 4, '.', '' ),
			'reserved_quantity'  => number_format( $reserved, 4, '.', '' ),
			'available_quantity' => number_format( $available, 4, '.', '' ),
			'updated_at'         => ! empty( $bucket['updated_at'] ) ? (string) $bucket['updated_at'] : current_time( 'mysql' ),
		);
	}

	private function build_scope_label( array $bucket ): string {
		$bucket_type = ! empty( $bucket['bucket_type'] ) ? (string) $bucket['bucket_type'] : '';
		$bucket_id   = ! empty( $bucket['owner_entity_id'] ) ? (int) $bucket['owner_entity_id'] : 0;
		$vendor_id   = ! empty( $bucket['vendor_id'] ) ? (int) $bucket['vendor_id'] : 0;

		if ( 'event' === $bucket_type ) {
			$event = $this->find_event_name( $bucket_id );
			if ( '' !== $event ) {
				return $event;
			}
		}

		if ( 0 !== $vendor_id ) {
			$vendor = $this->find_vendor_name( $vendor_id );
			if ( '' !== $vendor ) {
				return $vendor;
			}
		}

		return ! empty( $bucket['bucket_label'] ) ? (string) $bucket['bucket_label'] : 'Unassigned scope';
	}

	private function build_access_label(): string {
		return current_user_can( AIMS_Capabilities::CAP_MANAGE ) ? 'Full access' : 'Bucket-scoped';
	}

	private function get_allowed_vendor_ids() {
		if ( current_user_can( AIMS_Capabilities::CAP_MANAGE ) ) {
			return false;
		}

		global $wpdb;

		$access_table = $wpdb->prefix . 'aims_vendor_user_access';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $access_table ) );
		if ( $table_exists !== $access_table ) {
			return array();
		}

		$vendor_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT vendor_id FROM ' . $access_table . ' WHERE user_id = %d ORDER BY vendor_id ASC',
				get_current_user_id()
			)
		);

		if ( empty( $vendor_ids ) || ! is_array( $vendor_ids ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $vendor_ids ) ) );
	}

	private function find_vendor_name( int $vendor_id ): string {
		$vendor = $this->vendor_repository->find( $vendor_id );

		return ! empty( $vendor['vendor_name'] ) ? (string) $vendor['vendor_name'] : '';
	}

	private function find_event_name( int $event_id ): string {
		$events = $this->event_repository->all();

		foreach ( $events as $event ) {
			if ( ! empty( $event['id'] ) && (int) $event['id'] === $event_id ) {
				return ! empty( $event['event_name'] ) ? (string) $event['event_name'] : '';
			}
		}

		return '';
	}
}
