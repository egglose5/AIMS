<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Contracts\BucketFifoStoreInterface;
use AmesCore\Inventory\BucketFifoService;

final class AmesCoreBucketFifoServiceTest extends \AIMS\Tests\TestCase {
	public function testBucketRegistrationUsesStandaloneStore(): void {
		$store = new class() implements BucketFifoStoreInterface {
			public array $lastBucket = array();

			public function initialize(): void {}

			public function upsertBucket( array $bucket ): array {
				$this->lastBucket = $bucket;
				return $bucket;
			}

			public function listBuckets( array $filters = array() ): array {
				return array();
			}

			public function receiveIntoBucket( array $receipt ): array {
				return array();
			}

			public function moveBucketCustody( array $movement ): array {
				return array();
			}

			public function fifoAvailability( array $filters = array() ): array {
				return array();
			}

			public function pickFifo( array $request ): array {
				return array();
			}
		};

		$service = new BucketFifoService( $store );
		$result = $service->registerBucket(
			array(
				'bucket_code'      => 'BUCKET-1',
				'current_location' => 'warehouse-a',
				'current_custody'  => 'main-team',
			)
		);

		$this->assertSame( 'BUCKET-1', $result['bucket_code'] );
		$this->assertSame( 'warehouse-a', $store->lastBucket['current_location'] );
		$this->assertSame( 'main-team', $store->lastBucket['current_custody'] );
	}

	public function testFifoReceiptRequiresCostSnapshotAndNormalizesCents(): void {
		$store = new class() implements BucketFifoStoreInterface {
			public array $lastReceipt = array();

			public function initialize(): void {}

			public function upsertBucket( array $bucket ): array {
				return array();
			}

			public function listBuckets( array $filters = array() ): array {
				return array();
			}

			public function receiveIntoBucket( array $receipt ): array {
				$this->lastReceipt = $receipt;
				return $receipt;
			}

			public function moveBucketCustody( array $movement ): array {
				return array();
			}

			public function fifoAvailability( array $filters = array() ): array {
				return array();
			}

			public function pickFifo( array $request ): array {
				return array();
			}
		};

		$service = new BucketFifoService( $store );
		$result = $service->receive(
			array(
				'bucket_code' => 'BUCKET-1',
				'sku'         => 'SKU-1',
				'quantity'    => 3,
				'unit_cost'   => 4.25,
			)
		);

		$this->assertSame( 4.25, $result['unit_cost'] );
		$this->assertSame( 425, $result['unit_cost_cents'] );
		$this->assertSame( 425, $store->lastReceipt['unit_cost_cents'] );
	}

	public function testFifoReceiptRejectsMissingCostSnapshot(): void {
		$store = new class() implements BucketFifoStoreInterface {
			public function initialize(): void {}
			public function upsertBucket( array $bucket ): array { return array(); }
			public function listBuckets( array $filters = array() ): array { return array(); }
			public function receiveIntoBucket( array $receipt ): array { return array(); }
			public function moveBucketCustody( array $movement ): array { return array(); }
			public function fifoAvailability( array $filters = array() ): array { return array(); }
			public function pickFifo( array $request ): array { return array(); }
		};

		$service = new BucketFifoService( $store );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'FIFO receipt requires unit_cost or unit_cost_cents.' );

		$service->receive(
			array(
				'bucket_code' => 'BUCKET-1',
				'sku'         => 'SKU-1',
				'quantity'    => 3,
			)
		);
	}

	public function testFifoPickUsesStandaloneStore(): void {
		$store = new class() implements BucketFifoStoreInterface {
			public array $lastPick = array();

			public function initialize(): void {}
			public function upsertBucket( array $bucket ): array { return array(); }
			public function listBuckets( array $filters = array() ): array { return array(); }
			public function receiveIntoBucket( array $receipt ): array { return array(); }
			public function moveBucketCustody( array $movement ): array { return array(); }
			public function fifoAvailability( array $filters = array() ): array { return array(); }

			public function pickFifo( array $request ): array {
				$this->lastPick = $request;
				return array(
					'sku'                => $request['sku'],
					'requested_quantity' => $request['quantity'],
					'allocated_quantity' => $request['quantity'],
					'allocations'        => array(
						array( 'bucket_code' => 'BUCKET-1', 'lot_uuid' => 'LOT-1', 'quantity' => $request['quantity'] ),
					),
				);
			}
		};

		$service = new BucketFifoService( $store );
		$result = $service->pick(
			array(
				'sku'      => 'SKU-1',
				'quantity' => 2,
			)
		);

		$this->assertSame( 'fifo_pick', $store->lastPick['movement_type'] );
		$this->assertSame( 2.0, $result['allocated_quantity'] );
		$this->assertSame( 'BUCKET-1', $result['allocations'][0]['bucket_code'] );
	}

	public function testFifoPickForwardsActualPaidAmountAndTax(): void {
		$store = new class() implements BucketFifoStoreInterface {
			public array $lastPick = array();

			public function initialize(): void {}
			public function upsertBucket( array $bucket ): array { return array(); }
			public function listBuckets( array $filters = array() ): array { return array(); }
			public function receiveIntoBucket( array $receipt ): array { return array(); }
			public function moveBucketCustody( array $movement ): array { return array(); }
			public function fifoAvailability( array $filters = array() ): array { return array(); }

			public function pickFifo( array $request ): array {
				$this->lastPick = $request;
				return array(
					'sku'                => $request['sku'],
					'requested_quantity' => $request['quantity'],
					'allocated_quantity' => $request['quantity'],
					'amount_paid_cents'  => $request['amount_paid_cents'],
					'tax_amount_cents'   => $request['tax_amount_cents'],
					'allocations'        => array(),
				);
			}
		};

		$service = new BucketFifoService( $store );
		$result = $service->pick(
			array(
				'sku'               => 'SKU-1',
				'quantity'          => 1,
				'amount_paid'       => 18.25,
				'tax_amount_cents'  => 146,
			)
		);

		$this->assertSame( 1825, $store->lastPick['amount_paid_cents'] );
		$this->assertSame( 146, $store->lastPick['tax_amount_cents'] );
		$this->assertSame( 1825, $result['amount_paid_cents'] );
		$this->assertSame( 146, $result['tax_amount_cents'] );
	}
}
