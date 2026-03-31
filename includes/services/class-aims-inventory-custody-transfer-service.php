<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Custody_Transfer_Service {
	private $bucket_movement_service;
	private $bucket_repository;

	public function __construct(
		AIMS_Bucket_Movement_Service $bucket_movement_service,
		AIMS_Physical_Bucket_Repository $bucket_repository = null
	) {
		$this->bucket_movement_service = $bucket_movement_service;
		$this->bucket_repository       = $bucket_repository ?: new AIMS_Physical_Bucket_Repository();
	}

	public function create_transfer_out( array $data ): array {
		return $this->apply_custody_transfer(
			$data,
			array(
				'reference_type'       => 'custody_transfer',
				'message'              => 'Custody transfer dispatch recorded.',
				'sign'                 => -1,
				'route_bucket'         => 'source',
				'derive_vendor_from'   => 'source',
				'require_reference_id' => false,
			)
		);
	}

	public function confirm_transfer_receipt( array $data ): array {
		return $this->apply_custody_transfer(
			$data,
			array(
				'reference_type'       => 'custody_receipt',
				'message'              => 'Custody transfer receipt recorded.',
				'sign'                 => 1,
				'route_bucket'         => 'target',
				'derive_vendor_from'   => 'target',
				'require_reference_id' => true,
			)
		);
	}

	public function create_return_out( array $data ): array {
		return $this->apply_custody_transfer(
			$data,
			array(
				'reference_type'       => 'custody_return_dispatch',
				'message'              => 'Custody return dispatch recorded.',
				'sign'                 => -1,
				'route_bucket'         => 'source',
				'derive_vendor_from'   => 'source',
				'require_reference_id' => false,
			)
		);
	}

	public function confirm_return_receipt( array $data ): array {
		return $this->apply_custody_transfer(
			$data,
			array(
				'reference_type'       => 'custody_return_receipt',
				'message'              => 'Custody return receipt recorded.',
				'sign'                 => 1,
				'route_bucket'         => 'target',
				'derive_vendor_from'   => 'target',
				'require_reference_id' => true,
			)
		);
	}

	private function apply_custody_transfer( array $data, array $config ): array {
		$product_id       = (int) ( $data['product_id'] ?? 0 );
		$source_bucket_id = (int) ( $data['source_bucket_id'] ?? 0 );
		$target_bucket_id = (int) ( $data['target_bucket_id'] ?? 0 );
		$quantity_abs     = abs( (float) ( $data['quantity_delta'] ?? $data['quantity'] ?? 0 ) );

		if ( $product_id <= 0 ) {
			return $this->failure_response( 'A valid product is required.', 'aims_missing_product_id' );
		}

		if ( $source_bucket_id <= 0 ) {
			return $this->failure_response( 'A valid source bucket is required.', 'aims_missing_source_bucket_id' );
		}

		if ( $target_bucket_id <= 0 ) {
			return $this->failure_response( 'A valid target bucket is required.', 'aims_missing_target_bucket_id' );
		}

		if ( $quantity_abs <= 0 ) {
			return $this->failure_response( 'A non-zero quantity is required.', 'aims_missing_quantity' );
		}

		$reference_id = sanitize_text_field( (string) ( $data['reference_id'] ?? '' ) );
		if ( ! empty( $config['require_reference_id'] ) && '' === $reference_id ) {
			return $this->failure_response( 'A reference ID is required for receipt confirmation.', 'aims_missing_reference_id' );
		}

		if ( '' === $reference_id ) {
			$reference_id = $this->generate_reference_id();
		}

		$vendor_id = (int) ( $data['vendor_id'] ?? 0 );
		if ( $vendor_id <= 0 ) {
			$derive_bucket_id = 'target' === (string) $config['derive_vendor_from'] ? $target_bucket_id : $source_bucket_id;
			$vendor_id        = $this->derive_vendor_id_from_bucket( $derive_bucket_id );
		}

		if ( $vendor_id <= 0 ) {
			return $this->failure_response( 'Vendor could not be determined for this custody transfer.', 'aims_missing_vendor_id' );
		}

		$route_bucket_id = 'target' === (string) $config['route_bucket'] ? $target_bucket_id : $source_bucket_id;
		$quantity_delta  = $quantity_abs * (int) $config['sign'];

		$movement_payload = array(
			'bucket_id'        => $route_bucket_id,
			'source_bucket_id' => $source_bucket_id,
			'target_bucket_id' => $target_bucket_id,
			'vendor_id'        => $vendor_id,
			'product_id'       => $product_id,
			'reference_type'   => sanitize_key( (string) $config['reference_type'] ),
			'reference_id'     => $reference_id,
			'movement_type'    => AIMS_Inventory_Movement_Events::WAREHOUSE_TRANSFER,
			'quantity_delta'   => $quantity_delta,
		);

		if ( isset( $data['applied_by'] ) ) {
			$movement_payload['applied_by'] = (int) $data['applied_by'];
		}

		if ( isset( $data['note'] ) ) {
			$movement_payload['note'] = sanitize_textarea_field( (string) $data['note'] );
		}

		if ( isset( $data['position_status'] ) ) {
			$movement_payload['position_status'] = sanitize_key( (string) $data['position_status'] );
		}

		$movement_result = $this->bucket_movement_service->record_transfer( $movement_payload );

		if ( is_wp_error( $movement_result ) ) {
			$error_code = sanitize_key( (string) $movement_result->get_error_code() );
			if ( '' === $error_code ) {
				$error_code = 'aims_transfer_record_failed';
			}

			return $this->failure_response( $movement_result->get_error_message(), $error_code );
		}

		if ( ! is_array( $movement_result ) ) {
			return $this->failure_response( 'Transfer could not be recorded.', 'aims_transfer_record_failed' );
		}

		return array(
			'success'          => true,
			'message'          => (string) $config['message'],
			'reference_id'     => $reference_id,
			'movement_id'      => (int) ( $movement_result['movement_id'] ?? 0 ),
			'current_quantity' => (float) ( $movement_result['current_quantity'] ?? 0 ),
			'source_bucket_id' => $source_bucket_id,
			'target_bucket_id' => $target_bucket_id,
		);
	}

	private function derive_vendor_id_from_bucket( int $bucket_id ): int {
		if ( $bucket_id <= 0 || ! is_object( $this->bucket_repository ) || ! method_exists( $this->bucket_repository, 'find' ) ) {
			return 0;
		}

		$bucket = $this->bucket_repository->find( $bucket_id );

		return is_array( $bucket ) ? (int) ( $bucket['vendor_id'] ?? 0 ) : 0;
	}

	private function generate_reference_id(): string {
		$uuid = function_exists( 'wp_generate_uuid4' )
			? (string) wp_generate_uuid4()
			: str_replace( '.', '-', uniqid( '', true ) );

		return 'custody-' . sanitize_key( $uuid );
	}

	private function failure_response( string $message, string $error_code ): array {
		return array(
			'success'    => false,
			'message'    => $message,
			'error_code' => sanitize_key( $error_code ),
		);
	}
}
