<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Access_Service {
	private $access;
	private $buckets;
	private $audit;
	private $auth_context;

	public function __construct(
		AIMS_Bucket_Access_Repository $access,
		AIMS_Inventory_Bucket_Repository $buckets,
		AIMS_Audit_Service $audit = null,
		AIMS_Auth_Context_Service $auth_context = null
	) {
		$this->access  = $access;
		$this->buckets = $buckets;
		$this->audit   = $audit;
		$this->auth_context = $auth_context ?: new AIMS_Auth_Context_Service();
	}

	public function grant_bucket_responsibility( int $bucket_id, int $user_id, array $data = array(), int $actor_user_id = 0 ): int {
		$user_id = $this->normalize_actor_user_id( $user_id );
		$actor_user_id = $this->normalize_actor_user_id( $actor_user_id );

		if ( $bucket_id <= 0 || $user_id <= 0 ) {
			return 0;
		}

		if ( ! $this->auth_context->has_actor_user_id( $actor_user_id ) || ! $this->user_can_manage_bucket( $actor_user_id, $bucket_id ) ) {
			$this->record_access_audit(
				'access_grant_denied',
				$actor_user_id,
				$bucket_id,
				$user_id,
				array(
					'requested_role' => $data['access_role'] ?? self::ROLE_SUPERVISOR,
				),
				'access_change',
				'Bucket responsibility grant denied.'
			);

			return 0;
		}

		$access_id = $this->access->grant_access( $bucket_id, $user_id, $data );

		$this->record_access_audit(
			'access_granted',
			$actor_user_id,
			$bucket_id,
			$user_id,
			array_merge(
				$data,
				array(
					'access_id' => $access_id,
				)
			),
			'access_change',
			'Bucket responsibility granted.'
		);

		return $access_id;
	}

	public function revoke_bucket_responsibility( int $bucket_id, int $user_id, int $actor_user_id = 0 ): bool {
		$user_id = $this->normalize_actor_user_id( $user_id );
		$actor_user_id = $this->normalize_actor_user_id( $actor_user_id );

		if ( $bucket_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		if ( ! $this->auth_context->has_actor_user_id( $actor_user_id ) || ! $this->user_can_manage_bucket( $actor_user_id, $bucket_id ) ) {
			$this->record_access_audit(
				'access_revoke_denied',
				$actor_user_id,
				$bucket_id,
				$user_id,
				array(),
				'access_change',
				'Bucket responsibility revoke denied.'
			);

			return false;
		}

		$deleted = $this->access->revoke_access( $bucket_id, $user_id );

		if ( $deleted ) {
			$this->record_access_audit(
				'access_revoked',
				$actor_user_id,
				$bucket_id,
				$user_id,
				array(),
				'access_change',
				'Bucket responsibility revoked.'
			);
		}

		return $deleted;
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
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( $this->user_has_global_bucket_access( $user_id ) ) {
			return $this->buckets->get_all();
		}

		return $this->buckets->get_by_ids( $this->access->get_bucket_ids_for_user( $user_id ) );
	}

	public function get_managed_buckets_for_user( int $user_id ): array {
		$user_id = $this->normalize_actor_user_id( $user_id );

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
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( $this->user_has_global_bucket_view( $user_id ) || $this->user_has_global_bucket_access( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_view_bucket( $bucket_id, $user_id );
	}

	public function user_can_manage_bucket( int $user_id, int $bucket_id ): bool {
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( $this->user_has_global_bucket_access( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_adjust_bucket( $bucket_id, $user_id );
	}

	public function user_can_transfer_bucket( int $user_id, int $bucket_id ): bool {
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( $this->user_has_global_bucket_access( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_transfer_bucket( $bucket_id, $user_id );
	}

	public function can_manage_all_buckets( int $user_id ): bool {
		return $this->user_has_global_bucket_access( $user_id );
	}

	public function require_bucket_view_access( int $user_id, int $bucket_id ): ?WP_Error {
		if ( $this->user_has_bucket_access( $user_id, $bucket_id ) ) {
			return null;
		}

		$this->record_access_audit(
			'bucket_view_denied',
			$this->normalize_actor_user_id( $user_id ),
			$bucket_id,
			$user_id,
			array(),
			'access_change',
			'Bucket view access denied.'
		);

		return new WP_Error( 'aims_bucket_access_denied', 'The current user cannot view this bucket.' );
	}

	public function require_bucket_manage_access( int $user_id, int $bucket_id ): ?WP_Error {
		if ( $this->user_can_manage_bucket( $user_id, $bucket_id ) ) {
			return null;
		}

		$this->record_access_audit(
			'bucket_manage_denied',
			$this->normalize_actor_user_id( $user_id ),
			$bucket_id,
			$user_id,
			array(),
			'access_change',
			'Bucket manage access denied.'
		);

		return new WP_Error( 'aims_bucket_manage_denied', 'The current user cannot manage this bucket.' );
	}

	public function require_bucket_transfer_access( int $user_id, int $bucket_id ): ?WP_Error {
		if ( $this->user_can_transfer_bucket( $user_id, $bucket_id ) ) {
			return null;
		}

		$this->record_access_audit(
			'bucket_transfer_denied',
			$this->normalize_actor_user_id( $user_id ),
			$bucket_id,
			$user_id,
			array(),
			'access_change',
			'Bucket transfer access denied.'
		);

		return new WP_Error( 'aims_bucket_transfer_denied', 'The current user cannot transfer inventory for this bucket.' );
	}

	private function user_has_global_bucket_view( int $user_id ): bool {
		return $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE_BUCKETS )
			|| $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_VIEW_BUCKETS );
	}

	private function user_has_global_bucket_access( int $user_id ): bool {
		return $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE_BUCKETS )
			|| $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE_BUCKET_ACCESS );
	}

	private function filter_rows_by_flag( array $rows, string $flag ): array {
		return array_filter(
			$rows,
			static function ( $row ) use ( $flag ) {
				return ! empty( $row[ $flag ] );
			}
		);
	}

	private function normalize_actor_user_id( int $user_id ): int {
		return $this->auth_context->normalize_actor_user_id( $user_id );
	}

	private function record_access_audit(
		string $event_type,
		int $actor_id,
		int $bucket_id,
		int $user_id,
		array $details = array(),
		string $reason = '',
		string $scope_type = 'bucket'
	): void {
		if ( null === $this->audit ) {
			$this->audit = new AIMS_Audit_Service();
		}

		$this->audit->record_access_change(
			array(
				'actor_id'   => $actor_id,
				'scope_type' => $scope_type,
				'scope_id'   => $bucket_id,
				'entity_type'=> 'user',
				'entity_id'  => $user_id,
				'reason'     => $reason,
				'details'    => array_merge(
					$details,
					array(
						'event_type' => $event_type,
					)
				),
			)
		);
	}
}
