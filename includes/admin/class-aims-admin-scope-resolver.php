<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Scope_Resolver {
	private $bucket_access_repository;
	private $bucket_repository;
	private $vendor_access_service;
	private $vendor_repository;
	private $auth_context;

	public function __construct(
		AIMS_Bucket_Access_Repository $bucket_access_repository = null,
		AIMS_Inventory_Bucket_Repository $bucket_repository = null,
		AIMS_Vendor_Access_Service $vendor_access_service = null,
		AIMS_Vendor_Repository $vendor_repository = null,
		AIMS_Auth_Context_Service $auth_context = null
	) {
		$this->bucket_access_repository = $bucket_access_repository ?: new AIMS_Bucket_Access_Repository();
		$this->bucket_repository        = $bucket_repository ?: new AIMS_Inventory_Bucket_Repository();
		$this->vendor_repository        = $vendor_repository ?: new AIMS_Vendor_Repository();
		$this->auth_context             = $auth_context ?: new AIMS_Auth_Context_Service();
		$this->vendor_access_service    = $vendor_access_service ?: new AIMS_Vendor_Access_Service(
			new AIMS_Vendor_User_Access_Repository(),
			$this->vendor_repository,
			new AIMS_Audit_Service(),
			$this->auth_context
		);
	}

	public function can_manage_all( int $user_id ): bool {
		return $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE_BUCKETS );
	}

	public function get_access_mode_label( int $user_id ): string {
		return $this->can_manage_all( $user_id ) ? 'Full access' : 'Scoped access';
	}

	public function get_accessible_bucket_ids( int $user_id ): array {
		$user_id = $this->auth_context->normalize_actor_user_id( $user_id );

		if ( $this->can_manage_all( $user_id ) ) {
			return array();
		}

		return $this->bucket_access_repository->get_bucket_ids_for_user( $user_id );
	}

	public function get_accessible_buckets( int $user_id ): array {
		$user_id = $this->auth_context->normalize_actor_user_id( $user_id );

		if ( $this->can_manage_all( $user_id ) ) {
			return $this->bucket_repository->get_all();
		}

		$bucket_ids = $this->get_accessible_bucket_ids( $user_id );
		if ( empty( $bucket_ids ) ) {
			return array();
		}

		return $this->bucket_repository->get_by_ids( $bucket_ids );
	}

	public function get_accessible_vendors( int $user_id ): array {
		$user_id = $this->auth_context->normalize_actor_user_id( $user_id );

		if ( $this->can_manage_all( $user_id ) ) {
			return $this->vendor_repository->all();
		}

		if ( $user_id <= 0 ) {
			return array();
		}

		return $this->vendor_access_service->get_accessible_vendors_for_user( $user_id );
	}

	public function get_accessible_vendor_ids( int $user_id ): array {
		$vendor_ids = array();

		foreach ( $this->get_accessible_vendors( $user_id ) as $vendor ) {
			if ( ! empty( $vendor['id'] ) ) {
				$vendor_ids[] = (int) $vendor['id'];
			}
		}

		return array_values( array_unique( array_filter( $vendor_ids ) ) );
	}

	public function get_accessible_scope_ids( int $user_id ): array {
		$user_id = $this->auth_context->normalize_actor_user_id( $user_id );
		$vendor_ids  = array();
		$event_ids   = array();
		$bucket_codes = array();

		if ( $this->can_manage_all( $user_id ) ) {
			return array(
				'vendor_ids'   => array(),
				'event_ids'    => array(),
				'bucket_codes' => array(),
				'all'          => true,
			);
		}

		foreach ( $this->get_accessible_buckets( $user_id ) as $bucket ) {
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
