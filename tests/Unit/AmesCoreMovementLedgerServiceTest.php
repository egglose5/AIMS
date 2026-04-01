<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Contracts\ClockInterface;
use AmesCore\Contracts\InventoryAuthorizationInterface;
use AmesCore\Contracts\MovementLifecycleInterface;
use AmesCore\Contracts\MovementPolicyInterface;
use AmesCore\Contracts\MovementRepositoryInterface;
use AmesCore\Contracts\PersonIdentityInterface;
use AmesCore\Contracts\PositionRepositoryInterface;
use AmesCore\Contracts\UuidGeneratorInterface;
use AmesCore\Inventory\MovementException;
use AmesCore\Inventory\MovementLedgerService;

final class AmesCoreMovementLedgerServiceTest extends \AIMS\Tests\TestCase {
	public function testPortableMovementLedgerRecordsMovementWithoutWordPressDependencies(): void {
		$movements = new class() implements MovementRepositoryInterface {
			public array $created = array();

			public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool {
				return false;
			}

			public function create( array $data ): int {
				$this->created[] = $data;
				return 501;
			}

			public function getBalanceForBucketProduct( int $bucketId, int $vendorId, int $productId ): float {
				return 9.25;
			}
		};

		$positions = new class() implements PositionRepositoryInterface {
			public array $sync = array();

			public function supportsSynchronization(): bool {
				return true;
			}

			public function synchronizeFromMovements( int $bucketId, int $vendorId, int $productId ): void {
				$this->sync[] = compact( 'bucketId', 'vendorId', 'productId' );
			}

			public function upsertPosition( array $data ): void {}
		};

		$auth = new class() implements InventoryAuthorizationInterface {
			public function canManageVendorInventory( int $actorUserId, int $vendorId ): bool {
				return true;
			}
		};

		$person = new class() implements PersonIdentityInterface {
			public function isAimsPerson( int $actorUserId ): bool {
				return true;
			}
		};

		$lifecycle = new class() implements MovementLifecycleInterface {
			public array $captured = array();

			public function ensureHotBatch( array $data ): array {
				return array( 'id' => 222 );
			}

			public function captureHotLine( int $batchId, int $movementId, array $data ): bool {
				$this->captured[] = compact( 'batchId', 'movementId', 'data' );
				return true;
			}
		};

		$policy = new class() implements MovementPolicyInterface {
			public function isAllowedMovement( string $movementType ): bool {
				return 'stock_in' === $movementType;
			}

			public function isAllowedReferenceForMovement( string $movementType, string $referenceType ): bool {
				return 'inbound_receipt' === $referenceType;
			}
		};

		$clock = new class() implements ClockInterface {
			public function now(): string {
				return '2026-04-01 12:00:00';
			}
		};

		$uuid = new class() implements UuidGeneratorInterface {
			public function generate(): string {
				return 'portable-uuid-001';
			}
		};

		$service = new MovementLedgerService( $movements, $positions, $auth, $person, $lifecycle, $policy, $clock, $uuid );

		$result = $service->recordMovement(
			array(
				'applied_by'     => 7,
				'bucket_id'      => 11,
				'vendor_id'      => 3,
				'product_id'     => 99,
				'reference_type' => 'inbound_receipt',
				'reference_id'   => 'R-500',
				'movement_type'  => 'stock_in',
				'quantity_delta' => 9.25,
			)
		);

		$this->assertSame( 501, $result['movement_id'] );
		$this->assertSame( 222, $result['movement_batch_id'] );
		$this->assertSame( 9.25, $result['current_quantity'] );
		$this->assertSame( 'portable-uuid-001', $movements->created[0]['movement_uuid'] );
		$this->assertCount( 1, $positions->sync );
		$this->assertCount( 1, $lifecycle->captured );
	}

	public function testPortableMovementLedgerRejectsDuplicateMovementReferences(): void {
		$movements = new class() implements MovementRepositoryInterface {
			public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool {
				return true;
			}

			public function create( array $data ): int {
				return 0;
			}

			public function getBalanceForBucketProduct( int $bucketId, int $vendorId, int $productId ): float {
				return 0.0;
			}
		};

		$policy = new class() implements MovementPolicyInterface {
			public function isAllowedMovement( string $movementType ): bool {
				return true;
			}

			public function isAllowedReferenceForMovement( string $movementType, string $referenceType ): bool {
				return true;
			}
		};

		$clock = new class() implements ClockInterface {
			public function now(): string {
				return '2026-04-01 12:00:00';
			}
		};

		$uuid = new class() implements UuidGeneratorInterface {
			public function generate(): string {
				return 'portable-uuid-002';
			}
		};

		$service = new MovementLedgerService(
			$movements,
			null,
			null,
			null,
			null,
			$policy,
			$clock,
			$uuid
		);

		$this->expectException( MovementException::class );
		$this->expectExceptionMessage( 'This bucket movement has already been applied.' );

		$service->recordMovement(
			array(
				'bucket_id'      => 11,
				'vendor_id'      => 3,
				'product_id'     => 99,
				'reference_type' => 'inbound_receipt',
				'reference_id'   => 'R-500',
				'movement_type'  => 'stock_in',
				'quantity_delta' => 9.25,
			)
		);
	}
}
