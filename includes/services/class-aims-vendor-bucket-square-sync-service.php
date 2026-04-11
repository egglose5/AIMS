<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Bucket_Square_Sync_Service {
	private $bucket_positions;
	private $physical_buckets;
	private $vendor_service;
	private $client;

	public function __construct(
		AIMS_Bucket_Inventory_Position_Repository $bucket_positions = null,
		AIMS_Physical_Bucket_Repository $physical_buckets = null,
		AIMS_Vendor_Service $vendor_service = null,
		AIMS_Headless_Api_Client $client = null
	) {
		$this->bucket_positions = $bucket_positions ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->physical_buckets = $physical_buckets ?: new AIMS_Physical_Bucket_Repository();
		$this->vendor_service   = $vendor_service ?: new AIMS_Vendor_Service();
		$this->client           = $client ?: AIMS_Headless_Api_Client::from_plugin_options();
	}

	public function sync_bucket_to_vendor_location( int $bucket_id, int $vendor_id = 0, array $context = array() ): array {
		$bucket = $this->get_bucket_context( $bucket_id );
		if ( empty( $bucket ) ) {
			return $this->result( false, false, true, 'missing_bucket', 'Physical bucket context could not be resolved.', 0, '', array(), array(), array() );
		}

		if ( $vendor_id <= 0 ) {
			$vendor_id = (int) ( $bucket['vendor_id'] ?? $context['vendor_id'] ?? 0 );
		}

		if ( $vendor_id <= 0 ) {
			return $this->result( false, false, true, 'missing_vendor', 'Vendor ownership could not be resolved for this bucket.', $bucket_id, '', array(), array(), array() );
		}

		$vendor = is_object( $this->vendor_service ) && method_exists( $this->vendor_service, 'get_vendor' )
			? $this->vendor_service->get_vendor( $vendor_id )
			: null;

		$square_location_id = sanitize_text_field(
			(string) ( $vendor['square_location_id'] ?? $bucket['square_location_id'] ?? $context['square_location_id'] ?? '' )
		);

		if ( '' === $square_location_id ) {
			return $this->result( false, false, true, 'missing_square_location', 'Vendor Square location is not assigned.', $bucket_id, '', array(), array(), array() );
		}

		$catalog_rows = array();
		$skipped_rows = array();

		foreach ( $this->get_bucket_inventory_rows( $bucket_id ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = (int) ( $row['product_id'] ?? 0 );
			$quantity   = max( 0, (float) ( $row['quantity'] ?? 0 ) - (float) ( $row['reserved_quantity'] ?? 0 ) );
			$sku        = $this->resolve_product_sku( $product_id );

			if ( $product_id <= 0 || $quantity <= 0 ) {
				continue;
			}

			if ( '' === $sku ) {
				$skipped_rows[] = array(
					'product_id' => $product_id,
					'quantity'   => $quantity,
					'reason'     => 'missing_sku',
				);
				continue;
			}

			$catalog_rows[] = array(
				'product_id'         => $product_id,
				'sku'                => $sku,
				'stock_quantity'     => $quantity,
				'square_location_id' => $square_location_id,
				'bucket_id'          => $bucket_id,
				'bucket_code'        => sanitize_text_field( (string) ( $bucket['bucket_code'] ?? '' ) ),
				'bucket_label'       => sanitize_text_field( (string) ( $bucket['bucket_label'] ?? '' ) ),
				'source_of_truth'    => 'aims',
				'show_id'            => (string) ( $context['event_id'] ?? '' ),
			);
		}

		if ( empty( $catalog_rows ) ) {
			return $this->result(
				true,
				false,
				true,
				'no_syncable_inventory',
				'No bucket inventory lines with sellable SKU quantities were available to sync.',
				$bucket_id,
				$square_location_id,
				array(),
				array(),
				$skipped_rows
			);
		}

		$manifest_uuid = sanitize_text_field( (string) ( $context['reference_id'] ?? ( 'bucket-sync-' . $bucket_id . '-' . gmdate( 'YmdHis' ) ) ) );
		$payload       = array(
			'manifest_uuid'      => $manifest_uuid,
			'manifest_id'        => $manifest_uuid,
			'generated_at'       => gmdate( 'c' ),
			'sync_mode'          => 'bucket_square_sync',
			'consistency_model'  => 'vendor_square_bucket_projection',
			'resolved_truth'     => array(
				'catalog' => $catalog_rows,
			),
		);

		$response       = is_object( $this->client ) && method_exists( $this->client, 'push_manifest' )
			? $this->client->push_manifest( $payload )
			: array(
				'success' => false,
				'message' => 'Headless manifest client is unavailable.',
			);
		$square_results = $this->extract_square_results( $response );
		$success_count  = count(
			array_filter(
				$square_results,
				static function ( array $row ): bool {
					return ! empty( $row['success'] );
				}
			)
		);
		$failure_count  = count(
			array_filter(
				$square_results,
				static function ( array $row ): bool {
					return empty( $row['success'] ) && 'skipped' !== sanitize_key( (string) ( $row['status'] ?? '' ) );
				}
			)
		);

		if ( $success_count > 0 && is_object( $this->physical_buckets ) && method_exists( $this->physical_buckets, 'update_square_location_id' ) ) {
			$this->physical_buckets->update_square_location_id( $bucket_id, $square_location_id );
		}

		return $this->result(
			! empty( $response['success'] ) && 0 === $failure_count,
			true,
			false,
			0 === $failure_count ? '' : 'square_sync_failed',
			0 === $failure_count ? 'Bucket contents synced to the vendor Square location.' : 'Bucket contents could not be fully synced to Square.',
			$bucket_id,
			$square_location_id,
			$catalog_rows,
			$square_results,
			$skipped_rows,
			$payload,
			$response,
			$success_count
		);
	}

	private function get_bucket_context( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! is_object( $this->physical_buckets ) ) {
			return array();
		}

		if ( method_exists( $this->physical_buckets, 'find_with_context' ) ) {
			$bucket = $this->physical_buckets->find_with_context( $bucket_id );
			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		if ( method_exists( $this->physical_buckets, 'find' ) ) {
			$bucket = $this->physical_buckets->find( $bucket_id );
			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		return array();
	}

	private function get_bucket_inventory_rows( int $bucket_id ): array {
		if ( $bucket_id <= 0 || ! is_object( $this->bucket_positions ) ) {
			return array();
		}

		if ( method_exists( $this->bucket_positions, 'get_bucket_contents_summary' ) ) {
			return (array) $this->bucket_positions->get_bucket_contents_summary( $bucket_id );
		}

		if ( method_exists( $this->bucket_positions, 'get_for_bucket' ) ) {
			return (array) $this->bucket_positions->get_for_bucket( $bucket_id );
		}

		return array();
	}

	private function resolve_product_sku( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( is_object( $product ) && method_exists( $product, 'get_sku' ) ) {
				$sku = sanitize_text_field( (string) $product->get_sku() );
				if ( '' !== $sku ) {
					return $sku;
				}
			}
		}

		if ( function_exists( 'get_post_meta' ) ) {
			return sanitize_text_field( (string) get_post_meta( $product_id, '_sku', true ) );
		}

		return '';
	}

	private function extract_square_results( array $response ): array {
		$results = $response['json']['result']['square'] ?? $response['json']['square'] ?? $response['square'] ?? array();
		return is_array( $results ) ? array_values( $results ) : array();
	}

	private function result(
		bool $success,
		bool $attempted,
		bool $skipped,
		string $reason,
		string $message,
		int $bucket_id,
		string $square_location_id,
		array $catalog_rows = array(),
		array $square_results = array(),
		array $skipped_rows = array(),
		array $payload = array(),
		array $response = array(),
		int $synced_skus = 0
	): array {
		return array(
			'success'            => $success,
			'attempted'          => $attempted,
			'skipped'            => $skipped,
			'reason'             => sanitize_key( $reason ),
			'message'            => $message,
			'bucket_id'          => $bucket_id,
			'square_location_id' => sanitize_text_field( $square_location_id ),
			'synced_skus'        => $synced_skus,
			'catalog_rows'       => $catalog_rows,
			'square_results'     => $square_results,
			'skipped_rows'       => $skipped_rows,
			'payload'            => $payload,
			'response'           => $response,
		);
	}
}
