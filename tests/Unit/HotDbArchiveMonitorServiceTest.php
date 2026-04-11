<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class HotDbArchiveMonitorServiceTest extends \AIMS\Tests\TestCase {
	public function testEnsureScheduleRegistersArchiveMonitorCronWhenConfigured(): void {
		$service = new \AIMS_Hot_Db_Archive_Monitor_Service(
			new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' )
		);

		$service->ensure_schedule();

		$scheduled = TestState::get_scheduled_event( \AIMS_Hot_Db_Archive_Monitor_Service::CRON_HOOK );
		$this->assertSame( \AIMS_Hot_Db_Archive_Monitor_Service::CRON_INTERVAL, $scheduled['recurrence'] );
	}

	public function testRunMonitorTriggersArchiveWhenThresholdCrossed(): void {
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'ok' => true,
						'archived' => array(
							array(
								'show_id' => 'SPRING-SHOW',
								'row_count' => 1200,
							),
						),
						'message' => 'Archive run completed.',
					)
				),
			)
		);

		$health = new class() extends \AIMS_Hot_Db_Health_Service {
			public function __construct() {}

			public function get_dashboard_snapshot(): array {
				return array(
					'band' => 'yellow',
					'total_hot_rows' => 120000,
					'should_warn' => true,
					'should_auto_archive' => true,
					'warning_level' => 'archive',
					'message' => 'Automatic archive rotation is now recommended.',
				);
			}
		};

		$service = new \AIMS_Hot_Db_Archive_Monitor_Service(
			new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' ),
			$health
		);

		$result = $service->run_monitor();
		$requests = TestState::get_remote_requests();
		$status = TestState::get_option( \AIMS_Hot_Db_Archive_Monitor_Service::OPTION_LAST_RUN_STATUS, array() );

		$this->assertTrue( $result['archive_triggered'] );
		$this->assertCount( 1, $requests );
		$this->assertStringContainsString( '/internal/archive', $requests[0]['url'] );
		$this->assertSame( 'triggered', $status['status'] );
		$this->assertSame( 120000, $status['snapshot']['total_hot_rows'] );
	}
}
