<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryCustodyRelationshipRepositoryTest extends \AIMS\Tests\TestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb()->reset();
	}

	public function testSaveRelationshipPersistsDefaultRouteGuidance(): void {
		$repo = new \AIMS_Inventory_Custody_Endpoint_Relationship_Repository();

		$relationship_id = $repo->save(
			array(
				'source_endpoint_id' => 10,
				'target_endpoint_id' => 20,
				'relationship_key'   => 'default_route',
				'relationship_type'  => 'direct_route',
				'route_priority'     => 2,
				'route_policy'       => 'guidance',
				'is_default_route'   => true,
				'is_active'          => true,
				'guidance_label'     => 'Preferred route',
				'guidance_notes'     => 'Use this when available.',
			)
		);

		$this->assertGreaterThan( 0, $relationship_id );
		$this->assertSame( $this->wpdb()->prefix . 'aims_inventory_custody_endpoint_relationships', $this->wpdb()->inserted[0]['table'] );
		$this->assertSame( 10, $this->wpdb()->inserted[0]['data']['source_endpoint_id'] );
		$this->assertSame( 20, $this->wpdb()->inserted[0]['data']['target_endpoint_id'] );
		$this->assertSame( 'default_route', $this->wpdb()->inserted[0]['data']['relationship_key'] );
		$this->assertSame( 1, $this->wpdb()->inserted[0]['data']['is_default_route'] );
	}

	public function testGetDefaultRouteForSourceEndpointReturnsPreferredActiveRoute(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'id' => 101,
					'source_endpoint_id' => 10,
					'target_endpoint_id' => 20,
					'relationship_key' => 'default_route',
					'route_priority' => 1,
					'is_default_route' => 1,
					'is_active' => 1,
				),
			)
		);

		$repo = new \AIMS_Inventory_Custody_Endpoint_Relationship_Repository();
		$row = $repo->get_default_route_for_source_endpoint( 10 );

		$this->assertSame( 101, $row['id'] );
		$this->assertSame( 10, $this->wpdb()->last_prepare_args[0] );
		$this->assertSame( 1, $this->wpdb()->last_prepare_args[1] );
		$this->assertSame( 1, $this->wpdb()->last_prepare_args[2] );
		$this->assertSame( 1, $this->wpdb()->last_prepare_args[3] );
	}
}
