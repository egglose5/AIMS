<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Payout_Snapshot_Service {
	public const SNAPSHOT_SOURCE_STITCHER_SPECIFIC = 'stitcher_specific';
	public const SNAPSHOT_SOURCE_DEFAULT_FALLBACK  = 'default_fallback';
	public const SNAPSHOT_SOURCE_NONE              = 'none';

	private $cost_service;
	private $snapshots;

	public function __construct(
		AIMS_Product_Cost_Service $cost_service = null,
		AIMS_Stitch_Job_Item_Payout_Snapshot_Repository $snapshots = null
	) {
		$this->cost_service = $cost_service ?: new AIMS_Product_Cost_Service( new AIMS_Product_Cost_Rule_Repository() );
		$this->snapshots    = $snapshots ?: new AIMS_Stitch_Job_Item_Payout_Snapshot_Repository();
	}

	public function capture_for_job_item( array $item ): array {
		$item_id   = (int) ( $item['id'] ?? 0 );
		$product_id = (int) ( $item['product_id'] ?? 0 );

		if ( $item_id <= 0 || $product_id <= 0 ) {
			return $this->failure_response( 'A stitch job item and product are required for payout snapshotting.' );
		}

		$vendor_id   = (int) ( $item['vendor_id'] ?? 0 );
		$quantity    = (float) ( $item['quantity_requested'] ?? $item['quantity_completed'] ?? 0 );
		$rate_result = $this->resolve_snapshot_rate( $product_id, $vendor_id );

		$snapshot = array(
			'stitch_job_item_id'   => $item_id,
			'stitch_job_id'        => (int) ( $item['stitch_job_id'] ?? 0 ),
			'vendor_id'            => $vendor_id,
			'producer_user_id'     => (int) ( $item['producer_user_id'] ?? 0 ),
			'stitcher_user_id'     => (int) ( $item['stitcher_user_id'] ?? 0 ),
			'product_id'           => $product_id,
			'assignment_type'      => sanitize_key( (string) ( $item['assignment_type'] ?? AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_PRODUCT ) ),
			'stitch_job_type'      => sanitize_key( (string) ( $item['stitch_job_type'] ?? '' ) ),
			'snapshot_source'      => $rate_result['snapshot_source'],
			'snapshot_priority'    => $rate_result['snapshot_priority'],
			'snapshot_rule_id'     => (int) ( $item['payout_snapshot_rule_id'] ?? 0 ),
			'unit_payout_snapshot' => $rate_result['unit_payout_snapshot'],
			'snapshot_quantity'    => $quantity,
			'captured_at'          => current_time( 'mysql' ),
		);

		$snapshot_id = (int) $this->snapshots->save( $snapshot );
		if ( $snapshot_id > 0 ) {
			$snapshot['id'] = $snapshot_id;
		}

		return array(
			'success'    => true,
			'snapshot_id' => $snapshot_id,
			'snapshot'   => $snapshot,
		);
	}

	private function resolve_snapshot_rate( int $product_id, int $vendor_id ): array {
		$specific_rate = (float) $this->cost_service->resolve_unit_cost( $product_id, $vendor_id );

		if ( $specific_rate > 0 ) {
			return array(
				'unit_payout_snapshot' => $specific_rate,
				'snapshot_source'      => self::SNAPSHOT_SOURCE_STITCHER_SPECIFIC,
				'snapshot_priority'    => 1,
			);
		}

		$default_rate = (float) $this->cost_service->resolve_unit_cost( $product_id, 0 );
		if ( $default_rate > 0 ) {
			return array(
				'unit_payout_snapshot' => $default_rate,
				'snapshot_source'      => self::SNAPSHOT_SOURCE_DEFAULT_FALLBACK,
				'snapshot_priority'    => 2,
			);
		}

		return array(
			'unit_payout_snapshot' => 0.0,
			'snapshot_source'      => self::SNAPSHOT_SOURCE_NONE,
			'snapshot_priority'    => 99,
		);
	}

	private function failure_response( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
