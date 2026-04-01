<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Bucket_Inventory_Movement_Repository;
use AIMS_Bucket_Inventory_Position_Repository;
use AIMS_Bucket_Movement_Service;
use AIMS_Bucket_Position_Service;
use AIMS_Movement_Lifecycle_Service;

final class BucketMovementServiceTest extends \AIMS\Tests\TestCase {
	public function testRecordMovementUsesMovementBalanceAsSourceOfTruth(): void {
		$movementRepo = new class() extends AIMS_Bucket_Inventory_Movement_Repository {
			public array $created = array();

			public function has_reference_application( string $reference_type, string $reference_id, int $product_id, int $bucket_id, string $movement_type ): bool {
				return false;
			}

			public function create( array $data ): int {
				$this->created[] = $data;
				return 101;
			}

			public function get_balance_for_bucket_product( int $bucket_id, int $vendor_id, int $product_id ): float {
				return 12.7500;
			}
		};

		$positionRepo = new class() extends AIMS_Bucket_Inventory_Position_Repository {
			public array $synchronized = array();

			public function synchronize_from_movements( AIMS_Bucket_Inventory_Movement_Repository $movements, int $bucket_id, int $vendor_id, int $product_id ): int {
				$this->synchronized[] = compact( 'bucket_id', 'vendor_id', 'product_id' );
				return 222;
			}
		};

		$lifecycle = new class() extends AIMS_Movement_Lifecycle_Service {
			public array $ensure_calls = array();
			public array $capture_calls = array();

			public function __construct() {}

			public function ensure_hot_batch( array $data ): array {
				$this->ensure_calls[] = $data;
				return array( 'id' => 333 );
			}

			public function capture_hot_line( int $batch_id, int $movement_id, array $data ): bool {
				$this->capture_calls[] = compact( 'batch_id', 'movement_id', 'data' );
				return true;
			}
		};

		$service = new AIMS_Bucket_Movement_Service( $movementRepo, $positionRepo, null, null, $lifecycle );

		$result = $service->record_stock_in(
			array(
				'bucket_id'      => 11,
				'vendor_id'      => 7,
				'product_id'     => 99,
				'reference_type' => 'inbound_receipt',
				'reference_id'   => 'R-123',
				'movement_type'  => 'origin_inbound',
				'quantity_delta' => 12.75,
			)
		);

		$this->assertSame( 101, $result['movement_id'] );
		$this->assertSame( 333, $result['movement_batch_id'] );
		$this->assertSame( 12.75, $result['current_quantity'] );
		$this->assertCount( 1, $positionRepo->synchronized );
		$this->assertCount( 1, $lifecycle->ensure_calls );
		$this->assertCount( 1, $lifecycle->capture_calls );
		$this->assertSame( 11, $positionRepo->synchronized[0]['bucket_id'] );
		$this->assertSame( 7, $positionRepo->synchronized[0]['vendor_id'] );
		$this->assertSame( 99, $positionRepo->synchronized[0]['product_id'] );
		$this->assertSame( 333, $movementRepo->created[0]['movement_batch_id'] );
		$this->assertSame( 'hot', $movementRepo->created[0]['movement_lifecycle'] );
	}

	public function testBucketPositionServiceRecaclulateUsesMovementSourceOfTruth(): void {
		$positionRepo = new class() extends AIMS_Bucket_Inventory_Position_Repository {
			public bool $sync_called = false;

			public function synchronize_from_movements( AIMS_Bucket_Inventory_Movement_Repository $movements, int $bucket_id, int $vendor_id, int $product_id ): int {
				$this->sync_called = true;
				return 77;
			}
		};

		$movements = new class() extends AIMS_Bucket_Inventory_Movement_Repository {
			public function get_balance_for_bucket_product( int $bucket_id, int $vendor_id, int $product_id ): float {
				return 8.5;
			}
		};

		$service = new AIMS_Bucket_Position_Service( $positionRepo, $movements );
		$result = $service->recalculate_position(
			array(
				'bucket_id' => 101,
				'vendor_id' => 11,
				'product_id'=> 22,
				'quantity' => 1,
				'reserved_quantity' => 0,
				'position_status' => 'active',
			)
		);

		$this->assertSame( 77, $result );
		$this->assertTrue( $positionRepo->sync_called );
	}
}
