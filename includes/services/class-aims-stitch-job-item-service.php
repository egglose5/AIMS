<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Job_Item_Service {
	private $items;
	private $payout_snapshots;
	private $producer_authorization;

	public function __construct(
		AIMS_Stitch_Job_Item_Repository $items = null,
		AIMS_Stitch_Payout_Snapshot_Service $payout_snapshots = null,
		AIMS_Stitch_Producer_Authorization_Service $producer_authorization = null
	) {
		$this->items                 = $items ?: new AIMS_Stitch_Job_Item_Repository();
		$this->payout_snapshots      = $payout_snapshots ?: new AIMS_Stitch_Payout_Snapshot_Service();
		$this->producer_authorization = $producer_authorization ?: new AIMS_Stitch_Producer_Authorization_Service();
	}

	public function assign_job_item( array $data, int $item_id = 0 ): array {
		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $current_user_id > 0 && ! $this->producer_authorization->can_manage_stitch_orders( $current_user_id ) ) {
			return $this->failure_response( 'You do not have permission to manage stitch orders.' );
		}

		$record = $this->normalize_item_data( $data );
		$record['status']      = AIMS_Stitch_Job_Item_Repository::STATUS_ASSIGNED;
		$record['assigned_at'] = current_time( 'mysql' );

		$saved_item_id = (int) $this->items->save( $record, $item_id );
		if ( $saved_item_id <= 0 ) {
			return $this->failure_response( 'Stitch job item could not be saved.' );
		}

		$item = array_merge( $record, array( 'id' => $saved_item_id ) );
		$snapshot_result = $this->payout_snapshots->capture_for_job_item( $item );

		if ( ! empty( $snapshot_result['success'] ) && ! empty( $snapshot_result['snapshot'] ) ) {
			$this->items->set_payout_snapshot( $saved_item_id, $snapshot_result['snapshot'] );
			$item['unit_payout_snapshot']   = $snapshot_result['snapshot']['unit_payout_snapshot'] ?? $item['unit_payout_snapshot'];
			$item['payout_snapshot_source'] = $snapshot_result['snapshot']['snapshot_source'] ?? $item['payout_snapshot_source'];
			$item['payout_snapshot_rule_id'] = $snapshot_result['snapshot']['snapshot_rule_id'] ?? $item['payout_snapshot_rule_id'];
			$item['snapshot_taken_at']      = $snapshot_result['snapshot']['captured_at'] ?? $item['snapshot_taken_at'];
			$item = array_merge( $item, $snapshot_result['snapshot'] );
		}

		return array(
			'success' => true,
			'item_id' => $saved_item_id,
			'item'    => $item,
			'snapshot' => $snapshot_result['snapshot'] ?? array(),
		);
	}

	public function record_completed_quantity( int $item_id, float $quantity_completed, array $data = array() ): array {
		$item = $this->items->find( $item_id );
		if ( empty( $item ) ) {
			return $this->failure_response( 'Stitch job item could not be found.' );
		}

		$quantity_completed = max( 0.0, $quantity_completed );
		$success = $this->items->mark_completed(
			$item_id,
			$quantity_completed,
			array(
				'status'       => AIMS_Stitch_Job_Item_Repository::STATUS_COMPLETED,
				'completed_at' => $data['completed_at'] ?? current_time( 'mysql' ),
				'notes'        => $data['notes'] ?? '',
			)
		);

		if ( ! $success ) {
			return $this->failure_response( 'Completed quantity could not be recorded.' );
		}

		$item = $this->items->find( $item_id );

		return array(
			'success' => true,
			'item_id' => $item_id,
			'item'    => $item,
		);
	}

	public function record_received_back_quantity( int $item_id, float $quantity_received_back, array $data = array() ): array {
		$item = $this->items->find( $item_id );
		if ( empty( $item ) ) {
			return $this->failure_response( 'Stitch job item could not be found.' );
		}

		$quantity_received_back = max( 0.0, $quantity_received_back );
		$success = $this->items->mark_received_back(
			$item_id,
			$quantity_received_back,
			array(
				'status'         => AIMS_Stitch_Job_Item_Repository::STATUS_RECEIVED_BACK,
				'received_back_at' => $data['received_back_at'] ?? current_time( 'mysql' ),
				'notes'          => $data['notes'] ?? '',
			)
		);

		if ( ! $success ) {
			return $this->failure_response( 'Received-back quantity could not be recorded.' );
		}

		$item = $this->items->find( $item_id );

		return array(
			'success' => true,
			'item_id' => $item_id,
			'item'    => $item,
		);
	}

	private function normalize_item_data( array $data ): array {
		return array(
			'stitch_job_id'          => (int) ( $data['stitch_job_id'] ?? 0 ),
			'line_number'            => max( 1, (int) ( $data['line_number'] ?? 1 ) ),
			'product_id'             => (int) ( $data['product_id'] ?? 0 ),
			'vendor_id'              => (int) ( $data['vendor_id'] ?? 0 ),
			'producer_user_id'       => (int) ( $data['producer_user_id'] ?? 0 ),
			'stitcher_user_id'       => (int) ( $data['stitcher_user_id'] ?? 0 ),
			'stitch_job_type'        => sanitize_key( (string) ( $data['stitch_job_type'] ?? '' ) ),
			'quantity_requested'     => (float) ( $data['quantity_requested'] ?? 0 ),
			'quantity_completed'     => (float) ( $data['quantity_completed'] ?? 0 ),
			'quantity_received_back' => (float) ( $data['quantity_received_back'] ?? 0 ),
			'notes'                  => isset( $data['notes'] ) ? $data['notes'] : '',
			'payout_snapshot_rule_id' => (int) ( $data['payout_snapshot_rule_id'] ?? 0 ),
		);
	}

	private function failure_response( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
