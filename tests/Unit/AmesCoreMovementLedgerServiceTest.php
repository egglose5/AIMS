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
				'unit_cost'      => 4.0,
			)
		);

		$this->assertSame( 501, $result['movement_id'] );
		$this->assertSame( 222, $result['movement_batch_id'] );
		$this->assertSame( 9.25, $result['current_quantity'] );
		$this->assertSame( 'portable-uuid-001', $movements->created[0]['movement_uuid'] );
		$this->assertSame( 4.0, $movements->created[0]['metadata_json']['unit_cost'] );
		$this->assertSame( 400, $movements->created[0]['metadata_json']['unit_cost_cents'] );
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
				'unit_cost'      => 4.0,
			)
		);
	}

	public function testPortableMovementLedgerRejectsInboundMovementWithoutCostSnapshot(): void {
		$movements = new class() implements MovementRepositoryInterface {
			public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool {
				return false;
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
				return 'portable-uuid-003';
			}
		};

		$service = new MovementLedgerService( $movements, null, null, null, null, $policy, $clock, $uuid );

		$this->expectException( MovementException::class );
		$this->expectExceptionMessage( 'Inbound inventory must include cost values.' );

		$service->recordMovement(
			array(
				'bucket_id'      => 11,
				'vendor_id'      => 3,
				'product_id'     => 99,
				'reference_type' => 'inbound_receipt',
				'reference_id'   => 'R-999',
				'movement_type'  => 'origin_inbound',
				'quantity_delta' => 1,
			)
		);
	}

	public function testPortableMovementLedgerStripsMetadataFromInternalMovementsAndCapturesSkuRef(): void {
		$movements = new class() implements MovementRepositoryInterface {
			public array $created = array();

			public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool {
				return false;
			}

			public function create( array $data ): int {
				$this->created[] = $data;
				return 808;
			}

			public function getBalanceForBucketProduct( int $bucketId, int $vendorId, int $productId ): float {
				return 4.0;
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
				return 'portable-uuid-004';
			}
		};

		$service = new MovementLedgerService( $movements, null, null, null, null, $policy, $clock, $uuid );
		$service->recordMovement(
			array(
				'bucket_id'      => 11,
				'vendor_id'      => 3,
				'product_id'     => 99,
				'sku'            => 'SKU-99',
				'source_bucket_id' => 10,
				'target_bucket_id' => 11,
				'reference_type' => 'bucket_transfer',
				'reference_id'   => 'T-500',
				'movement_type'  => 'warehouse_transfer',
				'quantity_delta' => -2,
				'metadata_json'  => array(
					'gross_amount' => 18.5,
					'net_amount'   => 16.25,
					'tax_amount'   => 1.3,
					'route_key'    => 'truck-a',
				),
			)
		);

		$this->assertSame( array( 'route_key' => 'truck-a' ), $movements->created[0]['metadata_json'] );
		$this->assertSame( 'SKU-99', $movements->created[0]['line_meta_json']['sku'] );
		$this->assertSame( 10, $movements->created[0]['line_meta_json']['source_bucket_id'] );
		$this->assertSame( 11, $movements->created[0]['line_meta_json']['target_bucket_id'] );
	}

	public function testPortableMovementLedgerPreservesOutboundSalePaymentSnapshot(): void {
		$movements = new class() implements MovementRepositoryInterface {
			public array $created = array();

			public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool {
				return false;
			}

			public function create( array $data ): int {
				$this->created[] = $data;
				return 909;
			}

			public function getBalanceForBucketProduct( int $bucketId, int $vendorId, int $productId ): float {
				return 2.0;
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
				return 'portable-uuid-005';
			}
		};

		$service = new MovementLedgerService( $movements, null, null, null, null, $policy, $clock, $uuid );
		$service->recordMovement(
			array(
				'bucket_id'            => 11,
				'vendor_id'            => 3,
				'product_id'           => 99,
				'reference_type'       => 'square_sale_line',
				'reference_id'         => 'sale-500',
				'movement_type'        => 'show_consumption',
				'quantity_delta'       => -1,
				'net_amount'           => 18.25,
				'tax_amount'           => 1.46,
				'square_order_id'      => 'ord-1',
				'square_line_item_uid' => 'line-1',
				'currency'             => 'USD',
			)
		);

		$this->assertSame(
			array(
				'amount_paid'          => 18.25,
				'amount_paid_cents'    => 1825,
				'tax_amount'           => 1.46,
				'tax_amount_cents'     => 146,
				'currency'             => 'USD',
				'square_order_id'      => 'ord-1',
				'square_line_item_uid' => 'line-1',
			),
			$movements->created[0]['metadata_json']
		);
	}

	public function testPortableMovementLedgerRejectsSaleMovementWithoutPaidAmount(): void {
		$movements = new class() implements MovementRepositoryInterface {
			public function hasReferenceApplication( string $referenceType, string $referenceId, int $productId, int $bucketId, string $movementType ): bool {
				return false;
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
				return 'portable-uuid-006';
			}
		};

		$service = new MovementLedgerService( $movements, null, null, null, null, $policy, $clock, $uuid );

		$this->expectException( MovementException::class );
		$this->expectExceptionMessage( 'Sale-side outbound inventory must include the amount paid.' );

		$service->recordMovement(
			array(
				'bucket_id'      => 11,
				'vendor_id'      => 3,
				'product_id'     => 99,
				'reference_type' => 'square_sale_line',
				'reference_id'   => 'sale-999',
				'movement_type'  => 'show_consumption',
				'quantity_delta' => -1,
			)
		);
	}
}
