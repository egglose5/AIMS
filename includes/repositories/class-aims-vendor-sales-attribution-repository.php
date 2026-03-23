<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Sales_Attribution_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_vendor_sales_attribution';
	}

	public function save( array $data, int $attribution_id = 0 ): int {
		global $wpdb;

		$record = array(
			'normalized_sale_id'      => (int) ( $data['normalized_sale_id'] ?? 0 ),
			'vendor_id'               => (int) ( $data['vendor_id'] ?? 0 ),
			'event_id'                => (int) ( $data['event_id'] ?? 0 ),
			'runtime_assignment_id'   => (int) ( $data['runtime_assignment_id'] ?? 0 ),
			'attribution_status'      => sanitize_key( $data['attribution_status'] ?? 'pending' ),
			'gross_sales'             => number_format( (float) ( $data['gross_sales'] ?? 0 ), 2, '.', '' ),
			'tax_amount'              => number_format( (float) ( $data['tax_amount'] ?? 0 ), 2, '.', '' ),
			'discount_amount'         => number_format( (float) ( $data['discount_amount'] ?? 0 ), 2, '.', '' ),
			'tip_amount'              => number_format( (float) ( $data['tip_amount'] ?? 0 ), 2, '.', '' ),
			'refund_amount'           => number_format( (float) ( $data['refund_amount'] ?? 0 ), 2, '.', '' ),
			'net_sales_authoritative' => number_format( (float) ( $data['net_sales_authoritative'] ?? 0 ), 2, '.', '' ),
			'commissionable_sales'    => number_format( (float) ( $data['commissionable_sales'] ?? 0 ), 2, '.', '' ),
			'commission_amount'       => number_format( (float) ( $data['commission_amount'] ?? 0 ), 2, '.', '' ),
			'payout_amount'           => number_format( (float) ( $data['payout_amount'] ?? 0 ), 2, '.', '' ),
			'calculated_at'           => $this->normalize_datetime( $data['calculated_at'] ?? current_time( 'mysql' ) ),
			'source_sync_run_id'      => (int) ( $data['source_sync_run_id'] ?? 0 ),
			'updated_at'              => current_time( 'mysql' ),
		);

		if ( $attribution_id <= 0 ) {
			$existing = $this->find_by_normalized_sale_id( (int) $record['normalized_sale_id'] );
			if ( ! empty( $existing['id'] ) ) {
				$attribution_id = (int) $existing['id'];
			}
		}

		if ( $attribution_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $record, array( 'id' => $attribution_id ) );
			return $attribution_id;
		}

		$record['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $this->get_table_name(), $record );

		return (int) $wpdb->insert_id;
	}

	public function find_by_normalized_sale_id( int $normalized_sale_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE normalized_sale_id = %d',
				$normalized_sale_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d ORDER BY calculated_at ASC, id ASC',
				$event_id
			),
			ARRAY_A
		);
	}

	public function get_for_vendor( int $vendor_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE vendor_id = %d ORDER BY calculated_at ASC, id ASC',
				$vendor_id
			),
			ARRAY_A
		);
	}

	public function get_for_status( string $status, int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE attribution_status = %s ORDER BY calculated_at ASC, id ASC LIMIT %d',
				sanitize_key( $status ),
				$limit
			),
			ARRAY_A
		);
	}

	public function update_status( int $attribution_id, string $status ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->get_table_name(),
			array(
				'attribution_status' => sanitize_key( $status ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $attribution_id )
		);

		return false !== $updated;
	}

	private function normalize_datetime( $value ): string {
		if ( is_array( $value ) ) {
			return current_time( 'mysql' );
		}

		$time = strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : current_time( 'mysql' );
	}
}
