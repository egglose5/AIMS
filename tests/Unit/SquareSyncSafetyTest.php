<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class SquareSyncSafetyTest extends \AIMS\Tests\TestCase {
	public function testFindLatestForRunActionTypeLoadsLatestMatchingAction(): void {
		$this->wpdb()->queue_row(
			array(
				'id'          => 91,
				'run_id'      => 12,
				'action_type' => 'replay_request',
				'status'      => 'success',
			)
		);

		$repository = new \AIMS_Sync_Action_Repository();
		$result     = $repository->find_latest_for_run_action_type( 12, 'Replay_Request' );

		$this->assertIsArray( $result );
		$this->assertSame( 91, (int) $result['id'] );
		$this->assertStringContainsString( 'WHERE run_id = 12 AND action_type = replay_request', $this->wpdb()->last_query );
	}

	public function testSquareSyncRunsSummaryAggregatesTelemetry(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'id'                => 40,
					'source_system'     => 'square',
					'processed_records' => 7,
					'error_count'       => 2,
					'completed_at'      => '2026-03-20 12:00:00',
					'success'           => 0,
				),
				array(
					'id'                => 39,
					'source_system'     => 'square',
					'processed_records' => 5,
					'error_count'       => 0,
					'completed_at'      => '2026-03-19 12:00:00',
					'success'           => 1,
				),
			)
		);

		$provider = new \AIMS_Square_Sync_Runs_Data_Provider();
		$summary  = $provider->get_summary();

		$this->assertSame( 2, $summary['total_runs'] );
		$this->assertSame( 12, $summary['total_processed_records'] );
		$this->assertSame( 2, $summary['total_error_count'] );
		$this->assertSame( 'failed', $summary['last_sync_status'] );
		$this->assertSame( '2026-03-20 12:00:00', $summary['last_sync_completed_at'] );
	}

	public function testSquareSyncRowsUseResponsibilityFlagsWhenEnabled(): void {
		TestState::set_current_user_id( 56 );
		update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		$this->wpdb()->queue_results(
			array(
				array(
					'id'                => 23,
					'source_system'     => 'square',
					'sync_watermark'    => '2026-03-24T11:00:00Z',
					'processed_records' => 4,
					'error_count'       => 0,
					'completed_at'      => '2026-03-24 11:05:00',
					'success'           => 1,
				),
			)
		);

		$auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_run_square_sync_replay( int $user_id = 0 ): bool {
				return 56 === $user_id;
			}

			public function can_run_square_sync_undo( int $user_id = 0 ): bool {
				return 56 === $user_id;
			}
		};

		$provider = new \AIMS_Square_Sync_Runs_Data_Provider( $auth );
		$rows     = $provider->get_rows();

		$this->assertCount( 1, $rows );
		$this->assertTrue( $rows[0]['can_replay'] );
		$this->assertTrue( $rows[0]['can_undo'] );
	}

	public function testSquareSyncRowsExposeWooProjectionSummaryFromEffectMetadata(): void {
		TestState::set_current_user_id( 56 );

		$this->wpdb()->queue_results(
			array(
				array(
					'id'                => 24,
					'source_system'     => 'square',
					'sync_watermark'    => '2026-03-25T11:00:00Z',
					'processed_records' => 6,
					'error_count'       => 1,
					'completed_at'      => '2026-03-25 11:05:00',
					'success'           => 1,
				),
			)
		);

		$this->wpdb()->queue_results(
			array(
				array(
					'id'           => 1001,
					'sync_run_id'  => 24,
					'metadata_json' => wp_json_encode(
						array(
							'square_order_id' => 'SQ-ORDER-ROOT',
							'projection' => array(
								array( 'status' => 'projected', 'reason' => 'draft_projected', 'square_order_id' => 'SQ-ORDER-1' ),
								array( 'status' => 'skipped', 'reason' => 'awaiting_reconciliation', 'square_order_id' => 'SQ-ORDER-2' ),
							),
						)
					),
				),
			)
		);

		$provider = new \AIMS_Square_Sync_Runs_Data_Provider();
		$rows     = $provider->get_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'mixed', $rows[0]['woo_projection_status'] );
		$this->assertStringContainsString( 'Projected 1 | Skipped 1 | Linked 0', (string) $rows[0]['woo_projection_summary'] );
		$this->assertStringContainsString( 'Top reason: awaiting reconciliation', (string) $rows[0]['woo_projection_summary'] );
		$this->assertStringContainsString( 'Reasons: awaiting reconciliation (1), draft projected (1)', (string) $rows[0]['woo_projection_details'] );
		$this->assertStringContainsString( 'Orders: SQ-ORDER-1, SQ-ORDER-2', (string) $rows[0]['woo_projection_details'] );
	}

	public function testProjectionEffectDetailsExposeNormalizedRowsForRequestedRun(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'id'            => 2001,
					'effect_type'   => 'import_projection',
					'target_table'  => 'aims_square_sales',
					'target_id'     => 3301,
					'created_at'    => '2026-04-11 14:10:00',
					'metadata_json' => wp_json_encode(
						array(
							'square_order_id' => 'SQ-ORDER-ROOT',
							'sale_id'         => 3301,
							'line_item_uid'   => 'LINE-ROOT',
							'projection'      => array(
								array(
									'status'          => 'projected',
									'reason'          => 'draft_projected',
									'woo_order_id'    => 8801,
									'projection_mode' => 'draft',
									'square_order_id' => 'SQ-ORDER-1',
									'line_item_uid'   => 'LINE-1',
								),
							),
						)
					),
				),
			)
		);

		$provider = new \AIMS_Square_Sync_Runs_Data_Provider();
		$details  = $provider->get_projection_effect_details( 24, 10 );

		$this->assertSame( 24, $details['run_id'] );
		$this->assertSame( 1, $details['total_rows'] );
		$this->assertSame( 2001, $details['rows'][0]['effect_id'] ?? 0 );
		$this->assertSame( 8801, $details['rows'][0]['woo_order_id'] ?? 0 );
		$this->assertSame( 'SQ-ORDER-1', $details['rows'][0]['square_order_id'] ?? '' );
		$this->assertSame( 'LINE-1', $details['rows'][0]['line_item_uid'] ?? '' );
	}

	public function testSquareSyncRunControllerRegistersParquetExportAction(): void {
		$controller = new \AIMS_Square_Sync_Run_Controller();
		$controller->register();

		$hook_calls = TestState::get_hook_calls( 'admin_post_aims_square_export_projection_parquet' );
		$this->assertNotEmpty( $hook_calls );
	}

	public function testSquareSyncRunControllerRegistersPromoteProjectionsAction(): void {
		$controller = new \AIMS_Square_Sync_Run_Controller();
		$controller->register();

		$hook_calls = TestState::get_hook_calls( 'admin_post_aims_square_promote_projections' );
		$this->assertNotEmpty( $hook_calls );
	}

	public function testSquareSyncRunsPageRendersPromoteButtonWhenProjectionStatusIsSet(): void {
		$page = new \AIMS_Square_Sync_Runs_Page(
			new class() extends \AIMS_Square_Sync_Runs_Data_Provider {
				public function __construct() {}

				public function get_rows(): array {
					return array(
						array(
							'run_id'                  => 25,
							'source_system'           => 'square',
							'sync_watermark'          => '2026-04-11T10:00:00Z',
							'status'                  => 'success',
							'processed_records'       => '3',
							'error_count'             => '0',
							'woo_projection_status'   => 'projected',
							'woo_projection_summary'  => 'Projected 3 | Skipped 0',
							'woo_projection_details'  => '',
							'completed_at'            => '2026-04-11 10:05:00',
							'can_replay'              => false,
							'can_undo'                => false,
						),
					);
				}

				public function get_summary(): array {
					return array(
						'total_runs'              => 1,
						'total_processed_records' => 3,
						'total_error_count'       => 0,
						'last_sync_completed_at'  => '2026-04-11 10:05:00',
						'last_sync_status'        => 'success',
					);
				}
			}
		);

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aims_square_promote_projections', $html );
		$this->assertStringContainsString( 'Promote Draft Projections', $html );
	}

	public function testSquareSyncRunsPageDoesNotRenderPromoteButtonWhenNoProjections(): void {
		$page = new \AIMS_Square_Sync_Runs_Page(
			new class() extends \AIMS_Square_Sync_Runs_Data_Provider {
				public function __construct() {}

				public function get_rows(): array {
					return array(
						array(
							'run_id'                  => 26,
							'source_system'           => 'square',
							'sync_watermark'          => '2026-04-11T10:00:00Z',
							'status'                  => 'success',
							'processed_records'       => '2',
							'error_count'             => '0',
							'woo_projection_status'   => 'none',
							'woo_projection_summary'  => 'No projection records',
							'woo_projection_details'  => '',
							'completed_at'            => '2026-04-11 10:05:00',
							'can_replay'              => false,
							'can_undo'                => false,
						),
					);
				}

				public function get_summary(): array {
					return array(
						'total_runs'              => 1,
						'total_processed_records' => 2,
						'total_error_count'       => 0,
						'last_sync_completed_at'  => '2026-04-11 10:05:00',
						'last_sync_status'        => 'success',
					);
				}
			}
		);

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'aims_square_promote_projections', $html );
		$this->assertStringNotContainsString( 'Promote Draft Projections', $html );
	}

	public function testSquareSyncRunsPageRendersExportParquetAction(): void {
		$page = new \AIMS_Square_Sync_Runs_Page(
			new class() extends \AIMS_Square_Sync_Runs_Data_Provider {
				public function __construct() {}

				public function get_rows(): array {
					return array(
						array(
							'run_id'                  => 24,
							'source_system'           => 'square',
							'sync_watermark'          => '2026-03-25T11:00:00Z',
							'status'                  => 'success',
							'processed_records'       => '6',
							'error_count'             => '1',
							'woo_projection_status'   => 'mixed',
							'woo_projection_summary'  => 'Projected 1 | Skipped 1 | Linked 0',
							'woo_projection_details'  => 'Reasons: awaiting reconciliation (1)',
							'completed_at'            => '2026-03-25 11:05:00',
							'can_replay'              => true,
							'can_undo'                => true,
						),
					);
				}

				public function get_summary(): array {
					return array(
						'total_runs'              => 1,
						'total_processed_records' => 6,
						'total_error_count'       => 1,
						'last_sync_completed_at'  => '2026-03-25 11:05:00',
						'last_sync_status'        => 'success',
					);
				}
			}
		);

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aims_square_export_projection_parquet', $html );
		$this->assertStringContainsString( 'Export Parquet', $html );
	}
}
