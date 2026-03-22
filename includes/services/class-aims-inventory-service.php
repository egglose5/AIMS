<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Service {
	private $buckets;
	private $movements;
	private $bucket_access;
	private $audit;

	public function __construct(
		AIMS_Inventory_Bucket_Repository $buckets,
		AIMS_Inventory_Movement_Repository $movements,
		AIMS_Bucket_Access_Service $bucket_access = null,
		AIMS_Audit_Service $audit = null
	) {
		$this->buckets       = $buckets;
		$this->movements     = $movements;
		$this->bucket_access = $bucket_access;
		$this->audit         = $audit;
	}

	public function apply_movement( array $data, int $actor_user_id = 0 ) {
		$bucket_context = $this->resolve_bucket_context( $data );
		$reference_type = sanitize_key( $data['reference_type'] ?? '' );
		$reference_id   = sanitize_text_field( $data['reference_id'] ?? '' );
		$product_id     = $bucket_context['product_id'];
		$bucket_id      = $bucket_context['id'];
		$vendor_id      = $bucket_context['vendor_id'];
		$bucket_key     = $bucket_context['bucket_key'];
		$bucket_code    = $bucket_context['bucket_code'];
		$bucket_type    = $bucket_context['bucket_type'];
		$movement_type  = sanitize_key( $data['movement_type'] ?? '' );
		$quantity_delta = (float) ( $data['quantity_delta'] ?? 0 );

		if ( '' === $reference_type || '' === $reference_id || $product_id <= 0 || ( $bucket_id <= 0 && '' === $bucket_key ) || '' === $movement_type || 0.0 === $quantity_delta ) {
			return new WP_Error( 'aims_invalid_inventory_movement', 'Inventory movement is missing required fields.' );
		}

		if ( ! $this->can_manage_bucket_context( $bucket_context, $actor_user_id ) ) {
			$this->record_audit(
				'inventory_movement_denied',
				$this->resolve_actor_user_id( $actor_user_id ),
				(int) ( $bucket_context['owner_entity_id'] ?? 0 ),
				'bucket',
				$bucket_id,
				array(
					'reference_type' => $reference_type,
					'reference_id'   => $reference_id,
					'movement_type'  => $movement_type,
					'quantity_delta' => $quantity_delta,
					'bucket_code'    => $bucket_code,
				),
				'Inventory movement denied by bucket RBAC.'
			);

			return new WP_Error( 'aims_bucket_access_denied', 'The current user cannot change inventory for this bucket.' );
		}

		if ( $bucket_id > 0 ) {
			if ( $this->movements->has_reference_application_for_bucket_id( $reference_type, $reference_id, $product_id, $bucket_id, $movement_type ) ) {
				return new WP_Error( 'aims_duplicate_inventory_movement', 'This inventory movement has already been applied.' );
			}
		} elseif ( '' !== $bucket_key && '' !== $bucket_type && $this->movements->has_reference_application_for_bucket_key_and_type( $reference_type, $reference_id, $product_id, $bucket_key, $bucket_type, $movement_type ) ) {
			return new WP_Error( 'aims_duplicate_inventory_movement', 'This inventory movement has already been applied.' );
		} elseif ( $this->movements->has_reference_application_for_identity( $reference_type, $reference_id, $product_id, 0, $bucket_code, $movement_type ) ) {
			return new WP_Error( 'aims_duplicate_inventory_movement', 'This inventory movement has already been applied.' );
		}

		$existing_bucket = $bucket_id > 0 ? $this->buckets->find_bucket_by_id( $bucket_id ) : $this->buckets->find_bucket_by_key( $bucket_key );

		if ( empty( $existing_bucket ) ) {
			$existing_bucket = $this->buckets->find_bucket_by_identity(
				$bucket_key,
				$bucket_type,
				$bucket_context['owner_entity_type'],
				(int) $bucket_context['owner_entity_id'],
				$bucket_context['square_location_id'],
				$vendor_id,
				$product_id,
				$bucket_code
			);
		}

		if ( empty( $existing_bucket ) ) {
			$bucket_id = $this->buckets->upsert_bucket(
				array(
					'bucket_id'          => $bucket_id,
					'bucket_key'         => $bucket_key,
					'bucket_type'        => $bucket_type,
					'bucket_label'       => $bucket_context['bucket_label'],
					'owner_entity_type'  => $bucket_context['owner_entity_type'],
					'owner_entity_id'    => $bucket_context['owner_entity_id'],
					'square_location_id' => $bucket_context['square_location_id'],
					'vendor_id'          => $vendor_id,
					'product_id'         => $product_id,
					'bucket_code'        => $bucket_code,
					'quantity'           => 0,
					'reserved_quantity'  => 0,
				)
			);
			$existing_bucket = $this->buckets->find_bucket_by_id( $bucket_id );
		}

		$bucket_id = ! empty( $existing_bucket['id'] ) ? (int) $existing_bucket['id'] : $bucket_id;

		$data['vendor_id']   = $vendor_id;
		$data['product_id']  = $product_id;
		$data['bucket_id']   = $bucket_id;
		$data['bucket_key']  = $bucket_key;
		$data['bucket_type'] = $bucket_type;
		$data['bucket_code'] = $bucket_code;
		$data['bucket_name'] = $bucket_context['bucket_label'];

		$movement_id = $this->movements->create( $data );
		$current_qty = $bucket_id > 0
			? $this->movements->get_total_quantity_for_bucket_by_id( $bucket_id )
			: $this->movements->get_total_quantity_for_bucket_by_key_and_type( $bucket_key, $bucket_type );

		$this->buckets->upsert_bucket(
			array(
				'bucket_id'         => $bucket_id,
				'bucket_key'        => $bucket_key,
				'bucket_type'       => $bucket_type,
				'bucket_label'      => $bucket_context['bucket_label'],
				'owner_entity_type' => $bucket_context['owner_entity_type'],
				'owner_entity_id'   => $bucket_context['owner_entity_id'],
				'square_location_id'=> $bucket_context['square_location_id'],
				'vendor_id'         => $vendor_id,
				'product_id'        => $product_id,
				'bucket_code'       => $bucket_code,
				'quantity'          => $current_qty,
				'reserved_quantity' => ! empty( $existing_bucket['reserved_quantity'] ) ? (float) $existing_bucket['reserved_quantity'] : 0,
			)
		);

		return array(
			'movement_id'      => $movement_id,
			'current_quantity' => $current_qty,
			'bucket_context'   => $bucket_context,
		);
	}

	public function transfer_warehouse_to_event_bucket( array $data, int $actor_user_id = 0 ) {
		return $this->transfer_between_buckets(
			$data,
			AIMS_Inventory_Movement_Repository::MOVEMENT_WAREHOUSE_TRANSFER_OUT,
			AIMS_Inventory_Movement_Repository::MOVEMENT_EVENT_TRANSFER_IN,
			'warehouse',
			'event',
			$actor_user_id
		);
	}

	public function record_event_return( array $data, int $actor_user_id = 0 ) {
		return $this->transfer_between_buckets(
			$data,
			AIMS_Inventory_Movement_Repository::MOVEMENT_EVENT_RETURN_OUT,
			AIMS_Inventory_Movement_Repository::MOVEMENT_WAREHOUSE_RETURN_IN,
			'event',
			'warehouse',
			$actor_user_id
		);
	}

	public function get_event_transfer_operator_rows( array $filters = array(), int $actor_user_id = 0 ): array {
		$event_buckets = $this->buckets->get_bucket_snapshots_by_type( 'event' );
		$actor_user_id = $this->resolve_actor_user_id( $actor_user_id );

		if ( empty( $event_buckets ) ) {
			return array();
		}

		$limit = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 25;
		$rows  = array();

		foreach ( $event_buckets as $bucket ) {
			$summary          = $this->movements->get_transfer_summary_for_bucket_id( (int) $bucket['id'] );
			$history          = $this->movements->get_recent_movements_for_bucket_id( (int) $bucket['id'], 3 );
			$warehouse_bucket  = $this->resolve_transfer_partner_bucket( $bucket, 'warehouse' );
			$transfer_capacity = $this->get_transfer_capacity( $warehouse_bucket );
			$return_capacity   = $this->get_transfer_capacity( $bucket );
			$source_missing    = ! empty( $warehouse_bucket['source_missing'] );
			$can_transfer      = $transfer_capacity > 0
				&& ! $source_missing
				&& $this->can_manage_bucket_context( $warehouse_bucket, $actor_user_id )
				&& $this->can_manage_bucket_context( $bucket, $actor_user_id );
			$can_return        = $return_capacity > 0
				&& $this->can_manage_bucket_context( $bucket, $actor_user_id )
				&& $this->can_manage_bucket_context( $warehouse_bucket, $actor_user_id );

			$rows[] = array(
				'bucket'           => $bucket,
				'warehouse_bucket' => $warehouse_bucket,
				'transfer_summary' => $summary,
				'recent_movements' => $history,
				'operator_state'   => $this->determine_operator_state( $bucket, $summary, $warehouse_bucket ),
				'operator_state_label' => $this->describe_operator_state( $this->determine_operator_state( $bucket, $summary, $warehouse_bucket ) ),
				'can_transfer'     => $can_transfer,
				'can_return'       => $can_return,
				'available_to_transfer' => $transfer_capacity,
				'available_to_return'    => $return_capacity,
				'workflow_actions'       => array(
					'warehouse_to_event' => array(
						'movement_type'         => AIMS_Inventory_Movement_Repository::MOVEMENT_WAREHOUSE_TRANSFER_OUT,
						'label'                 => 'Move from warehouse to event',
						'source_bucket'         => $warehouse_bucket,
						'destination_bucket'    => $bucket,
						'quantity_limit'        => $transfer_capacity,
						'can_initiate'          => $can_transfer,
						'source_missing'        => $source_missing,
						'source_label'          => $this->build_bucket_label( $warehouse_bucket, 'Warehouse source' ),
						'destination_label'     => $this->build_bucket_label( $bucket, 'Event bucket' ),
					),
					'event_return'       => array(
						'movement_type'         => AIMS_Inventory_Movement_Repository::MOVEMENT_EVENT_RETURN_OUT,
						'label'                 => 'Return to warehouse',
						'source_bucket'         => $bucket,
						'destination_bucket'    => $warehouse_bucket,
						'quantity_limit'        => $return_capacity,
						'can_initiate'          => $can_return,
						'source_label'          => $this->build_bucket_label( $bucket, 'Event bucket' ),
						'destination_label'     => $this->build_bucket_label( $warehouse_bucket, 'Warehouse destination' ),
					),
				),
			);
		}

		if ( count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return $rows;
	}

	private function transfer_between_buckets( array $data, string $source_movement_type, string $destination_movement_type, string $source_bucket_type, string $destination_bucket_type, int $actor_user_id = 0 ) {
		$reference_type = sanitize_key( $data['reference_type'] ?? '' );
		$reference_id   = sanitize_text_field( $data['reference_id'] ?? '' );
		$quantity       = abs( (float) ( $data['quantity_delta'] ?? $data['quantity'] ?? 0 ) );

		if ( '' === $reference_type || '' === $reference_id || 0.0 === $quantity ) {
			return new WP_Error( 'aims_invalid_inventory_transfer', 'Inventory transfer is missing required fields.' );
		}

		$source_data               = $data;
		$destination_data          = $data;
		$source_data['bucket']     = $this->resolve_explicit_bucket_side( $data, array( 'source_bucket', 'warehouse_bucket', 'bucket' ), $source_bucket_type );
		$destination_data['bucket'] = $this->resolve_explicit_bucket_side( $data, array( 'destination_bucket', 'event_bucket', 'bucket' ), $destination_bucket_type );

		if (
			! $this->can_manage_bucket_context( $source_data['bucket'], $actor_user_id )
			|| ! $this->can_manage_bucket_context( $destination_data['bucket'], $actor_user_id )
		) {
			$this->record_audit(
				'inventory_transfer_denied',
				$this->resolve_actor_user_id( $actor_user_id ),
				(int) ( $source_data['bucket']['owner_entity_id'] ?? 0 ),
				'bucket',
				(int) ( $source_data['bucket']['id'] ?? 0 ),
				array(
					'reference_type' => $reference_type,
					'reference_id'   => $reference_id,
					'quantity'       => $quantity,
					'source_bucket'   => $source_data['bucket'] ?? array(),
					'destination_bucket' => $destination_data['bucket'] ?? array(),
				),
				'Inventory transfer denied by bucket RBAC.'
			);

			return new WP_Error( 'aims_bucket_access_denied', 'The current user cannot transfer inventory for one or more buckets.' );
		}

		$source_result = $this->apply_movement_for_transfer_context(
			$source_data,
			$source_movement_type,
			-1 * $quantity,
			$actor_user_id
		);

		if ( is_wp_error( $source_result ) ) {
			return $source_result;
		}

		$destination_result = $this->apply_movement_for_transfer_context(
			$destination_data,
			$destination_movement_type,
			$quantity,
			$actor_user_id
		);

		if ( is_wp_error( $destination_result ) ) {
			return $destination_result;
		}

		return array(
			'reference_type'        => $reference_type,
			'reference_id'          => $reference_id,
			'quantity'              => $quantity,
			'source'                => $source_result,
			'destination'           => $destination_result,
			'source_movement_type'  => $source_movement_type,
			'destination_movement_type' => $destination_movement_type,
			'workflow'              => array(
				'type'                  => 'explicit_event_transfer',
				'source_bucket_type'    => $source_bucket_type,
				'destination_bucket_type' => $destination_bucket_type,
			),
		);
	}

	public function describe_operator_state( string $state ): string {
		switch ( sanitize_key( $state ) ) {
			case 'ready_to_transfer':
				return 'Ready to transfer';
			case 'at_show':
				return 'At show';
			case 'show_complete':
				return 'Show complete';
			case 'partially_returned':
				return 'Partially returned';
			case 'source_missing':
				return 'Warehouse source missing';
			case 'warehouse':
				return 'Warehouse bucket';
			default:
				return ucfirst( str_replace( '_', ' ', sanitize_key( $state ) ) );
		}
	}

	private function determine_operator_state( array $bucket, array $summary, array $warehouse_bucket = array() ): string {
		$bucket_type = (string) ( $bucket['bucket_type'] ?? '' );
		$available   = (float) ( $bucket['available_quantity'] ?? 0 );
		$transferred = (float) ( $summary['transfer_in_quantity'] ?? 0 );
		$returned    = (float) ( $summary['return_in_quantity'] ?? 0 );
		$source_missing = empty( $warehouse_bucket ) || ! empty( $warehouse_bucket['source_missing'] );

		if ( 'event' !== $bucket_type ) {
			return 'warehouse';
		}

		if ( $source_missing && $transferred <= 0 ) {
			return 'source_missing';
		}

		if ( $transferred > 0 && $available <= 0 ) {
			return 'show_complete';
		}

		if ( $transferred > 0 && $returned > 0 ) {
			return 'partially_returned';
		}

		if ( $transferred > 0 ) {
			return 'at_show';
		}

		return 'ready_to_transfer';
	}

	private function apply_movement_for_transfer_context( array $data, string $movement_type, float $quantity_delta, int $actor_user_id = 0 ) {
		$data['movement_type']  = $movement_type;
		$data['quantity_delta'] = $quantity_delta;

		return $this->apply_movement( $data, $actor_user_id );
	}

	private function resolve_explicit_bucket_side( array $data, array $keys, string $bucket_type ): array {
		foreach ( $keys as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}

			if ( is_array( $data[ $key ] ) ) {
				$bucket = $data[ $key ];
				if ( empty( $bucket['bucket_type'] ) ) {
					$bucket['bucket_type'] = $bucket_type;
				}

				return $bucket;
			}

			if ( is_object( $data[ $key ] ) ) {
				$bucket = get_object_vars( $data[ $key ] );
				if ( empty( $bucket['bucket_type'] ) ) {
					$bucket['bucket_type'] = $bucket_type;
				}

				return $bucket;
			}

			if ( is_string( $data[ $key ] ) ) {
				$decoded = json_decode( wp_unslash( $data[ $key ] ), true );
				if ( is_array( $decoded ) ) {
					if ( empty( $decoded['bucket_type'] ) ) {
						$decoded['bucket_type'] = $bucket_type;
					}

					return $decoded;
				}
			}
		}

		$resolved = $this->resolve_bucket_context( $data );
		$resolved['bucket_type'] = $bucket_type;
		if ( '' === (string) ( $resolved['bucket_key'] ?? '' ) ) {
			$resolved['bucket_key'] = implode(
				':',
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_key( (string) $value );
						},
						array(
							$bucket_type,
							$resolved['owner_entity_type'] ?? '',
							$resolved['owner_entity_id'] ?? '',
							$resolved['square_location_id'] ?? '',
							$resolved['vendor_id'] ?? '',
							$resolved['product_id'] ?? '',
							$resolved['bucket_code'] ?? '',
						)
					)
				)
			);
		}

		return $resolved;
	}

	private function resolve_transfer_partner_bucket( array $event_bucket, string $bucket_type ): array {
		$bucket_type   = sanitize_key( $bucket_type );
		$product_id    = (int) ( $event_bucket['product_id'] ?? 0 );
		$vendor_id     = (int) ( $event_bucket['vendor_id'] ?? 0 );
		$bucket_code   = sanitize_text_field( $event_bucket['bucket_code'] ?? '' );
		$bucket_name   = ! empty( $event_bucket['bucket_label'] ) ? (string) $event_bucket['bucket_label'] : '';

		$matched_bucket = $this->buckets->find_bucket_by_identity(
			'',
			$bucket_type,
			'',
			0,
			'',
			$vendor_id,
			$product_id,
			$bucket_code
		);

		if ( ! empty( $matched_bucket ) ) {
			return $this->buckets->get_bucket_snapshot_by_id( (int) ( $matched_bucket['id'] ?? 0 ) ) ?: array();
		}

		$warehouse_buckets = $this->buckets->get_bucket_snapshots_by_type( $bucket_type );

		foreach ( $warehouse_buckets as $candidate ) {
			$candidate_product_id = (int) ( $candidate['product_id'] ?? 0 );
			$candidate_vendor_id  = (int) ( $candidate['vendor_id'] ?? 0 );
			$candidate_bucket_code = sanitize_text_field( $candidate['bucket_code'] ?? '' );

			if ( $product_id > 0 && $candidate_product_id !== $product_id ) {
				continue;
			}

			if ( $vendor_id > 0 && $candidate_vendor_id !== $vendor_id ) {
				continue;
			}

			if ( '' !== $bucket_code && '' !== $candidate_bucket_code && $candidate_bucket_code !== $bucket_code ) {
				continue;
			}

			return $candidate;
		}

		return array(
			'bucket_label' => $bucket_name,
			'bucket_type'  => $bucket_type,
			'quantity'     => 0,
			'reserved_quantity' => 0,
			'available_quantity' => 0,
			'source_missing' => true,
		);
	}

	private function get_transfer_capacity( array $bucket ): float {
		$quantity = (float) ( $bucket['quantity'] ?? 0 );
		$reserved = (float) ( $bucket['reserved_quantity'] ?? 0 );

		return max( 0, $quantity - $reserved );
	}

	private function build_bucket_label( array $bucket, string $fallback ): string {
		if ( ! empty( $bucket['bucket_label'] ) ) {
			return (string) $bucket['bucket_label'];
		}

		if ( ! empty( $bucket['bucket_key'] ) ) {
			return (string) $bucket['bucket_key'];
		}

		return $fallback;
	}

	public function resolve_bucket_context( array $data ): array {
		$bucket = $this->extract_bucket_context( $data );

		$bucket_id          = (int) ( $data['bucket_id'] ?? $data['inventory_bucket_id'] ?? $bucket['id'] ?? 0 );
		$bucket_key         = sanitize_text_field( $data['bucket_key'] ?? $bucket['bucket_key'] ?? '' );
		$bucket_type        = sanitize_key( $data['bucket_type'] ?? $bucket['bucket_type'] ?? '' );
		$bucket_label       = sanitize_text_field( $data['bucket_label'] ?? $data['bucket_name'] ?? $bucket['bucket_label'] ?? $bucket['bucket_name'] ?? '' );
		$owner_entity_type  = sanitize_key( $data['owner_entity_type'] ?? $bucket['owner_entity_type'] ?? '' );
		$owner_entity_id    = (int) ( $data['owner_entity_id'] ?? $bucket['owner_entity_id'] ?? 0 );
		$square_location_id = sanitize_text_field( $data['square_location_id'] ?? $bucket['square_location_id'] ?? '' );
		$vendor_id          = (int) ( $data['vendor_id'] ?? $data['bucket_vendor_id'] ?? $bucket['vendor_id'] ?? 0 );
		$product_id         = (int) ( $data['product_id'] ?? $bucket['product_id'] ?? 0 );
		$bucket_code        = sanitize_text_field( $data['bucket_code'] ?? $bucket['bucket_code'] ?? '' );

		if ( '' === $bucket_type ) {
			$bucket_type = ! empty( $square_location_id ) ? 'event' : ( ! empty( $owner_entity_type ) ? $owner_entity_type : 'warehouse' );
		}

		if ( '' === $bucket_label ) {
			$bucket_label = $bucket_key;
		}

		return array(
			'id'                => $bucket_id,
			'bucket_key'        => $bucket_key,
			'bucket_type'       => $bucket_type,
			'bucket_label'      => $bucket_label,
			'owner_entity_type' => $owner_entity_type,
			'owner_entity_id'   => $owner_entity_id,
			'square_location_id' => $square_location_id,
			'vendor_id'         => $vendor_id,
			'product_id'        => $product_id,
			'bucket_code'       => $bucket_code,
			'raw_bucket'        => $bucket,
		);
	}

	private function extract_bucket_context( array $data ): array {
		foreach ( array( 'bucket', 'inventory_bucket', 'source_bucket' ) as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}

			if ( is_array( $data[ $key ] ) ) {
				return $data[ $key ];
			}

			if ( is_object( $data[ $key ] ) ) {
				return get_object_vars( $data[ $key ] );
			}
		}

		return array();
	}

	private function can_manage_bucket_context( array $bucket_context, int $actor_user_id ): bool {
		if ( null === $this->bucket_access ) {
			return true;
		}

		$actor_user_id = $this->resolve_actor_user_id( $actor_user_id );

		if ( $actor_user_id <= 0 ) {
			return false;
		}

		$bucket_id = (int) ( $bucket_context['id'] ?? 0 );

		if ( $bucket_id > 0 ) {
			return $this->bucket_access->user_can_manage_bucket( $actor_user_id, $bucket_id );
		}

		$bucket_key = (string) ( $bucket_context['bucket_key'] ?? '' );
		if ( '' === $bucket_key ) {
			return $this->bucket_access->can_manage_all_buckets( $actor_user_id );
		}

		$bucket = $this->buckets->find_bucket_by_key_and_type(
			$bucket_key,
			(string) ( $bucket_context['bucket_type'] ?? '' )
		);

		if ( empty( $bucket ) ) {
			return $this->bucket_access->can_manage_all_buckets( $actor_user_id );
		}

		return $this->bucket_access->user_can_manage_bucket( $actor_user_id, (int) ( $bucket['id'] ?? 0 ) );
	}

	private function resolve_actor_user_id( int $actor_user_id ): int {
		if ( $actor_user_id > 0 ) {
			return $actor_user_id;
		}

		return (int) get_current_user_id();
	}

	private function record_audit(
		string $event_type,
		int $actor_id,
		int $scope_id,
		string $entity_type,
		int $entity_id,
		array $details = array(),
		string $reason = ''
	): void {
		if ( null === $this->audit ) {
			$this->audit = new AIMS_Audit_Service();
		}

		$this->audit->record(
			$event_type,
			array(
				'actor_id'   => $actor_id,
				'scope_type' => 'bucket',
				'scope_id'   => $scope_id,
				'entity_type'=> $entity_type,
				'entity_id'  => $entity_id,
				'reason'     => $reason,
				'details'    => $details,
			)
		);
	}
}
