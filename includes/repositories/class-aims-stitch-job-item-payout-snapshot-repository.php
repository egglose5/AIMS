<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Job_Item_Payout_Snapshot_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_stitch_job_item_payout_snapshots';
	}

	public function save( array $data, int $snapshot_id = 0 ): int {
		global $wpdb;

		$record = $this->build_record( $data );

		if ( $snapshot_id > 0 ) {
			$record['updated_at'] = current_time( 'mysql' );
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $snapshot_id )
			);

			return $snapshot_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find_latest_for_item( int $stitch_job_item_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE stitch_job_item_id = %d ORDER BY captured_at DESC, id DESC LIMIT 1',
				$stitch_job_item_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_item( int $stitch_job_item_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE stitch_job_item_id = %d ORDER BY captured_at DESC, id DESC',
				$stitch_job_item_id
			),
			ARRAY_A
		);
	}

	private function build_record( array $data ): array {
		return array(
			'stitch_job_item_id'   => (int) ( $data['stitch_job_item_id'] ?? 0 ),
			'stitch_job_id'        => (int) ( $data['stitch_job_id'] ?? 0 ),
			'vendor_id'            => (int) ( $data['vendor_id'] ?? 0 ),
			'producer_user_id'     => (int) ( $data['producer_user_id'] ?? 0 ),
			'stitcher_user_id'     => (int) ( $data['stitcher_user_id'] ?? 0 ),
			'product_id'           => (int) ( $data['product_id'] ?? 0 ),
			'assignment_type'      => sanitize_key( $data['assignment_type'] ?? 'product' ),
			'stitch_job_type'      => sanitize_key( $data['stitch_job_type'] ?? '' ),
			'snapshot_source'      => sanitize_key( $data['snapshot_source'] ?? 'default_fallback' ),
			'snapshot_priority'    => max( 0, (int) ( $data['snapshot_priority'] ?? 0 ) ),
			'snapshot_rule_id'     => (int) ( $data['snapshot_rule_id'] ?? 0 ),
			'unit_payout_snapshot' => number_format( (float) ( $data['unit_payout_snapshot'] ?? 0 ), 4, '.', '' ),
			'snapshot_quantity'    => number_format( (float) ( $data['snapshot_quantity'] ?? 0 ), 4, '.', '' ),
			'captured_at'          => $this->normalize_datetime( $data['captured_at'] ?? current_time( 'mysql' ) ),
			'updated_at'           => current_time( 'mysql' ),
		);
	}

	private function normalize_datetime( $value ): string {
		$timestamp = strtotime( (string) $value );

		return false === $timestamp ? sanitize_text_field( (string) $value ) : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
