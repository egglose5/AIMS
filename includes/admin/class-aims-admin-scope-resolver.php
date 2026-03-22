<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Scope_Resolver {
	private $bucket_access_repository;
	private $bucket_repository;

	public function __construct(
		AIMS_Bucket_Access_Repository $bucket_access_repository = null,
		AIMS_Inventory_Bucket_Repository $bucket_repository = null
	) {
		$this->bucket_access_repository = $bucket_access_repository ?: new AIMS_Bucket_Access_Repository();
		$this->bucket_repository        = $bucket_repository ?: new AIMS_Inventory_Bucket_Repository();
	}

	public function can_manage_all(): bool {
		return current_user_can( AIMS_Capabilities::CAP_MANAGE );
	}

	public function get_accessible_bucket_ids(): array {
		if ( $this->can_manage_all() ) {
			return array();
		}

		return $this->bucket_access_repository->get_bucket_ids_for_user( get_current_user_id() );
	}

	public function get_accessible_buckets(): array {
		if ( $this->can_manage_all() ) {
			return $this->bucket_repository->get_all();
		}

		$bucket_ids = $this->get_accessible_bucket_ids();
		if ( empty( $bucket_ids ) ) {
			return array();
		}

		return $this->bucket_repository->get_by_ids( $bucket_ids );
	}

	public function get_accessible_scope_ids(): array {
		$bucket_ids  = $this->get_accessible_bucket_ids();
		$vendor_ids  = array();
		$event_ids   = array();
		$bucket_codes = array();

		if ( $this->can_manage_all() ) {
			return array(
				'vendor_ids'   => array(),
				'event_ids'    => array(),
				'bucket_codes' => array(),
				'all'          => true,
			);
		}

		foreach ( $this->get_accessible_buckets() as $bucket ) {
			if ( ! empty( $bucket['bucket_code'] ) ) {
				$bucket_codes[] = (string) $bucket['bucket_code'];
			}

			$bucket_type = ! empty( $bucket['bucket_type'] ) ? sanitize_key( (string) $bucket['bucket_type'] ) : '';
			$owner_type  = ! empty( $bucket['owner_entity_type'] ) ? sanitize_key( (string) $bucket['owner_entity_type'] ) : '';
			$owner_id    = ! empty( $bucket['owner_entity_id'] ) ? (int) $bucket['owner_entity_id'] : 0;
			$vendor_id   = ! empty( $bucket['vendor_id'] ) ? (int) $bucket['vendor_id'] : 0;

			if ( 'event' === $bucket_type && $owner_id > 0 ) {
				$event_ids[] = $owner_id;
			}

			if ( 'vendor' === $bucket_type && $vendor_id > 0 ) {
				$vendor_ids[] = $vendor_id;
			}

			if ( 'vendor' === $owner_type && $owner_id > 0 ) {
				$vendor_ids[] = $owner_id;
			}
		}

		return array(
			'vendor_ids'   => array_values( array_unique( array_map( 'intval', $vendor_ids ) ) ),
			'event_ids'    => array_values( array_unique( array_map( 'intval', $event_ids ) ) ),
			'bucket_codes' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $bucket_codes ) ) ) ),
			'all'          => false,
		);
	}
}
