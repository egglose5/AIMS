<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class EventBucketMaterialRepositoryTest extends \AIMS\Tests\TestCase {
	public function testSavePersistsBucketScopedPlanningMaterial(): void {
		$this->wpdb()->reset();
		$repository = new \AIMS_Event_Bucket_Material_Repository();

		$material_id = $repository->save(
			array(
				'event_id'           => 22,
				'physical_bucket_id' => 300,
				'label'              => 'Check-In Signage',
				'quantity'           => 2,
				'unit'               => 'pcs',
				'is_required'        => 1,
				'is_consumable'      => 0,
				'packed_status'      => 'planned',
				'notes'              => 'Front table only.',
				'sort_order'         => 10,
			)
		);

		$this->assertSame( 1, $material_id );
		$this->assertCount( 1, $this->wpdb()->inserted );
		$this->assertSame( $this->wpdb()->prefix . 'aims_event_bucket_materials', $this->wpdb()->inserted[0]['table'] );
		$this->assertSame( 'check-insignage', $this->wpdb()->inserted[0]['data']['material_key'] );
		$this->assertSame( 'Check-In Signage', $this->wpdb()->inserted[0]['data']['label'] );
		$this->assertSame( 22, $this->wpdb()->inserted[0]['data']['event_id'] );
		$this->assertSame( 300, $this->wpdb()->inserted[0]['data']['physical_bucket_id'] );
	}

	public function testGetForEventBucketReturnsNormalizedRows(): void {
		$this->wpdb()->reset();
		$this->wpdb()->queue_results(
			array(
				array(
					'id'                 => 5,
					'event_id'           => 22,
					'physical_bucket_id' => 300,
					'material_key'       => 'tape',
					'label'              => 'Tape',
					'quantity'           => '3.0000',
					'unit'               => 'rolls',
					'is_required'        => 1,
					'is_consumable'      => 1,
					'packed_status'      => 'packed',
					'notes'              => 'Gaffer',
					'sort_order'         => 20,
					'created_at'         => '2026-04-03 10:00:00',
					'updated_at'         => '2026-04-03 10:05:00',
				),
			)
		);

		$repository = new \AIMS_Event_Bucket_Material_Repository();
		$rows = $repository->get_for_event_bucket( 22, 300 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Tape', $rows[0]['label'] );
		$this->assertSame( 3.0, $rows[0]['quantity'] );
		$this->assertTrue( $rows[0]['is_required'] );
		$this->assertTrue( $rows[0]['is_consumable'] );
		$this->assertSame( 'packed', $rows[0]['packed_status'] );
	}
}
