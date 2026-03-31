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
}
