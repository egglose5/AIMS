<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Service {
	private $vendors;
	private $access;
	private $audit;
	private $auth_context;

	public function __construct(
		AIMS_Vendor_Repository $vendors,
		AIMS_Vendor_Access_Service $access = null,
		AIMS_Audit_Service $audit = null,
		AIMS_Auth_Context_Service $auth_context = null
	) {
		$this->vendors = $vendors;
		$this->access   = $access;
		$this->audit    = $audit;
		$this->auth_context = $auth_context ?: new AIMS_Auth_Context_Service();
	}

	public function list_vendors( int $user_id = 0 ): array {
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( null !== $this->access && $user_id <= 0 ) {
			return array();
		}

		if ( null !== $this->access && $user_id > 0 && ! $this->access->can_manage_all_vendors( $user_id ) ) {
			return $this->access->get_accessible_vendors_for_user( $user_id );
		}

		return $this->vendors->all();
	}

	public function create_vendor( array $data, int $user_id = 0 ): int {
		$user_id = $this->normalize_actor_user_id( $user_id );

		if ( null !== $this->access && ! $this->access->can_manage_all_vendors( $user_id ) ) {
			$this->record_audit(
				'vendor_create_denied',
				$user_id,
				0,
				'vendor',
				0,
				array(
					'input' => $data,
				),
				'Vendor creation denied.'
			);

			return 0;
		}

		$vendor_id = $this->vendors->save( $data );

		$this->record_audit(
			'vendor_created',
			$user_id,
			$vendor_id,
			'vendor',
			$vendor_id,
			array(
				'input' => $data,
			),
			'Vendor created.'
		);

		return $vendor_id;
	}

	public function get_vendor( int $vendor_id, int $user_id = 0 ): ?array {
		$user_id = $this->normalize_actor_user_id( $user_id );
		$vendor = $this->vendors->find( $vendor_id );

		if ( empty( $vendor ) ) {
			return null;
		}

		if ( null !== $this->access && $user_id <= 0 ) {
			$this->record_audit(
				'vendor_view_denied',
				$user_id,
				$vendor_id,
				'vendor',
				$vendor_id,
				array(),
				'Vendor view denied.'
			);

			return null;
		}

		if ( null !== $this->access && $user_id > 0 && ! $this->access->user_has_vendor_access( $vendor_id, $user_id ) ) {
			$this->record_audit(
				'vendor_view_denied',
				$user_id,
				$vendor_id,
				'vendor',
				$vendor_id,
				array(),
				'Vendor view denied.'
			);

			return null;
		}

		return $vendor;
	}

	public function get_sync_mapping_by_square_location( string $square_location_id ): ?array {
		$vendor = $this->vendors->find_by_square_location_id( $square_location_id );

		if ( empty( $vendor ) ) {
			return null;
		}

		return array(
			'vendor_id'           => (int) $vendor['id'],
			'vendor_name'         => (string) $vendor['vendor_name'],
			'square_location_id'  => (string) $vendor['square_location_id'],
			'default_bucket_code' => (string) $vendor['default_bucket_code'],
		);
	}

	private function normalize_actor_user_id( int $user_id ): int {
		return $this->auth_context->normalize_actor_user_id( $user_id );
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
				'scope_type' => 'vendor',
				'scope_id'   => $scope_id,
				'entity_type'=> $entity_type,
				'entity_id'  => $entity_id,
				'reason'     => $reason,
				'details'    => $details,
			)
		);
	}
}
