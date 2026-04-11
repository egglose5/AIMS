<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchWorkspaceDataProviderTest extends \AIMS\Tests\TestCase {
	public function testWorkspaceModelShowsLinesAndContextForSelectedJob(): void {
		TestState::set_current_user_id( 22 );
		TestState::set_user_capabilities(
			22,
			array(
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

		$source = new class() {
			public function list_stitch_jobs(): array {
				return array(
					array(
						'job_id'        => 200,
						'job_code'      => 'SJ-200',
						'job_name'      => 'Fall Stitch',
						'status'        => 'in_progress',
						'stitcher_name' => 'Stitcher Two',
						'line_count'    => 2,
						'total_quantity'=> 6,
					),
				);
			}

			public function get_stitch_job( int $job_id ): ?array {
				return array(
					'job_id'        => $job_id,
					'job_code'      => 'SJ-200',
					'job_name'      => 'Fall Stitch',
					'status'        => 'in_progress',
					'stitcher_name' => 'Stitcher Two',
					'line_count'    => 2,
					'total_quantity'=> 6,
					'notes'         => 'Producer notes',
				);
			}

			public function get_stitch_job_lines( int $job_id ): array {
				return array(
					array(
						'line_id'       => 1,
						'job_id'        => $job_id,
						'product_name'  => 'Demo Top',
						'product_sku'   => 'TOP-1',
						'quantity'      => 4,
						'completed_quantity' => 2,
						'status'        => 'in_progress',
					),
					array(
						'line_id'       => 2,
						'job_id'        => $job_id,
						'product_name'  => 'Demo Bottom',
						'product_sku'   => 'BOT-1',
						'quantity'      => 2,
						'completed_quantity' => 2,
						'status'        => 'completed',
					),
				);
			}

			public function get_stitch_job_assignment_context( int $job_id ): array {
				return array(
					'stitcher_name' => 'Stitcher Two',
					'producer_name' => 'Producer One',
					'job_id'        => $job_id,
				);
			}

			public function get_stitch_job_progress_summary( int $job_id ): array {
				return array(
					'completed_line_count' => 1,
					'progress_percent'     => 50,
				);
			}
		};

		$provider = new \AIMS_Stitch_Workspace_Data_Provider( $source );
		$model    = $provider->get_page_model( array( 'stitch_job_id' => 200 ) );

		$this->assertSame( 200, $model['selected_job_id'] );
		$this->assertSame( 'Fall Stitch', $model['selected_job']['job_name'] );
		$this->assertCount( 2, $model['lines'] );
		$this->assertSame( 2.0, $model['lines'][0]['remaining'] );
		$this->assertSame( 'TOP-1', $model['lines'][0]['product_sku'] );
		$this->assertSame( 'Stitcher Two', $model['assignment_context']['stitcher_name'] );
		$this->assertSame( 50.0, $model['workspace_summary']['progress_percent'] );
		$this->assertFalse( $model['safe_actions_enabled'] );
	}

	public function testWorkspaceModelBuildsPreStitchLabelPayloadFromJobLines(): void {
		TestState::set_current_user_id( 22 );
		TestState::set_user_capabilities(
			22,
			array(
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

		$source = new class() {
			public function list_stitch_jobs(): array {
				return array(
					array(
						'job_id'        => 410,
						'job_code'      => 'SJ-410',
						'job_name'      => 'Barcode Prep',
						'status'        => 'queued',
						'stitcher_name' => 'Stitcher Four',
						'line_count'    => 2,
						'total_quantity'=> 5,
					),
				);
			}

			public function get_stitch_job( int $job_id ): ?array {
				return array(
					'job_id'        => $job_id,
					'job_code'      => 'SJ-410',
					'job_name'      => 'Barcode Prep',
					'status'        => 'queued',
					'stitcher_name' => 'Stitcher Four',
					'line_count'    => 2,
					'total_quantity'=> 5,
				);
			}

			public function get_stitch_job_lines( int $job_id ): array {
				return array(
					array(
						'line_id'       => 10,
						'job_id'        => $job_id,
						'product_name'  => 'Prep Tee',
						'product_sku'   => 'PREP-TEE',
						'quantity'      => 3,
						'completed_quantity' => 0,
						'status'        => 'queued',
					),
					array(
						'line_id'       => 11,
						'job_id'        => $job_id,
						'product_name'  => 'Prep Hoodie',
						'product_sku'   => 'PREP-HOOD',
						'quantity'      => 2,
						'completed_quantity' => 0,
						'status'        => 'queued',
					),
				);
			}
		};

		$provider = new \AIMS_Stitch_Workspace_Data_Provider( $source );
		$model    = $provider->get_page_model( array( 'stitch_job_id' => 410 ) );

		$this->assertCount( 2, $model['pre_handoff_label_items'] );
		$this->assertSame( 'PREP-TEE', $model['pre_handoff_label_items'][0]['product_sku'] );
		$this->assertSame( 3, $model['pre_handoff_label_items'][0]['quantity'] );
		$this->assertStringContainsString( 'PREP-HOOD', $model['pre_handoff_label_items_json'] );
		$this->assertSame( 5, $model['pre_handoff_label_total'] );
	}

	public function testWorkspaceModelFallsBackToFirstJobWhenSelectionMissing(): void {
		TestState::set_current_user_id( 22 );
		TestState::set_user_capabilities(
			22,
			array(
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);

		$source = new class() {
			public function list_stitch_jobs(): array {
				return array(
					array(
						'job_id'        => 300,
						'job_code'      => 'SJ-300',
						'job_name'      => 'Winter Stitch',
						'status'        => 'open',
						'stitcher_name' => 'Stitcher Three',
						'line_count'    => 1,
						'total_quantity'=> 1,
					),
				);
			}

			public function get_stitch_job( int $job_id ): ?array {
				return null;
			}
		};

		$provider = new \AIMS_Stitch_Workspace_Data_Provider( $source );
		$model    = $provider->get_page_model();

		$this->assertSame( 300, $model['selected_job_id'] );
		$this->assertSame( 'Winter Stitch', $model['selected_job']['job_name'] );
		$this->assertSame( '', $model['selection_message'] );
	}
}
