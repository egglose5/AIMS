<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SaleFulfillmentAllocationRepositoryTest extends \AIMS\Tests\TestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb()->reset();
	}

	public function testSaveUpdatesExistingAllocationForSquareSaleId(): void {
		$this->wpdb()->queue_row(
			array(
				'id' => 44,
				'square_sale_id' => 7001,
				'allocation_type' => 'event_stock',
			)
		);

		$repo = new \AIMS_Sale_Fulfillment_Allocation_Repository();
		$allocation_id = $repo->save(
			array(
				'square_sale_id' => 7001,
				'square_order_id' => 'SQ-ORDER-55',
				'product_id' => 901,
				'vendor_id' => 11,
				'event_id' => 22,
				'allocation_type' => 'event_stock',
				'allocation_status' => 'allocated',
				'quantity' => 2,
			)
		);

		$this->assertSame( 44, $allocation_id );
		$this->assertCount( 0, $this->wpdb()->inserted );
		$this->assertCount( 1, $this->wpdb()->updated );
		$this->assertSame( 44, $this->wpdb()->updated[0]['where']['id'] );
	}

	public function testSaveFallsBackToExistingOrderProductAllocationIdentityWhenSaleIdMissing(): void {
		$this->wpdb()->queue_row(
			array(
				'id' => 51,
				'square_order_id' => 'SQ-ORDER-88',
				'product_id' => 777,
				'allocation_type' => 'warehouse_backorder',
			)
		);

		$repo = new \AIMS_Sale_Fulfillment_Allocation_Repository();
		$allocation_id = $repo->save(
			array(
				'square_sale_id' => 0,
				'square_order_id' => 'SQ-ORDER-88',
				'product_id' => 777,
				'vendor_id' => 12,
				'event_id' => 0,
				'allocation_type' => 'warehouse_backorder',
				'allocation_status' => 'backordered',
				'quantity' => 1,
			)
		);

		$this->assertSame( 51, $allocation_id );
		$this->assertCount( 0, $this->wpdb()->inserted );
		$this->assertCount( 1, $this->wpdb()->updated );
		$this->assertSame( 51, $this->wpdb()->updated[0]['where']['id'] );
	}
}
