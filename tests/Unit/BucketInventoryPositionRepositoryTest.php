<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Bucket_Inventory_Position_Repository;

final class BucketInventoryPositionRepositoryTest extends \AIMS\Tests\TestCase {
	public function testGetBucketContentsSummaryAggregatesQuantitiesAndLatestCountedAt(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'id'                     => 1,
					'bucket_id'              => 300,
					'vendor_id'              => 5,
					'product_id'             => 901,
					'quantity'               => '4.0000',
					'reserved_quantity'      => '1.0000',
					'position_status'        => 'inactive',
					'last_bucket_movement_id'=> 9,
					'last_counted_at'        => '2026-03-20 09:00:00',
				),
				array(
					'id'                     => 2,
					'bucket_id'              => 300,
					'vendor_id'              => 5,
					'product_id'             => 901,
					'quantity'               => '6.0000',
					'reserved_quantity'      => '2.0000',
					'position_status'        => 'active',
					'last_bucket_movement_id'=> 12,
					'last_counted_at'        => '2026-03-20 11:15:00',
				),
				array(
					'id'                     => 3,
					'bucket_id'              => 300,
					'vendor_id'              => 5,
					'product_id'             => 902,
					'quantity'               => '3.5000',
					'reserved_quantity'      => '0.5000',
					'position_status'        => 'active',
					'last_bucket_movement_id'=> 8,
					'last_counted_at'        => '2026-03-20 10:30:00',
				),
			)
		);

		$repo    = new AIMS_Bucket_Inventory_Position_Repository();
		$summary = $repo->get_bucket_contents_summary( 300 );

		$this->assertCount( 2, $summary );
		$this->assertSame( 10.0, $summary[0]['quantity'] );
		$this->assertSame( 3.0, $summary[0]['reserved_quantity'] );
		$this->assertSame( 12, $summary[0]['last_bucket_movement_id'] );
		$this->assertSame( '2026-03-20 11:15:00', $summary[0]['last_counted_at'] );
		$this->assertSame( 'active', $summary[0]['position_status'] );
		$this->assertSame( 3.5, $summary[1]['quantity'] );
		$this->assertSame( 0.5, $summary[1]['reserved_quantity'] );
	}
}
