<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Overview_Data_Provider {
	private $transfer_repo;
	private $transfer_items_repo;
	private $bucket_repo;
	private $endpoint_directory;
	private $bucket_sourcing;
	private $person_identity;

	public function __construct(
		AIMS_Inventory_Transfer_Repository $transfer_repo = null,
		AIMS_Inventory_Transfer_Items_Repository $transfer_items_repo = null,
		AIMS_Physical_Bucket_Repository $bucket_repo = null,
		$endpoint_directory = null,
		AIMS_Person_Identity_Service $person_identity = null,
		AIMS_Inventory_Bucket_Sourcing_Service $bucket_sourcing = null
	) {
		$this->transfer_repo       = $transfer_repo ?: new AIMS_Inventory_Transfer_Repository();
		$this->transfer_items_repo = $transfer_items_repo ?: new AIMS_Inventory_Transfer_Items_Repository();
		$this->bucket_repo         = $bucket_repo ?: new AIMS_Physical_Bucket_Repository();
		$this->endpoint_directory   = $endpoint_directory;
		$this->person_identity      = $person_identity ?: ( class_exists( 'AIMS_Person_Identity_Service' ) ? new AIMS_Person_Identity_Service() : null );
		$this->bucket_sourcing      = $bucket_sourcing;
	}

	public function get_outline(): array {
		return array(
			'Physical buckets are permanent objects with warehouse locations and lifecycle state.',
			'Bucket contents will be tracked separately from show assignment so the same bucket can move over time.',
			'Bucket movements are the immutable ledger for stock-in, stock-out, transfer, and event load-out/return activity.',
			'Pick, pack, and reconciliation views should read from bucket and event assignments, not Square identifiers.',
		);
	}

	public function get_operator_context(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$user    = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$can_bypass_route = current_user_can( AIMS_Capabilities::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE );
		$is_elevated = $can_bypass_route
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_PRODUCTION )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE );
		$endpoint = $this->resolve_operator_endpoint( $user_id );

		return array(
			'user_id'             => $user_id,
			'display_name'        => is_object( $user ) ? sanitize_text_field( (string) $user->display_name ) : '',
			'is_elevated'         => $is_elevated,
			'can_override_route'  => $can_bypass_route,
			'node_id'             => (int) ( $endpoint['node_id'] ?? $this->resolve_operator_node_id( $user_id ) ),
			'node_type'           => (string) ( $endpoint['node_type'] ?? $this->resolve_operator_node_type( $user_id ) ),
			'node_label'          => (string) ( $endpoint['endpoint_label'] ?? $this->resolve_operator_node_label( $user_id ) ),
			'endpoint_key'        => (string) ( $endpoint['endpoint_key'] ?? '' ),
			'endpoint_type'       => (string) ( $endpoint['endpoint_type'] ?? $endpoint['node_type'] ?? '' ),
		);
	}

	public function get_route_model(): array {
		$source_pool = $this->get_source_bucket_pool();
		$target_pool = $this->get_target_bucket_pool();
		$endpoint_choices = $this->get_endpoint_choices();
		$route_suggestions = $this->get_route_suggestions();

		return array(
			'source_pool'             => $source_pool,
			'target_pool'             => $target_pool,
			'suggested_route_label'    => $this->resolve_route_label(),
			'suggested_route_note'     => $this->resolve_route_note(),
			'endpoint_choices'         => $endpoint_choices,
			'route_suggestions'        => $route_suggestions,
			'can_override_route'       => (bool) ( $this->get_operator_context()['can_override_route'] ?? false ),
			'override_reason_required' => (bool) ( $this->get_operator_context()['can_override_route'] ?? false ),
		);
	}

	public function get_source_bucket_pool(): array {
		$operator = $this->get_operator_context();
		$pool = $this->call_endpoint_directory(
			array(
				'get_source_bucket_pool',
				'get_source_endpoint_pool',
				'get_source_pool',
			),
			array(
				(int) ( $operator['node_id'] ?? 0 ),
				(string) ( $operator['node_type'] ?? 'endpoint' ),
				(int) ( $operator['user_id'] ?? 0 ),
			)
		);

		if ( is_array( $pool ) && ! empty( $pool ) ) {
			return $this->normalize_bucket_pool( $pool, 'source' );
		}

		$source_buckets = $this->get_source_buckets(
			(int) ( $operator['node_id'] ?? 0 ),
			(string) ( $operator['node_type'] ?? 'endpoint' ),
			array(
				'user_id' => (int) ( $operator['user_id'] ?? 0 ),
			)
		);

		if ( ! empty( $source_buckets ) ) {
			return $source_buckets;
		}

		return $this->resolve_bucket_pool( 'source' );
	}

	public function get_target_bucket_pool(): array {
		$operator = $this->get_operator_context();
		$pool = $this->call_endpoint_directory(
			array(
				'get_target_bucket_pool',
				'get_target_endpoint_pool',
				'get_target_pool',
			),
			array(
				(int) ( $operator['node_id'] ?? 0 ),
				(string) ( $operator['node_type'] ?? 'endpoint' ),
				(int) ( $operator['user_id'] ?? 0 ),
			)
		);

		if ( is_array( $pool ) && ! empty( $pool ) ) {
			return $this->normalize_bucket_pool( $pool, 'target' );
		}

		$target_buckets = $this->get_target_buckets(
			(int) ( $operator['node_id'] ?? 0 ),
			(string) ( $operator['node_type'] ?? 'endpoint' ),
			array(
				'user_id' => (int) ( $operator['user_id'] ?? 0 ),
			)
		);

		if ( ! empty( $target_buckets ) ) {
			return $target_buckets;
		}

		return $this->resolve_bucket_pool( 'target' );
	}

	public function get_runtime_endpoint_directory(): array {
		$user_id = (int) ( $this->get_operator_context()['user_id'] ?? 0 );
		$directory = $this->call_endpoint_directory(
			array(
				'get_runtime_endpoints',
				'get_directory',
			),
			array( $user_id )
		);

		return is_array( $directory ) ? $directory : array();
	}

	public function get_endpoint_choices(): array {
		$choices = $this->call_endpoint_directory(
			array( 'get_endpoint_choices' ),
			array( (int) ( $this->get_operator_context()['user_id'] ?? 0 ) )
		);

		if ( is_array( $choices ) && ! empty( $choices ) ) {
			return $choices;
		}

		$choices = array();
		foreach ( $this->get_runtime_endpoint_directory() as $endpoint_key => $endpoint ) {
			$choices[ $endpoint_key ] = (string) ( $endpoint['endpoint_label'] ?? $endpoint_key );
		}

		return $choices;
	}

	public function get_route_suggestions(): array {
		$suggestions = $this->call_endpoint_directory(
			array( 'get_route_suggestions' ),
			array( (int) ( $this->get_operator_context()['user_id'] ?? 0 ) )
		);

		return is_array( $suggestions ) ? $suggestions : array();
	}

	public function get_source_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		return $this->resolve_bucket_source_service()->get_source_buckets( $node_id, $node_type, $context );
	}

	public function get_target_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		return $this->resolve_bucket_source_service()->get_target_buckets( $node_id, $node_type, $context );
	}

	public function get_bucket_sourcing_context( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
		return $this->resolve_bucket_source_service()->get_bucket_sourcing_context( $node_id, $node_type, $context );
	}

	/**
	 * Get all outgoing transfers for a node.
	 *
	 * @param int    $node_id Node ID.
	 * @param string $node_type Node type.
	 * @return array Transfer records with item details.
	 */
	public function get_outgoing_transfers( int $node_id, string $node_type = 'vendor' ): array {
		$transfers = $this->transfer_repo->get_outgoing_for_node( $node_id, array( 'pending', 'dispatched', 'in_transit' ), $node_type );

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
	 * Get all incoming transfers for a node.
	 *
	 * @param int    $node_id Node ID.
	 * @param string $node_type Node type.
	 * @return array Transfer records with item details.
	 */
	public function get_incoming_transfers( int $node_id, string $node_type = 'vendor' ): array {
		$transfers = $this->transfer_repo->get_incoming_for_node( $node_id, array( 'pending', 'in_transit', 'dispatched' ), $node_type );

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
		return $this->get_source_buckets(
			$vendor_id,
			'vendor',
			array(
				'vendor_id' => $vendor_id,
			)
		);
	}

	public function get_bucket_pool_options( string $pool_key ): array {
		$pool_key = sanitize_key( $pool_key );

		if ( 'target' === $pool_key ) {
			return $this->get_target_bucket_pool();
		}

		return $this->get_source_bucket_pool();
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

	private function resolve_bucket_pool( string $pool_key ): array {
		$pool_key = sanitize_key( $pool_key );

		if ( '' === $pool_key ) {
			return array();
		}

		$runtime_pool = $this->call_endpoint_directory( array(
			'get_' . $pool_key . '_bucket_pool',
			'get_' . $pool_key . '_endpoint_pool',
			'get_' . $pool_key . '_pool',
		), array( $pool_key ) );

		if ( is_array( $runtime_pool ) && ! empty( $runtime_pool ) ) {
			return $this->normalize_bucket_pool( $runtime_pool, $pool_key );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'aims_inventory_transfer_' . $pool_key . '_bucket_pool', array(), $this );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				return $this->normalize_bucket_pool( $filtered, $pool_key );
			}
		}

		$bucket_rows = array();
		if ( is_object( $this->bucket_repo ) && method_exists( $this->bucket_repo, 'get_available_for_planning' ) ) {
			$bucket_rows = $this->bucket_repo->get_available_for_planning( array() );
		} elseif ( is_object( $this->bucket_repo ) && method_exists( $this->bucket_repo, 'get_all_with_context' ) ) {
			$bucket_rows = $this->bucket_repo->get_all_with_context( array( 'status' => 'available' ) );
		} elseif ( is_object( $this->bucket_repo ) && method_exists( $this->bucket_repo, 'get_all' ) ) {
			$bucket_rows = $this->bucket_repo->get_all( array( 'status' => 'available' ) );
		}

		return $this->normalize_bucket_pool( is_array( $bucket_rows ) ? $bucket_rows : array(), $pool_key );
	}

	private function normalize_bucket_pool( array $bucket_rows, string $pool_key ): array {
		$normalized = array();

		foreach ( $bucket_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$current_storage = is_array( $row['current_storage_location'] ?? null ) ? (array) $row['current_storage_location'] : array();
			$home_storage    = is_array( $row['home_storage_location'] ?? null ) ? (array) $row['home_storage_location'] : array();

			$normalized[] = array(
				'id'              => (int) ( $row['id'] ?? 0 ),
				'bucket_code'     => sanitize_text_field( (string) ( $row['bucket_code'] ?? '' ) ),
				'bucket_label'    => sanitize_text_field( (string) ( $row['bucket_label'] ?? '' ) ),
				'bucket_type'     => sanitize_key( (string) ( $row['bucket_type'] ?? '' ) ),
				'status'          => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'pool_key'        => $pool_key,
				'pool_label'      => $this->resolve_pool_label( $pool_key ),
				'endpoint_label'   => $this->resolve_bucket_endpoint_label( $row, $pool_key ),
				'storage_current'  => $current_storage,
				'storage_home'     => $home_storage,
				'can_override'     => $this->can_use_route_override(),
			);
		}

		return $normalized;
	}

	private function resolve_pool_label( string $pool_key ): string {
		$labels = array(
			'source' => 'Source Endpoint Pool',
			'target' => 'Target Endpoint Pool',
		);

		return $labels[ $pool_key ] ?? ucwords( str_replace( '_', ' ', $pool_key ) );
	}

	private function resolve_bucket_endpoint_label( array $row, string $pool_key ): string {
		if ( ! empty( $row['endpoint_label'] ) ) {
			return sanitize_text_field( (string) $row['endpoint_label'] );
		}

		$current_storage = is_array( $row['current_storage_location'] ?? null ) ? (array) $row['current_storage_location'] : array();
		$home_storage    = is_array( $row['home_storage_location'] ?? null ) ? (array) $row['home_storage_location'] : array();

		$preferred = 'source' === $pool_key ? $current_storage : $home_storage;
		if ( ! empty( $preferred['location_name'] ) ) {
			return sanitize_text_field( (string) $preferred['location_name'] );
		}

		if ( ! empty( $preferred['location_code'] ) ) {
			return sanitize_text_field( (string) $preferred['location_code'] );
		}

		return 'source' === $pool_key ? 'Dispatch endpoint' : 'Receipt endpoint';
	}

	private function resolve_route_label(): string {
		$runtime_label = $this->call_endpoint_directory( array( 'get_suggested_route_label', 'get_default_route_label' ), array() );
		if ( is_string( $runtime_label ) && '' !== trim( $runtime_label ) ) {
			return sanitize_text_field( $runtime_label );
		}

		return 'Default route';
	}

	private function resolve_route_note(): string {
		$runtime_note = $this->call_endpoint_directory( array( 'get_suggested_route_note', 'get_default_route_note' ), array() );
		if ( is_string( $runtime_note ) && '' !== trim( $runtime_note ) ) {
			return sanitize_textarea_field( $runtime_note );
		}

		return 'Default route is suggested by the transfer workspace. Elevated operators can bypass it with an audit reason.';
	}

	private function can_use_route_override(): bool {
		$context = $this->get_operator_context();
		return ! empty( $context['can_override_route'] );
	}

	private function call_endpoint_directory( array $method_names, array $args ) {
		$directory = $this->resolve_endpoint_directory();
		if ( ! is_object( $directory ) ) {
			return null;
		}

		foreach ( $method_names as $method ) {
			if ( method_exists( $directory, $method ) ) {
				$call_args = $args;
				try {
					$reflection = new ReflectionMethod( $directory, $method );
					$call_args  = array_slice( $args, 0, $reflection->getNumberOfParameters() );
				} catch ( ReflectionException $exception ) {
					$call_args = $args;
				}

				return $directory->{$method}( ...$call_args );
			}
		}

		return null;
	}

	private function resolve_endpoint_directory() {
		if ( is_object( $this->endpoint_directory ) ) {
			return $this->endpoint_directory;
		}

		if ( class_exists( 'AIMS_Inventory_Endpoint_Directory_Service' ) ) {
			return new AIMS_Inventory_Endpoint_Directory_Service();
		}

		return null;
	}

	private function resolve_bucket_source_service() {
		if ( is_object( $this->bucket_sourcing ) ) {
			return $this->bucket_sourcing;
		}

		if ( class_exists( 'AIMS_Inventory_Bucket_Sourcing_Service' ) ) {
			return new AIMS_Inventory_Bucket_Sourcing_Service( $this->bucket_repo, $this->resolve_endpoint_directory() );
		}

		return new class( $this->bucket_repo ) {
			private $bucket_repo;

			public function __construct( $bucket_repo ) {
				$this->bucket_repo = $bucket_repo;
			}

			public function get_source_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
				if ( is_object( $this->bucket_repo ) && method_exists( $this->bucket_repo, 'get_available_for_planning' ) ) {
					return $this->bucket_repo->get_available_for_planning( array() );
				}

				if ( is_object( $this->bucket_repo ) && method_exists( $this->bucket_repo, 'get_all_with_context' ) ) {
					return $this->bucket_repo->get_all_with_context( array( 'status' => 'available' ) );
				}

				if ( is_object( $this->bucket_repo ) && method_exists( $this->bucket_repo, 'get_all' ) ) {
					return $this->bucket_repo->get_all( array( 'status' => 'available' ) );
				}

				return array();
			}

			public function get_target_buckets( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
				return $this->get_source_buckets( $node_id, $node_type, $context );
			}

			public function get_bucket_sourcing_context( int $node_id = 0, string $node_type = 'vendor', array $context = array() ): array {
				return array(
					'node_id'          => max( 0, $node_id ),
					'node_type'        => sanitize_key( $node_type ),
					'source_buckets'   => $this->get_source_buckets( $node_id, $node_type, $context ),
					'target_buckets'   => $this->get_target_buckets( $node_id, $node_type, $context ),
					'route_suggestions'=> array(),
				);
			}
		};
	}

	private function resolve_operator_endpoint( int $user_id ): array {
		$directory = $this->resolve_endpoint_directory();
		if ( is_object( $directory ) && method_exists( $directory, 'resolve_runtime_endpoint' ) ) {
			$endpoint = $directory->resolve_runtime_endpoint( $user_id );
			if ( is_array( $endpoint ) ) {
				return $endpoint;
			}
		}

		return array();
	}

	private function resolve_operator_node_id( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		if ( is_object( $this->person_identity ) && $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR ) ) {
			return $user_id;
		}

		return 0;
	}

	private function resolve_operator_node_type( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'endpoint';
		}

		if ( is_object( $this->person_identity ) && $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR ) ) {
			return 'vendor';
		}

		return 'endpoint';
	}

	private function resolve_operator_node_label( int $user_id ): string {
		if ( function_exists( 'wp_get_current_user' ) && $user_id > 0 ) {
			$user = wp_get_current_user();
			if ( is_object( $user ) && ! empty( $user->display_name ) ) {
				return sanitize_text_field( (string) $user->display_name );
			}
		}

		return 'Current operator';
	}
}
