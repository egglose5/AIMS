<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Service {
	private $buckets;
	private $movements;
	private $bucket_access;

	public function __construct(
		AIMS_Inventory_Bucket_Repository $buckets,
		AIMS_Inventory_Movement_Repository $movements,
		AIMS_Bucket_Access_Service $bucket_access = null
	) {
		$this->buckets       = $buckets;
		$this->movements     = $movements;
		$this->bucket_access = $bucket_access;
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
			'warehouse_transfer_out',
			'event_transfer_in',
			'warehouse',
			'event',
			$actor_user_id
		);
	}

	public function record_event_return( array $data, int $actor_user_id = 0 ) {
		return $this->transfer_between_buckets(
			$data,
			'event_return_out',
			'warehouse_return_in',
			'event',
			'warehouse',
			$actor_user_id
		);
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
			return true;
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
}
