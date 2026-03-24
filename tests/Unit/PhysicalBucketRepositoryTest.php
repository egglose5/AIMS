<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Physical_Bucket_Repository;

final class PhysicalBucketRepositoryTest extends \AIMS\Tests\TestCase {
	public function testGetAvailableForPlanningReturnsHydratedAvailableBuckets(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'id'                           => 300,
					'bucket_code'                  => 'B-300',
					'bucket_label'                 => 'Blue Bin',
					'bucket_type'                  => 'standard',
					'status'                       => 'available',
					'current_storage_location_id'  => 12,
					'home_storage_location_id'     => 11,
					'vendor_id'                    => 5,
					'barcode_value'                => 'BAR300',
					'current_location_id'          => 12,
					'current_location_code'        => 'STG-A',
					'current_location_name'        => 'Staging A',
					'current_location_type'        => 'staging',
					'current_location_parent_id'   => 0,
					'current_location_sort_order'  => 1,
					'current_location_is_pickable'  => 1,
					'current_location_is_staging'   => 1,
					'current_location_status'      => 'active',
					'current_location_barcode'     => 'LOC-12',
					'home_location_id'             => 11,
					'home_location_code'           => 'WH-A',
					'home_location_name'           => 'Warehouse A',
					'home_location_type'           => 'warehouse',
					'home_location_parent_id'      => 0,
					'home_location_sort_order'     => 2,
					'home_location_is_pickable'    => 1,
					'home_location_is_staging'     => 0,
					'home_location_status'         => 'active',
					'home_location_barcode'        => 'LOC-11',
				),
			)
		);

		$repo  = new AIMS_Physical_Bucket_Repository();
		$rows  = $repo->get_available_for_planning( array( 'vendor_id' => 5 ) );
		$query = $this->wpdb()->last_query;

		$this->assertStringContainsString( 'b.status = available', $query );
		$this->assertStringContainsString( 'b.vendor_id = 5', $query );
		$this->assertCount( 1, $rows );
		$this->assertSame( 300, (int) $rows[0]['id'] );
		$this->assertSame( 'Blue Bin', $rows[0]['bucket_label'] );
		$this->assertSame( 'Staging A', $rows[0]['current_storage_location']['location_name'] );
		$this->assertSame( 'Warehouse A', $rows[0]['home_storage_location']['location_name'] );
	}
}
