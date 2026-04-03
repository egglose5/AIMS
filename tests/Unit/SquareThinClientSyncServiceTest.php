<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class SquareThinClientSyncServiceTest extends \AIMS\Tests\TestCase {
	public function testRunOverlapSyncPullsFromHeadlessAndPersistsSalesFlow(): void {
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'ok' => true,
						'result' => array(
							'orders' => array(
								array(
									'id' => 'sq-order-1',
									'location_id' => 'LOC-1',
									'created_at' => '2026-04-02T10:00:00Z',
									'line_items' => array(
										array(
											'uid' => 'line-1',
											'sku' => 'SKU-1',
											'quantity' => 1,
											'total_money' => array( 'amount' => 1200 ),
										),
									),
								),
							),
							'next_watermark' => '2026-04-02T10:15:00Z',
						),
					)
				),
			)
		);

		$client = new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' );
		$runs   = new class() extends \AIMS_Sync_Run_Repository {
			public array $started = array();
			public array $finished = array();

			public function start_run( array $data = array() ): int {
				$this->started[] = $data;
				return 44;
			}

			public function finish_run( int $run_id, array $data = array() ): bool {
				$this->finished[] = compact( 'run_id', 'data' );
				return true;
			}

			public function find_latest_for_source( string $source_system ): ?array {
				return array(
					'id' => 1,
					'sync_watermark' => '2026-04-02T10:00:00Z',
				);
			}
		};

		$import = new class() extends \AIMS_Square_Import_Service {
			public array $ingested = array();
			public array $persisted = array();

			public function __construct() {}

			public function ingest_order_payload( array $payload ): array {
				$this->ingested[] = $payload;
				return array( 'queue_id' => 7 );
			}

			public function persist_queue_to_sales_flow( array $payload, int $queue_id = 0 ): array {
				$this->persisted[] = compact( 'payload', 'queue_id' );
				return array( 'sale_ids' => array( 88 ) );
			}
		};

		$buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function get_all( array $args = array() ): array {
				return array(
					array( 'square_location_id' => 'LOC-1' ),
					array( 'square_location_id' => 'LOC-2' ),
				);
			}
		};

		$service = new \AIMS_Square_Thin_Client_Sync_Service(
			$client,
			$runs,
			$import,
			$buckets,
			new \AIMS_Event_Square_Location_Repository(),
			new \AIMS_Runtime_Assignment_Repository()
		);

		$result   = $service->run_overlap_sync();
		$requests = TestState::get_remote_requests();

		$this->assertSame( 1, $result['pulled_count'] );
		$this->assertSame( 1, $result['processed_count'] );
		$this->assertSame( '2026-04-02T10:15:00Z', $result['next_watermark'] );
		$this->assertCount( 1, $requests );
		$this->assertStringContainsString( '/internal/square/pull', $requests[0]['url'] );
		$this->assertStringContainsString( 'location_ids%5B0%5D=LOC-1', $requests[0]['url'] );
		$this->assertCount( 1, $import->ingested );
		$this->assertCount( 1, $runs->finished );
		$this->assertSame( '2026-04-02T10:15:00Z', $runs->finished[0]['data']['sync_watermark'] );
	}

	public function testEnsureScheduleRegistersCronWhenConfigured(): void {
		$service = new \AIMS_Square_Thin_Client_Sync_Service(
			new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' )
		);

		$service->ensure_schedule();

		$scheduled = TestState::get_scheduled_event( \AIMS_Square_Thin_Client_Sync_Service::CRON_HOOK );
		$this->assertSame( \AIMS_Square_Thin_Client_Sync_Service::CRON_INTERVAL, $scheduled['recurrence'] );
	}
}
