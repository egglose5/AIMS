<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Access_Service {
	private $access;
	private $vendors;
	private $audit;
	private $auth_context;

	public function __construct(
		AIMS_Vendor_User_Access_Repository $access,
		AIMS_Vendor_Repository $vendors,
		AIMS_Audit_Service $audit = null,
		AIMS_Auth_Context_Service $auth_context = null
	) {
		$this->access  = $access;
		$this->vendors = $vendors;
		$this->audit   = $audit;
		$this->auth_context = $auth_context ?: new AIMS_Auth_Context_Service();
	}

	public function grant_vendor_responsibility( int $vendor_id, int $user_id, int $actor_user_id, array $data = array() ): int {
		$user_id = $this->normalize_actor_user_id( $user_id );
		$actor_user_id = $this->normalize_actor_user_id( $actor_user_id );

		if ( ! $this->auth_context->has_actor_user_id( $actor_user_id ) || ! $this->can_manage_vendor_scope( $vendor_id, $actor_user_id ) ) {
			$this->record_access_audit(
				'access_grant_denied',
				$actor_user_id,
				$vendor_id,
				$user_id,
				array(
					'requested_role' => $data['access_role'] ?? AIMS_Vendor_User_Access_Repository::ROLE_MANAGER,
				),
				'access_change',
				'Vendor responsibility grant denied.'
			);

			return 0;
		}

		$access_id = $this->access->grant_access( $vendor_id, $user_id, $data );

		$this->record_access_audit(
			'access_granted',
			$actor_user_id,
			$vendor_id,
			$user_id,
			array_merge(
				$data,
				array(
					'access_id' => $access_id,
				)
			),
			'access_change',
			'Vendor responsibility granted.'
		);

		return $access_id;
	}

	public function revoke_vendor_responsibility( int $vendor_id, int $user_id, int $actor_user_id ): bool {
		$user_id = $this->normalize_actor_user_id( $user_id );
		$actor_user_id = $this->normalize_actor_user_id( $actor_user_id );

		if ( ! $this->auth_context->has_actor_user_id( $actor_user_id ) || ! $this->can_manage_vendor_scope( $vendor_id, $actor_user_id ) ) {
			$this->record_access_audit(
				'access_revoke_denied',
				$actor_user_id,
				$vendor_id,
				$user_id,
				array(),
				'access_change',
				'Vendor responsibility revoke denied.'
			);

			return false;
		}

		$deleted = $this->access->revoke_access( $vendor_id, $user_id );

		if ( $deleted ) {
			$this->record_access_audit(
				'access_revoked',
				$actor_user_id,
				$vendor_id,
				$user_id,
				array(),
				'access_change',
				'Vendor responsibility revoked.'
			);
		}

		return $deleted;
	}

	public function get_accessible_vendors_for_user( int $user_id ): array {
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return $this->vendors->all();
		}

		return $this->vendors->find_by_ids( $this->access->get_vendor_ids_for_user( $user_id ) );
	}

	public function user_has_vendor_access( int $vendor_id, int $user_id ): bool {
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_view_vendor( $vendor_id, $user_id );
	}

	public function user_can_manage_vendor( int $vendor_id, int $user_id ): bool {
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_manage_vendor( $vendor_id, $user_id );
	}

	public function require_vendor_view_access( int $vendor_id, int $user_id ): ?WP_Error {
		if ( $this->user_has_vendor_access( $vendor_id, $user_id ) ) {
			return null;
		}

		$this->record_access_audit(
			'vendor_view_denied',
			$this->normalize_actor_user_id( $user_id ),
			$vendor_id,
			$user_id,
			array(),
			'access_change',
			'Vendor view access denied.'
		);

		return new WP_Error( 'aims_vendor_access_denied', 'The current user cannot view this vendor.' );
	}

	public function require_vendor_manage_access( int $vendor_id, int $user_id ): ?WP_Error {
		if ( $this->user_can_manage_vendor( $vendor_id, $user_id ) ) {
			return null;
		}

		$this->record_access_audit(
			'vendor_manage_denied',
			$this->normalize_actor_user_id( $user_id ),
			$vendor_id,
			$user_id,
			array(),
			'access_change',
			'Vendor manage access denied.'
		);

		return new WP_Error( 'aims_vendor_manage_denied', 'The current user cannot manage this vendor.' );
	}

	public function can_manage_all_vendors( int $user_id ): bool {
		return $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE )
			|| $this->auth_context->can_user( $user_id, AIMS_Capabilities::CAP_MANAGE_VENDORS );
	}

	private function can_manage_vendor_scope( int $vendor_id, int $user_id ): bool {
		if ( $this->can_manage_all_vendors( $user_id ) ) {
			return true;
		}

		return $this->access->user_can_manage_vendor( $vendor_id, $user_id );
	}

	private function normalize_actor_user_id( int $user_id ): int {
		return $this->auth_context->normalize_actor_user_id( $user_id );
	}

	private function record_access_audit(
		string $event_type,
		int $actor_id,
		int $vendor_id,
		int $user_id,
		array $details = array(),
		string $reason = '',
		string $scope_type = 'vendor'
	): void {
		if ( null === $this->audit ) {
			$this->audit = new AIMS_Audit_Service();
		}

		$this->audit->record_access_change(
			array(
				'actor_id'   => $actor_id,
				'scope_type' => $scope_type,
				'scope_id'   => $vendor_id,
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
