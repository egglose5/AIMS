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
					'square_location_id'           => 'LOC-12',
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
		$this->assertSame( 'LOC-12', $rows[0]['square_location_id'] );
		$this->assertSame( 'Staging A', $rows[0]['current_storage_location']['location_name'] );
		$this->assertSame( 'Warehouse A', $rows[0]['home_storage_location']['location_name'] );
	}

	public function testGetSourceAndTargetForEndpointApplyEndpointAwareFilters(): void {
		$this->wpdb()->queue_results( array() );
		$this->wpdb()->queue_results( array() );

		$repo = new AIMS_Physical_Bucket_Repository();
		$repo->get_source_for_endpoint( 'warehouse' );
		$source_args = $this->wpdb()->last_prepare_args;

		$this->assertContains( 'available', $source_args );
		$this->assertContains( 'staged', $source_args );
		$this->assertContains( 'in_transit', $source_args );
		$this->assertContains( 'warehouse', $source_args );
		$this->assertContains( 'staging', $source_args );

		$repo->get_target_for_endpoint( 'warehouse' );
		$target_args = $this->wpdb()->last_prepare_args;

		$this->assertContains( 'available', $target_args );
		$this->assertContains( 'staged', $target_args );
		$this->assertNotContains( 'in_transit', $target_args );
		$this->assertContains( 'warehouse', $target_args );
		$this->assertContains( 'staging', $target_args );
	}
}
