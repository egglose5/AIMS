<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchJobsDataProviderTest extends \AIMS\Tests\TestCase {
	public function testLandingModelSummarizesJobsAndStitcherDirectory(): void {
		TestState::set_current_user_id( 21 );
		TestState::set_user_capabilities(
			21,
			array(
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

		$source = new class() {
			public function list_stitch_jobs(): array {
				return array(
					array(
						'job_id'        => 100,
						'job_code'      => 'SJ-100',
						'job_name'      => 'Spring Stitch',
						'status'        => 'in_progress',
						'stitcher_name' => 'Stitcher One',
						'line_count'    => 2,
						'total_quantity'=> 8.5,
						'created_at'    => '2026-03-20 10:00:00',
					),
					array(
						'job_id'        => 101,
						'job_code'      => 'SJ-101',
						'job_name'      => 'Summer Stitch',
						'status'        => 'open',
						'stitcher_name' => '',
						'line_count'    => 1,
						'total_quantity'=> 2,
						'created_at'    => '2026-03-21 10:00:00',
					),
				);
			}

			public function get_stitch_job( int $job_id ): ?array {
				return null;
			}
		};

		$provider = new \AIMS_Stitch_Jobs_Data_Provider( $source );
		$model    = $provider->get_page_model();

		$this->assertTrue( $model['can_manage'] );
		$this->assertCount( 2, $model['jobs'] );
		$this->assertSame( 2, $model['summary']['total_jobs'] );
		$this->assertSame( 1, $model['summary']['open_jobs'] );
		$this->assertSame( 1, $model['summary']['in_progress'] );
		$this->assertSame( 0, $model['summary']['completed_jobs'] );
		$this->assertCount( 2, $model['stitcher_directory'] );
		$this->assertSame( 'Stitcher One', $model['stitcher_directory'][0]['stitcher_name'] );
		$this->assertSame( 'Unassigned', $model['stitcher_directory'][1]['stitcher_name'] );
		$this->assertStringContainsString( 'page=aims-stitch-workspace', $model['workspace_url'] );
	}
}
