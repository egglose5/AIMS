<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Access_Service {
	private $access;
	private $buckets;

	public function __construct(
		AIMS_Bucket_Access_Repository $access,
		AIMS_Inventory_Bucket_Repository $buckets
	) {
		$this->access  = $access;
		$this->buckets = $buckets;
	}

	public function grant_bucket_responsibility( int $bucket_id, int $user_id, array $data = array() ): int {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $bucket_id <= 0 || $user_id <= 0 ) {
			return 0;
		}

		return $this->access->grant_access( $bucket_id, $user_id, $data );
	}

	public function revoke_bucket_responsibility( int $bucket_id, int $user_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $bucket_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		return $this->access->revoke_access( $bucket_id, $user_id );
	}

	public function get_bucket_access_model( int $bucket_id ): array {
		$rows = $this->access->get_for_bucket( $bucket_id );

		return array(
			'bucket_id'         => $bucket_id,
			'access_count'      => count( $rows ),
			'can_view_count'    => count( $this->filter_rows_by_flag( $rows, 'can_view' ) ),
			'can_adjust_count'  => count( $this->filter_rows_by_flag( $rows, 'can_adjust_inventory' ) ),
			'can_transfer_count'=> count( $this->filter_rows_by_flag( $rows, 'can_transfer' ) ),
			'rows'              => $rows,
		);
	}

	public function get_accessible_buckets_for_user( int $user_id ): array {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( $this->user_has_global_bucket_access( $user_id ) ) {
			return $this->buckets->get_all();
		}

		return $this->buckets->get_by_ids( $this->access->get_bucket_ids_for_user( $user_id ) );
	}

	public function get_managed_buckets_for_user( int $user_id ): array {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( $this->user_has_global_bucket_access( $user_id ) ) {
			return $this->buckets->get_all();
		}

		$rows = $this->access->get_for_user( $user_id );
		$bucket_ids = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['can_adjust_inventory'] ) || in_array(
				(string) ( $row['access_role'] ?? '' ),
				array(
					AIMS_Bucket_Access_Repository::ROLE_SUPERVISOR,
					AIMS_Bucket_Access_Repository::ROLE_MANAGER,
				),
				true
			) ) {
				$bucket_ids[] = (int) ( $row['bucket_id'] ?? 0 );
			}
		}

		return $this->buckets->get_by_ids( $bucket_ids );
	}

	public function user_has_bucket_access( int $user_id, int $bucket_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $this->user_has_global_bucket_view( $user_id ) || $this->user_has_global_bucket_access( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_view_bucket( $bucket_id, $user_id );
	}

	public function user_can_manage_bucket( int $user_id, int $bucket_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $this->user_has_global_bucket_access( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_adjust_bucket( $bucket_id, $user_id );
	}

	public function user_can_transfer_bucket( int $user_id, int $bucket_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		if ( $this->user_has_global_bucket_access( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_transfer_bucket( $bucket_id, $user_id );
	}

	public function can_manage_all_buckets( int $user_id ): bool {
		$user_id = $this->resolve_actor_user_id( $user_id );

		return $this->user_has_global_bucket_access( $user_id );
	}

	public function require_bucket_view_access( int $user_id, int $bucket_id ): ?WP_Error {
		if ( $this->user_has_bucket_access( $user_id, $bucket_id ) ) {
			return null;
		}

		return new WP_Error( 'aims_bucket_access_denied', 'The current user cannot view this bucket.' );
	}

	public function require_bucket_manage_access( int $user_id, int $bucket_id ): ?WP_Error {
		if ( $this->user_can_manage_bucket( $user_id, $bucket_id ) ) {
			return null;
		}

		return new WP_Error( 'aims_bucket_manage_denied', 'The current user cannot manage this bucket.' );
	}

	public function require_bucket_transfer_access( int $user_id, int $bucket_id ): ?WP_Error {
		if ( $this->user_can_transfer_bucket( $user_id, $bucket_id ) ) {
			return null;
		}

		return new WP_Error( 'aims_bucket_transfer_denied', 'The current user cannot transfer inventory for this bucket.' );
	}

	private function user_has_global_bucket_view( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return user_can( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_BUCKETS )
			|| user_can( $user_id, AIMS_Capabilities::CAP_VIEW_BUCKETS );
	}

	private function user_has_global_bucket_access( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return user_can( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_BUCKETS )
			|| user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_BUCKET_ACCESS );
	}

	private function filter_rows_by_flag( array $rows, string $flag ): array {
		return array_filter(
			$rows,
			static function ( $row ) use ( $flag ) {
				return ! empty( $row[ $flag ] );
			}
		);
	}

	private function resolve_actor_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		return (int) get_current_user_id();
	}
}
