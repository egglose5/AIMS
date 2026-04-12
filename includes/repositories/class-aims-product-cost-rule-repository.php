<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Product_Cost_Rule_Repository {
	public const ASSIGNMENT_TYPE_PRODUCT  = 'product';
	public const ASSIGNMENT_TYPE_CATEGORY = 'category';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_product_cost_rules';
	}

	public function save( array $data, int $rule_id = 0 ): int {
		global $wpdb;

		$record = array(
			'assignment_type' => $this->sanitize_assignment_type( $data['assignment_type'] ?? self::ASSIGNMENT_TYPE_PRODUCT ),
			'product_id'      => (int) ( $data['product_id'] ?? 0 ),
			'category_term_id'=> (int) ( $data['category_term_id'] ?? 0 ),
			'vendor_id'       => (int) ( $data['vendor_id'] ?? 0 ),
			'stitch_job_type' => sanitize_key( $data['stitch_job_type'] ?? '' ),
			'unit_cost'       => number_format( (float) ( $data['unit_cost'] ?? 0 ), 4, '.', '' ),
			'stitching_price' => number_format( (float) ( $data['stitching_price'] ?? 0 ), 4, '.', '' ),
			'is_active'       => ! empty( $data['is_active'] ) ? 1 : 0,
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( $rule_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $rule_id ),
				array( '%s', '%d', '%d', '%d', '%s', '%f', '%f', '%d', '%s' ),
				array( '%d' )
			);

			$this->fire_rule_saved_action( $rule_id, $record );

			return $rule_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record,
			array( '%s', '%d', '%d', '%d', '%s', '%f', '%f', '%d', '%s', '%s' )
		);

		$inserted_id = (int) $wpdb->insert_id;
		if ( $inserted_id > 0 ) {
			$this->fire_rule_saved_action( $inserted_id, $record );
		}

		return $inserted_id;
	}

	public function get_active_rules(): array {
		global $wpdb;

		return $wpdb->get_results(
			'SELECT * FROM ' . $this->get_table_name() . ' WHERE is_active = 1 ORDER BY vendor_id DESC, assignment_type ASC, id ASC',
			ARRAY_A
		);
	}

	private function sanitize_assignment_type( string $assignment_type ): string {
		$assignment_type = strtolower( trim( $assignment_type ) );

		return in_array( $assignment_type, array( self::ASSIGNMENT_TYPE_PRODUCT, self::ASSIGNMENT_TYPE_CATEGORY ), true )
			? $assignment_type
			: self::ASSIGNMENT_TYPE_PRODUCT;
	}

	private function fire_rule_saved_action( int $rule_id, array $record ): void {
		if ( $rule_id <= 0 || ! function_exists( 'do_action' ) ) {
			return;
		}

		$record['id'] = $rule_id;
		do_action( 'aims_product_cost_rule_saved', $rule_id, $record );
	}
}

