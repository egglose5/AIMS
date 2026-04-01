<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryCustodyEndpointRepositoryTest extends \AIMS\Tests\TestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb()->reset();
	}

	public function testSaveEndpointPersistsFlexibleNodeAndDirectCollectionFlags(): void {
		$repo = new \AIMS_Inventory_Custody_Endpoint_Repository();

		$endpoint_id = $repo->save(
			array(
				'endpoint_key'            => 'warehouse-main',
				'endpoint_name'           => 'Main Warehouse',
				'endpoint_type'           => 'warehouse',
				'endpoint_status'         => 'active',
				'node_ref_type'           => 'warehouse',
				'node_ref_id'             => 77,
				'parent_endpoint_id'      => 0,
				'default_route_policy'    => 'guidance',
				'allows_direct_collection' => true,
				'allows_direct_recovery'  => false,
				'notes'                   => 'Primary custody endpoint.',
			)
		);

		$this->assertSame( 1, $endpoint_id );
		$this->assertSame( $this->wpdb()->prefix . 'aims_inventory_custody_endpoints', $this->wpdb()->inserted[0]['table'] );
		$this->assertSame( 'warehouse-main', $this->wpdb()->inserted[0]['data']['endpoint_key'] );
		$this->assertSame( 'warehouse', $this->wpdb()->inserted[0]['data']['endpoint_type'] );
		$this->assertSame( 'warehouse', $this->wpdb()->inserted[0]['data']['node_ref_type'] );
		$this->assertSame( 77, $this->wpdb()->inserted[0]['data']['node_ref_id'] );
		$this->assertSame( 1, $this->wpdb()->inserted[0]['data']['allows_direct_collection'] );
		$this->assertSame( 0, $this->wpdb()->inserted[0]['data']['allows_direct_recovery'] );

		$this->wpdb()->queue_row(
			array(
				'id' => 1,
				'endpoint_key' => 'warehouse-main',
				'endpoint_name' => 'Main Warehouse',
			)
		);

		$found = $repo->find_by_key( 'warehouse-main' );
		$this->assertSame( 'warehouse-main', $found['endpoint_key'] );
	}

	public function testGetActiveForNodeFiltersByNodeRefTypeAndId(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'id' => 1,
					'endpoint_key' => 'vendor-001',
					'endpoint_type' => 'vendor',
					'endpoint_status' => 'active',
				),
			)
		);

		$repo = new \AIMS_Inventory_Custody_Endpoint_Repository();
		$rows = $repo->get_active_for_node( 'vendor', 55 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'vendor', $rows[0]['endpoint_type'] );
		$this->assertSame( 'active', $this->wpdb()->last_prepare_args[0] );
		$this->assertSame( 'vendor', $this->wpdb()->last_prepare_args[1] );
		$this->assertSame( 55, $this->wpdb()->last_prepare_args[2] );
	}
}
