<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class LowStockAlertServiceTest extends \AIMS\Tests\TestCase {
	public function testDashboardSnapshotReportsTotalLowStockProductsBeyondDisplayLimit(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function __construct() {}

			public function get_all_positions( int $limit = 0 ): array {
				return array(
					array( 'position_status' => 'active', 'product_id' => 101, 'quantity' => 1, 'reserved_quantity' => 0, 'bucket_id' => 1, 'vendor_id' => 10 ),
					array( 'position_status' => 'active', 'product_id' => 102, 'quantity' => 2, 'reserved_quantity' => 0, 'bucket_id' => 1, 'vendor_id' => 10 ),
					array( 'position_status' => 'active', 'product_id' => 103, 'quantity' => 3, 'reserved_quantity' => 0, 'bucket_id' => 1, 'vendor_id' => 10 ),
				);
			}
		};

		$service  = new \AIMS_Low_Stock_Alert_Service( $positions, 3 );
		$snapshot = $service->get_dashboard_snapshot( 1 );

		$this->assertSame( 3, $snapshot['low_stock_products'] );
		$this->assertCount( 1, $snapshot['alerts'] );
	}

	public function testDashboardSnapshotAggregatesAvailableQuantityAndStatusAcrossRows(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function __construct() {}

			public function get_all_positions( int $limit = 0 ): array {
				return array(
					array( 'position_status' => 'active', 'product_id' => 200, 'quantity' => 5, 'reserved_quantity' => 3, 'bucket_id' => 1, 'vendor_id' => 11 ),
					array( 'position_status' => 'active', 'product_id' => 200, 'quantity' => 2, 'reserved_quantity' => 3, 'bucket_id' => 2, 'vendor_id' => 12 ),
					array( 'position_status' => 'archived', 'product_id' => 201, 'quantity' => 1, 'reserved_quantity' => 0, 'bucket_id' => 3, 'vendor_id' => 13 ),
				);
			}
		};

		$service  = new \AIMS_Low_Stock_Alert_Service( $positions, 1 );
		$snapshot = $service->get_dashboard_snapshot( 10 );

		$this->assertSame( 2, $snapshot['active_positions'] );
		$this->assertSame( 1, $snapshot['tracked_products'] );
		$this->assertSame( 1, $snapshot['low_stock_products'] );
		$this->assertCount( 1, $snapshot['alerts'] );
		$this->assertSame( 1.0, $snapshot['alerts'][0]['available_quantity'] );
		$this->assertSame( 'low', $snapshot['alerts'][0]['status'] );
		$this->assertSame( 2, $snapshot['alerts'][0]['bucket_count'] );
		$this->assertSame( 2, $snapshot['alerts'][0]['vendor_count'] );
	}
}
