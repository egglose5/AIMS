<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class StitchPayoutSnapshotServiceTest extends \AIMS\Tests\TestCase {
	public function testCaptureUsesStitcherSpecificRateBeforeDefaultFallback(): void {
		$cost_service = new class() extends \AIMS_Product_Cost_Service {
			public function __construct() {}

			public function resolve_unit_cost( int $product_id, int $vendor_id = 0 ): float {
				unset( $product_id );
				return 44 === $vendor_id ? 18.50 : 12.00;
			}
		};

		$snapshot_repo = new class() extends \AIMS_Stitch_Job_Item_Payout_Snapshot_Repository {
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $snapshot_id = 0 ): int {
				unset( $snapshot_id );
				$this->saved[] = $data;
				return 801;
			}
		};

		$service = new \AIMS_Stitch_Payout_Snapshot_Service( $cost_service, $snapshot_repo );
		$result = $service->capture_for_job_item(
			array(
				'id'               => 77,
				'stitch_job_id'    => 991,
				'vendor_id'        => 44,
				'producer_user_id' => 12,
				'stitcher_user_id' => 55,
				'product_id'       => 1501,
				'quantity_requested' => 3,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 801, $result['snapshot_id'] );
		$this->assertSame( 'stitcher_specific', $result['snapshot']['snapshot_source'] );
		$this->assertSame( 18.5, $result['snapshot']['unit_payout_snapshot'] );
		$this->assertSame( 3.0, $result['snapshot']['snapshot_quantity'] );
		$this->assertSame( 44, $snapshot_repo->saved[0]['vendor_id'] );
	}

	public function testCaptureFallsBackToDefaultRateWhenStitcherSpecificIsMissing(): void {
		$cost_service = new class() extends \AIMS_Product_Cost_Service {
			public function __construct() {}

			public function resolve_unit_cost( int $product_id, int $vendor_id = 0 ): float {
				unset( $product_id );
				return 0 === $vendor_id ? 11.25 : 0.0;
			}
		};

		$snapshot_repo = new class() extends \AIMS_Stitch_Job_Item_Payout_Snapshot_Repository {
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $snapshot_id = 0 ): int {
				unset( $snapshot_id );
				$this->saved[] = $data;
				return 802;
			}
		};

		$service = new \AIMS_Stitch_Payout_Snapshot_Service( $cost_service, $snapshot_repo );
		$result = $service->capture_for_job_item(
			array(
				'id'             => 78,
				'stitch_job_id'  => 992,
				'vendor_id'      => 44,
				'product_id'     => 1502,
				'quantity_completed' => 2,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 802, $result['snapshot_id'] );
		$this->assertSame( 'default_fallback', $result['snapshot']['snapshot_source'] );
		$this->assertSame( 11.25, $result['snapshot']['unit_payout_snapshot'] );
		$this->assertSame( 2.0, $result['snapshot']['snapshot_quantity'] );
	}
}
