<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class StitchJobRepositoryTest extends \AIMS\Tests\TestCase {
	public function testGetOpenForUserFiltersByAssignedUserAndOpenStatuses(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'id'              => 701,
					'assigned_user_id' => 55,
					'status'          => 'in_progress',
				),
			)
		);

		$repo = new \AIMS_Stitch_Job_Repository();
		$jobs = $repo->get_open_for_user( 55 );

		$this->assertCount( 1, $jobs );
		$this->assertSame( 55, (int) $this->wpdb()->last_prepare_args[0] );
		$this->assertStringContainsString( 'status NOT IN', $this->wpdb()->last_query );
		$this->assertSame( 'in_transit_back', $this->wpdb()->last_prepare_args[1] );
		$this->assertSame( 'completed', $this->wpdb()->last_prepare_args[2] );
		$this->assertSame( 'returned', $this->wpdb()->last_prepare_args[3] );
		$this->assertSame( 'cancelled', $this->wpdb()->last_prepare_args[4] );
	}

	public function testMarkCompleteAndInTransitBackUpdatesJobState(): void {
		$this->wpdb()->queue_row(
			array(
				'id'     => 701,
				'notes'  => 'Existing note',
			)
		);
		$prefix = $this->wpdb()->prefix;

		$repo = new \AIMS_Stitch_Job_Repository();
		$result = $repo->mark_complete_and_in_transit_back( 701, 55, 'Finished stitching.' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->wpdb()->updated );
		$this->assertSame( $prefix . 'aims_stitch_jobs', $this->wpdb()->updated[0]['table'] );
		$this->assertSame( 'in_transit_back', $this->wpdb()->updated[0]['data']['status'] );
		$this->assertSame( 55, (int) $this->wpdb()->updated[0]['data']['assigned_user_id'] );
		$this->assertStringContainsString( 'Existing note', $this->wpdb()->updated[0]['data']['notes'] );
		$this->assertStringContainsString( 'Finished stitching.', $this->wpdb()->updated[0]['data']['notes'] );
	}
}
