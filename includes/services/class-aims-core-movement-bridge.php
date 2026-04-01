<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Core_Movement_Bridge {
	public static function make_service(
		$movements,
		$positions = null,
		AIMS_Responsibility_Authorization_Service $auth_service = null,
		AIMS_Person_Identity_Service $person_identity = null,
		AIMS_Movement_Lifecycle_Service $movement_lifecycle = null
	): \AmesCore\Inventory\MovementLedgerService {
		$movement_repo = new class( $movements ) implements \AmesCore\Contracts\MovementRepositoryInterface {
			private $movements;

			public function __construct( $movements ) {
				$this->movements = $movements;
			}

			public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool {
				return method_exists( $this->movements, 'has_reference_application' )
					? (bool) $this->movements->has_reference_application( $referenceType, $referenceId, $productId, $bucketId, $movementType )
					: false;
			}

			public function create( array $data ): int {
				return (int) $this->movements->create( $data );
			}

			public function getBalanceForBucketProduct( int $bucketId, int $vendorId, int $productId ): float {
				return method_exists( $this->movements, 'get_balance_for_bucket_product' )
					? (float) $this->movements->get_balance_for_bucket_product( $bucketId, $vendorId, $productId )
					: 0.0;
			}
		};

		$position_repo = null;
		if ( is_object( $positions ) ) {
			$position_repo = new class( $positions, $movements ) implements \AmesCore\Contracts\PositionRepositoryInterface {
				private $positions;
				private $movements;

				public function __construct( $positions, $movements ) {
					$this->positions  = $positions;
					$this->movements = $movements;
				}

				public function supportsSynchronization(): bool {
					return method_exists( $this->positions, 'synchronize_from_movements' ) && method_exists( $this->movements, 'get_balance_for_bucket_product' );
				}

				public function synchronizeFromMovements( int $bucketId, int $vendorId, int $productId ): void {
					if ( $this->supportsSynchronization() ) {
						$this->positions->synchronize_from_movements( $this->movements, $bucketId, $vendorId, $productId );
					}
				}

				public function upsertPosition( array $data ): void {
					if ( method_exists( $this->positions, 'upsert_position' ) ) {
						$this->positions->upsert_position( $data );
					}
				}
			};
		}

		$authorization = null;
		if ( is_object( $auth_service ) ) {
			$authorization = new class( $auth_service ) implements \AmesCore\Contracts\InventoryAuthorizationInterface {
				private AIMS_Responsibility_Authorization_Service $service;

				public function __construct( AIMS_Responsibility_Authorization_Service $service ) {
					$this->service = $service;
				}

				public function canManageVendorInventory( int $actorUserId, int $vendorId ): bool {
					return $this->service->can_manage_vendor_inventory_for_vendor( $actorUserId, $vendorId );
				}
			};
		}

		$person = null;
		if ( is_object( $person_identity ) ) {
			$person = new class( $person_identity ) implements \AmesCore\Contracts\PersonIdentityInterface {
				private AIMS_Person_Identity_Service $service;

				public function __construct( AIMS_Person_Identity_Service $service ) {
					$this->service = $service;
				}

				public function isAimsPerson( int $actorUserId ): bool {
					return $this->service->is_aims_person( $actorUserId );
				}
			};
		}

		$lifecycle = null;
		if ( is_object( $movement_lifecycle ) ) {
			$lifecycle = new class( $movement_lifecycle ) implements \AmesCore\Contracts\MovementLifecycleInterface {
				private AIMS_Movement_Lifecycle_Service $service;

				public function __construct( AIMS_Movement_Lifecycle_Service $service ) {
					$this->service = $service;
				}

				public function ensureHotBatch( array $data ): array {
					return $this->service->ensure_hot_batch( $data );
				}

				public function captureHotLine( int $batchId, int $movementId, array $data ): bool {
					return $this->service->capture_hot_line( $batchId, $movementId, $data );
				}
			};
		}

		$policy = new class() implements \AmesCore\Contracts\MovementPolicyInterface {
			public function isAllowedMovement( string $movementType ): bool {
				return AIMS_Inventory_Movement_Events::is_allowed( $movementType );
			}

			public function isAllowedReferenceForMovement( string $movementType, string $referenceType ): bool {
				return AIMS_Inventory_Movement_Events::is_allowed_reference_for_movement( $movementType, $referenceType );
			}
		};

		$clock = new class() implements \AmesCore\Contracts\ClockInterface {
			public function now(): string {
				return current_time( 'mysql' );
			}
		};

		$uuid = new class() implements \AmesCore\Contracts\UuidGeneratorInterface {
			public function generate(): string {
				return wp_generate_uuid4();
			}
		};

		return new \AmesCore\Inventory\MovementLedgerService(
			$movement_repo,
			$position_repo,
			$authorization,
			$person,
			$lifecycle,
			$policy,
			$clock,
			$uuid
		);
	}
}
