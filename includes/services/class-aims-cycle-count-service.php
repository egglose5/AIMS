<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Cycle_Count_Service {
	private $bucket_repo;
	private $position_repo;
	private $movement_repo;

	public function __construct(
		AIMS_Physical_Bucket_Repository $bucket_repo = null,
		AIMS_Bucket_Inventory_Position_Repository $position_repo = null,
		AIMS_Bucket_Inventory_Movement_Repository $movement_repo = null
	) {
		$this->bucket_repo   = $bucket_repo   ?: new AIMS_Physical_Bucket_Repository();
		$this->position_repo = $position_repo ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->movement_repo = $movement_repo ?: new AIMS_Bucket_Inventory_Movement_Repository();
	}

	/**
	 * Resolve a scanned barcode or typed bucket code to a bucket record with its current position summary.
	 *
	 * @param string $scan_value Raw scan value (barcode or bucket_code).
	 * @return array{found:bool,bucket:array,positions:array,message:string}
	 */
	public function resolve_bucket( string $scan_value ): array {
		$scan_value = sanitize_text_field( $scan_value );

		if ( '' === $scan_value ) {
			return $this->not_found( 'No scan value provided.' );
		}

		// Try by barcode_value first.
		$bucket = null;
		if ( method_exists( $this->bucket_repo, 'find_by_barcode' ) ) {
			$bucket = $this->bucket_repo->find_by_barcode( $scan_value );
		}

		// Fall back to bucket_code lookup.
		if ( ! is_array( $bucket ) || empty( $bucket ) ) {
			if ( method_exists( $this->bucket_repo, 'find_by_code' ) ) {
				$bucket = $this->bucket_repo->find_by_code( $scan_value );
			}
		}

		if ( ! is_array( $bucket ) || empty( $bucket ) ) {
			return $this->not_found( 'No bucket matched barcode or code "' . esc_html( $scan_value ) . '".' );
		}

		$bucket_id = (int) ( $bucket['id'] ?? 0 );
		$positions = $this->get_bucket_positions( $bucket_id );

		return array(
			'found'     => true,
			'bucket'    => $this->normalize_bucket_for_response( $bucket ),
			'positions' => $positions,
			'message'   => 'Bucket found.',
		);
	}

	/**
	 * Get the current position summary for a bucket.
	 */
	public function get_bucket_positions( int $bucket_id ): array {
		if ( $bucket_id <= 0 ) {
			return array();
		}

		$rows = $this->position_repo->get_for_bucket( $bucket_id );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$positions = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$product_id = (int) ( $row['product_id'] ?? 0 );
			$positions[] = array(
				'position_id'     => (int) ( $row['id'] ?? 0 ),
				'product_id'      => $product_id,
				'sku'             => $this->resolve_product_sku( $product_id ),
				'quantity'        => (float) ( $row['quantity'] ?? 0 ),
				'position_status' => sanitize_key( (string) ( $row['position_status'] ?? 'active' ) ),
				'last_counted_at' => sanitize_text_field( (string) ( $row['last_counted_at'] ?? '' ) ),
			);
		}

		return $positions;
	}

	/**
	 * Submit a cycle count or initial inventory deployment for a bucket.
	 *
	 * Each line represents the new absolute quantity for a SKU in this bucket.
	 * This sets quantity to the given value (not a delta), then records a movement
	 * for the difference.
	 *
	 * @param int    $bucket_id  ID of the bucket being counted.
	 * @param array  $lines      [['sku' => string, 'quantity' => float], ...]
	 * @param string $notes      Optional operator notes.
	 * @param int    $applied_by WP user ID performing the count.
	 * @return array{success:bool,applied_lines:int,skipped_lines:int,errors:array,movements:array}
	 */
	public function submit_count( int $bucket_id, array $lines, string $notes = '', int $applied_by = 0 ): array {
		if ( $bucket_id <= 0 ) {
			return $this->failure( 'Invalid bucket ID.' );
		}

		$notes            = sanitize_textarea_field( $notes );
		$runtime_user_id  = $applied_by > 0 ? $applied_by : $this->current_user_id();
		$batch_ref        = 'cc_' . time() . '_' . $runtime_user_id;
		$normalized_lines = $this->normalize_count_lines( $lines );

		$applied   = 0;
		$skipped   = 0;
		$errors    = array();
		$movements = array();
		$submitted_product_ids = array();

		foreach ( $normalized_lines as $line ) {
			$sku      = (string) $line['sku'];
			$quantity = (float) $line['quantity'];
			$product_id = $this->resolve_sku_to_product_id( $sku );
			if ( $product_id <= 0 ) {
				$skipped++;
				$errors[] = 'Skipped SKU "' . $sku . '" because no WooCommerce product matched.';
				continue;
			}

			$submitted_product_ids[] = $product_id;

			// Get existing position so we can calculate the delta.
			$existing = $this->position_repo->find_by_bucket_vendor_product( $bucket_id, 0, $product_id );

			$old_quantity   = is_array( $existing ) ? (float) ( $existing['quantity'] ?? 0 ) : 0.0;
			$reserved_qty   = is_array( $existing ) ? (float) ( $existing['reserved_quantity'] ?? 0 ) : 0.0;
			$quantity_delta = $quantity - $old_quantity;
			$position_id    = is_array( $existing ) ? (int) ( $existing['id'] ?? 0 ) : 0;

			// Upsert the position with the new absolute quantity.
			$this->position_repo->save(
				array(
					'bucket_id'       => $bucket_id,
					'vendor_id'       => 0,
					'product_id'      => $product_id,
					'quantity'        => $quantity,
					'reserved_quantity' => $reserved_qty,
					'position_status' => 'active',
					'last_counted_at' => current_time( 'mysql' ),
				),
				$position_id
			);

			// Record the movement for audit.
			$movement_id = 0;
			if ( $quantity_delta !== 0.0 || $quantity > 0 ) {
				$movement_id = $this->movement_repo->create(
					array(
						'bucket_id'          => $bucket_id,
						'product_id'         => $product_id,
						'movement_type'      => 'cycle_count',
						'quantity_delta'     => $quantity_delta,
						'reference_type'     => 'cycle_count',
						'reference_id'       => $batch_ref,
						'note'               => $notes,
						'applied_by'         => $runtime_user_id,
						'metadata_json'      => array(
							'sku'          => $sku,
							'count_source' => 'mobile_scan',
							'old_quantity' => $old_quantity,
							'new_quantity' => $quantity,
						),
					)
				);
			}

			$movements[] = array(
				'sku'         => $sku,
				'product_id'  => $product_id,
				'quantity'    => $quantity,
				'delta'       => $quantity_delta,
				'movement_id' => $movement_id,
			);

			$applied++;
		}

		$reconciled = $this->reconcile_missing_positions(
			$bucket_id,
			$submitted_product_ids,
			$batch_ref,
			$notes,
			$runtime_user_id
		);

		$applied   += (int) ( $reconciled['applied'] ?? 0 );
		$movements = array_merge( $movements, (array) ( $reconciled['movements'] ?? array() ) );

		return array(
			'success'       => $applied > 0 || ( 0 === count( $normalized_lines ) && empty( $errors ) ),
			'applied_lines' => $applied,
			'skipped_lines' => $skipped,
			'batch_ref'     => $batch_ref,
			'errors'        => $errors,
			'movements'     => $movements,
		);
	}

	/**
	 * @return array<int, array{sku:string,quantity:float}>
	 */
	private function normalize_count_lines( array $lines ): array {
		$normalized = array();

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$sku = sanitize_text_field( (string) ( $line['sku'] ?? '' ) );
			if ( '' === $sku ) {
				continue;
			}

			$normalized[ $sku ] = max( 0.0, (float) ( $line['quantity'] ?? 0 ) );
		}

		$resolved = array();
		foreach ( $normalized as $sku => $quantity ) {
			$resolved[] = array(
				'sku'      => (string) $sku,
				'quantity' => (float) $quantity,
			);
		}

		return $resolved;
	}

	/**
	 * @param array<int, int> $submitted_product_ids
	 * @return array{applied:int,movements:array<int,array<string,mixed>>}
	 */
	private function reconcile_missing_positions( int $bucket_id, array $submitted_product_ids, string $batch_ref, string $notes, int $runtime_user_id ): array {
		$existing_rows = $this->position_repo->get_for_bucket( $bucket_id );
		if ( ! is_array( $existing_rows ) || empty( $existing_rows ) ) {
			return array(
				'applied'   => 0,
				'movements' => array(),
			);
		}

		$submitted_lookup = array_fill_keys( array_map( 'intval', $submitted_product_ids ), true );
		$applied          = 0;
		$movements        = array();

		foreach ( $existing_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = (int) ( $row['product_id'] ?? 0 );
			if ( $product_id <= 0 || isset( $submitted_lookup[ $product_id ] ) ) {
				continue;
			}

			$old_quantity = (float) ( $row['quantity'] ?? 0 );
			if ( 0.0 === $old_quantity ) {
				continue;
			}

			$this->position_repo->save(
				array(
					'bucket_id'         => $bucket_id,
					'vendor_id'         => (int) ( $row['vendor_id'] ?? 0 ),
					'product_id'        => $product_id,
					'quantity'          => 0,
					'reserved_quantity' => (float) ( $row['reserved_quantity'] ?? 0 ),
					'position_status'   => 'active',
					'last_counted_at'   => current_time( 'mysql' ),
				),
				(int) ( $row['id'] ?? 0 )
			);

			$movement_id = $this->movement_repo->create(
				array(
					'bucket_id'      => $bucket_id,
					'product_id'     => $product_id,
					'movement_type'  => 'cycle_count',
					'quantity_delta' => 0 - $old_quantity,
					'reference_type' => 'cycle_count',
					'reference_id'   => $batch_ref,
					'note'           => $notes,
					'applied_by'     => $runtime_user_id,
					'metadata_json'  => array(
						'sku'                    => $this->resolve_product_sku( $product_id ),
						'count_source'           => 'mobile_scan',
						'old_quantity'           => $old_quantity,
						'new_quantity'           => 0,
						'zeroed_by_reconciliation' => true,
					),
				)
			);

			$movements[] = array(
				'sku'         => $this->resolve_product_sku( $product_id ),
				'product_id'  => $product_id,
				'quantity'    => 0.0,
				'delta'       => 0 - $old_quantity,
				'movement_id' => $movement_id,
			);

			$applied++;
		}

		return array(
			'applied'   => $applied,
			'movements' => $movements,
		);
	}

	private function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Resolve a product SKU to a WooCommerce product ID.
	 */
	public function resolve_sku_to_product_id( string $sku ): int {
		$sku = sanitize_text_field( $sku );

		if ( '' === $sku ) {
			return 0;
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = (int) wc_get_product_id_by_sku( $sku );
			if ( $product_id > 0 ) {
				return $product_id;
			}
		}

		// Fallback: direct postmeta query.
		global $wpdb;
		$product_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
				$sku
			)
		);

		return $product_id;
	}

	/**
	 * Resolve a WC product ID to its SKU for display purposes.
	 */
	private function resolve_product_sku( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'get_post_meta' ) ) {
			return (string) get_post_meta( $product_id, '_sku', true );
		}

		return '';
	}

	private function normalize_bucket_for_response( array $bucket ): array {
		return array(
			'id'                   => (int) ( $bucket['id'] ?? 0 ),
			'bucket_code'          => sanitize_text_field( (string) ( $bucket['bucket_code'] ?? '' ) ),
			'bucket_label'         => sanitize_text_field( (string) ( $bucket['bucket_label'] ?? '' ) ),
			'bucket_type'          => sanitize_key( (string) ( $bucket['bucket_type'] ?? '' ) ),
			'status'               => sanitize_key( (string) ( $bucket['status'] ?? '' ) ),
			'barcode_value'        => sanitize_text_field( (string) ( $bucket['barcode_value'] ?? '' ) ),
			'current_location_id'  => (int) ( $bucket['current_storage_location_id'] ?? 0 ),
			'home_location_id'     => (int) ( $bucket['home_storage_location_id'] ?? 0 ),
		);
	}

	private function not_found( string $message ): array {
		return array(
			'found'     => false,
			'bucket'    => array(),
			'positions' => array(),
			'message'   => $message,
		);
	}

	private function failure( string $message ): array {
		return array(
			'success'       => false,
			'applied_lines' => 0,
			'skipped_lines' => 0,
			'batch_ref'     => '',
			'errors'        => array( $message ),
			'movements'     => array(),
		);
	}
}
