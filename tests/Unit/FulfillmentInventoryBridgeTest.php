<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class FulfillmentInventoryBridgeTest extends \AIMS\Tests\TestCase {

	public function testReserveForAllocationIncrementsReservedQuantityOnMatchingPosition(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function __construct() {}

			public array $increments = array();

			public function find_by_bucket_vendor_product( int $bucket_id, int $vendor_id, int $product_id ): ?array {
				return null; // force fallback to vendor+product search
			}

			public function find_by_vendor_and_product( int $vendor_id, int $product_id ): array {
				if ( 10 === $vendor_id && 200 === $product_id ) {
					return array( array( 'id' => 77, 'vendor_id' => 10, 'product_id' => 200, 'reserved_quantity' => '0.0000', 'position_status' => 'active' ) );
				}
				return array();
			}

			public function increment_reserved_quantity( int $position_id, float $delta ): bool {
				$this->increments[] = array( 'position_id' => $position_id, 'delta' => $delta );
				return true;
			}
		};

		$bridge = new \AIMS_Fulfillment_Inventory_Bridge( $positions );
		$result = $bridge->reserve_for_allocation(
			101,
			array(
				'vendor_id'  => 10,
				'product_id' => 200,
				'quantity'   => 3.0,
			)
		);

		$this->assertTrue( $result['applied'], 'reserve_for_allocation should apply the increment.' );
		$this->assertSame( 77, $result['position_id'] );
		$this->assertSame( 3.0, $result['delta'] );
		$this->assertCount( 1, $positions->increments );
		$this->assertSame( 77, $positions->increments[0]['position_id'] );
		$this->assertSame( 3.0, $positions->increments[0]['delta'] );
	}

	public function testReserveForAllocationPrefersBucketPositionOverVendorSearch(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function __construct() {}
			public array $increments = array();

			public function find_by_bucket_vendor_product( int $bucket_id, int $vendor_id, int $product_id ): ?array {
				if ( 5 === $bucket_id && 10 === $vendor_id && 200 === $product_id ) {
					return array( 'id' => 88, 'vendor_id' => 10, 'product_id' => 200, 'position_status' => 'active' );
				}
				return null;
			}

			public function find_by_vendor_and_product( int $vendor_id, int $product_id ): array {
				return array( array( 'id' => 99, 'vendor_id' => 10, 'product_id' => 200, 'position_status' => 'active' ) );
			}

			public function increment_reserved_quantity( int $position_id, float $delta ): bool {
				$this->increments[] = array( 'position_id' => $position_id, 'delta' => $delta );
				return true;
			}
		};

		$bridge = new \AIMS_Fulfillment_Inventory_Bridge( $positions );
		$result = $bridge->reserve_for_allocation(
			102,
			array(
				'source_bucket_id' => 5,
				'vendor_id'        => 10,
				'product_id'       => 200,
				'quantity'         => 2.0,
			)
		);

		$this->assertSame( 88, $result['position_id'], 'Should prefer bucket-specific position over vendor+product fallback.' );
	}

	public function testReserveForAllocationReturnsNoopWhenNoPositionFound(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function __construct() {}
			public function find_by_bucket_vendor_product( int $bucket_id, int $vendor_id, int $product_id ): ?array {
				return null;
			}
			public function find_by_vendor_and_product( int $vendor_id, int $product_id ): array {
				return array();
			}
			public function increment_reserved_quantity( int $position_id, float $delta ): bool {
				return true;
			}
		};

		$bridge  = new \AIMS_Fulfillment_Inventory_Bridge( $positions );
		$result  = $bridge->reserve_for_allocation( 103, array( 'vendor_id' => 10, 'product_id' => 300, 'quantity' => 1.0 ) );

		$this->assertFalse( $result['applied'] );
		$this->assertSame( 0, $result['position_id'] );
	}

	public function testReserveForAllocationReturnsNoopForZeroQuantity(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function __construct() {}
			public function find_by_vendor_and_product( int $vendor_id, int $product_id ): array {
				return array( array( 'id' => 55, 'position_status' => 'active' ) );
			}
			public function increment_reserved_quantity( int $position_id, float $delta ): bool {
				return true;
			}
		};

		$bridge = new \AIMS_Fulfillment_Inventory_Bridge( $positions );
		$result = $bridge->reserve_for_allocation( 104, array( 'vendor_id' => 10, 'product_id' => 200, 'quantity' => 0.0 ) );

		$this->assertFalse( $result['applied'], 'Zero-quantity allocations should not be reserved.' );
	}

	public function testTransitionAllocationStatusCallsUpdateStatusOnRepository(): void {
		$allocations = new class() extends \AIMS_Sale_Fulfillment_Allocation_Repository {
			public function __construct() {}
			public array $updates = array();
			public function update_status( int $allocation_id, string $to_status ): bool {
				$this->updates[] = array( 'id' => $allocation_id, 'status' => $to_status );
				return true;
			}
		};

		$service = new \AIMS_Fulfillment_Service( $allocations );
		$result  = $service->transition_allocation_status( 200, \AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_SHIPPED );

		$this->assertTrue( $result );
		$this->assertCount( 1, $allocations->updates );
		$this->assertSame( 200, $allocations->updates[0]['id'] );
		$this->assertSame( \AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_SHIPPED, $allocations->updates[0]['status'] );
	}

	public function testTransitionAllocationStatusReturnsFalseForInvalidId(): void {
		$allocations = new class() extends \AIMS_Sale_Fulfillment_Allocation_Repository {
			public function __construct() {}
			public function update_status( int $allocation_id, string $to_status ): bool {
				return true;
			}
		};

		$service = new \AIMS_Fulfillment_Service( $allocations );
		$this->assertFalse( $service->transition_allocation_status( 0, \AIMS_Sale_Fulfillment_Allocation_Repository::STATUS_SHIPPED ) );
	}
}
