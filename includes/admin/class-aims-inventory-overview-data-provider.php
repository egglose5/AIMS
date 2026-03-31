<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Overview_Data_Provider {
	private $transfer_repo;
	private $transfer_items_repo;
	private $bucket_repo;

	public function __construct(
		AIMS_Inventory_Transfer_Repository $transfer_repo = null,
		AIMS_Inventory_Transfer_Items_Repository $transfer_items_repo = null,
		AIMS_Physical_Bucket_Repository $bucket_repo = null
	) {
		$this->transfer_repo        = $transfer_repo ?: new AIMS_Inventory_Transfer_Repository();
		$this->transfer_items_repo  = $transfer_items_repo ?: new AIMS_Inventory_Transfer_Items_Repository();
		$this->bucket_repo          = $bucket_repo ?: new AIMS_Physical_Bucket_Repository();
	}

	public function get_outline(): array {
		return array(
			'Physical buckets are permanent objects with warehouse locations and lifecycle state.',
			'Bucket contents will be tracked separately from show assignment so the same bucket can move over time.',
			'Bucket movements are the immutable ledger for stock-in, stock-out, transfer, and event load-out/return activity.',
			'Pick, pack, and reconciliation views should read from bucket and event assignments, not Square identifiers.',
		);
	}

	/**
	 * Get all outgoing transfers for a vendor (initiated by vendor).
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array Transfer records with item details.
	 */
	public function get_outgoing_transfers( int $vendor_id ): array {
		$transfers = $this->transfer_repo->get_outgoing_for_vendor( $vendor_id );

		if ( empty( $transfers ) ) {
			return array();
		}

		$enhanced = array();
		foreach ( $transfers as $transfer ) {
			if ( ! is_array( $transfer ) ) {
				continue;
			}

			$transfer_id = (int) ( $transfer['id'] ?? 0 );
			$items       = $this->transfer_items_repo->get_for_transfer( $transfer_id );

			$enhanced[] = array_merge( $transfer, array(
				'items'      => $items,
				'item_count' => count( $items ),
			) );
		}

		return $enhanced;
	}

	/**
	 * Get all incoming transfers for a vendor (recipient).
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array Transfer records with item details.
	 */
	public function get_incoming_transfers( int $vendor_id ): array {
		$transfers = $this->transfer_repo->get_incoming_for_vendor( $vendor_id );

		if ( empty( $transfers ) ) {
			return array();
		}

		$enhanced = array();
		foreach ( $transfers as $transfer ) {
			if ( ! is_array( $transfer ) ) {
				continue;
			}

			$transfer_id = (int) ( $transfer['id'] ?? 0 );
			$items       = $this->transfer_items_repo->get_for_transfer( $transfer_id );

			$enhanced[] = array_merge( $transfer, array(
				'items'      => $items,
				'item_count' => count( $items ),
			) );
		}

		return $enhanced;
	}

	/**
	 * Get available buckets for a transfer form.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array Bucket records.
	 */
	public function get_available_buckets( int $vendor_id ): array {
		$buckets = array();

		if ( ! is_object( $this->bucket_repo ) || ! method_exists( $this->bucket_repo, 'get_for_vendor' ) ) {
			return $buckets;
		}

		$bucket_records = $this->bucket_repo->get_for_vendor( $vendor_id );

		if ( empty( $bucket_records ) ) {
			return array();
		}

		foreach ( $bucket_records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$buckets[] = array(
				'id'            => (int) ( $record['id'] ?? 0 ),
				'bucket_code'   => sanitize_text_field( $record['bucket_code'] ?? '' ),
				'bucket_name'   => sanitize_text_field( $record['bucket_name'] ?? '' ),
				'bucket_type'   => sanitize_key( $record['bucket_type'] ?? '' ),
				'status'        => sanitize_key( $record['status'] ?? '' ),
			);
		}

		return $buckets;
	}

	/**
	 * Get available WooCommerce products for transfer forms.
	 *
	 * @return array Product records with id, name, sku.
	 */
	public function get_available_products(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products( array(
			'status'   => 'publish',
			'type'     => array( 'simple', 'variable' ),
			'limit'    => 500,
			'orderby'  => 'title',
			'order'    => 'ASC',
		) );

		if ( empty( $products ) ) {
			return array();
		}

		$output = array();
		foreach ( $products as $product ) {
			if ( ! $product || $product->is_virtual() ) {
				continue; // Skip virtual products
			}

			$output[] = array(
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
				'sku'  => $product->get_sku(),
			);
		}

		return $output;
	}

	/**
	 * Get a specific transfer with items.
	 *
	 * @param int $transfer_id Transfer ID.
	 * @return array|null Transfer record with items, or null if not found.
	 */
	public function get_transfer_detail( int $transfer_id ): ?array {
		$transfer = $this->transfer_repo->find( $transfer_id );

		if ( ! is_array( $transfer ) ) {
			return null;
		}

		$items = $this->transfer_items_repo->get_for_transfer( $transfer_id );

		return array_merge( $transfer, array(
			'items'      => $items,
			'item_count' => count( $items ),
		) );
	}

	/**
	 * Get user-friendly status label for a transfer status.
	 *
	 * @param string $status Transfer status code.
	 * @return string Displayable status label.
	 */
	public static function get_transfer_status_label( string $status ): string {
		$labels = array(
			'pending'    => 'Draft',
			'dispatched' => 'In Transit',
			'in_transit' => 'In Transit',
			'received'   => 'Received',
			'received_with_variance' => 'Received (with variance)',
			'cancelled'  => 'Cancelled',
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Get user-friendly label for a line item status.
	 *
	 * @param string $status Line status code.
	 * @return string Displayable status label.
	 */
	public static function get_line_status_label( string $status ): string {
		$labels = array(
			'pending'    => 'Pending',
			'dispatched' => 'Dispatched',
			'received'   => 'Received',
			'received_with_variance' => 'Received (variance)',
			'cancelled'  => 'Cancelled',
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}
}

