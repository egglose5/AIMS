<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchJobItemServiceTest extends \AIMS\Tests\TestCase {
	public function testAssignJobItemCapturesSnapshotAndPersistsAssignmentState(): void {
		TestState::set_current_user_id( 201 );
		TestState::set_current_time( '2026-03-25 09:00:00' );

		$item_repo = new class() extends \AIMS_Stitch_Job_Item_Repository {
			public array $saved = array();
			public array $snapshots = array();

			public function __construct() {}

			public function save( array $data, int $item_id = 0 ): int {
				unset( $item_id );
				$this->saved[] = $data;
				return 601;
			}

			public function set_payout_snapshot( int $item_id, array $snapshot ): bool {
				$this->snapshots[] = array(
					'item_id'  => $item_id,
					'snapshot' => $snapshot,
				);
				return true;
			}
		};

		$payout_service = new class() extends \AIMS_Stitch_Payout_Snapshot_Service {
			public array $calls = array();

			public function __construct() {}

			public function capture_for_job_item( array $item ): array {
				$this->calls[] = $item;

				return array(
					'success'    => true,
					'snapshot_id' => 901,
					'snapshot'   => array(
						'unit_payout_snapshot' => 14.75,
						'snapshot_source'      => 'stitcher_specific',
						'snapshot_priority'    => 1,
						'snapshot_rule_id'     => 0,
						'captured_at'          => '2026-03-25 09:00:00',
					),
				);
			}
		};

		$producer_auth = new class() extends \AIMS_Stitch_Producer_Authorization_Service {
			public function __construct() {}

			public function can_manage_stitch_orders( int $user_id = 0 ): bool {
				return 201 === $user_id;
			}
		};

		$service = new \AIMS_Stitch_Job_Item_Service( $item_repo, $payout_service, $producer_auth );
		$result = $service->assign_job_item(
			array(
				'stitch_job_id'    => 991,
				'line_number'      => 3,
				'product_id'       => 1501,
				'vendor_id'        => 44,
				'producer_user_id' => 201,
				'stitcher_user_id' => 55,
				'quantity_requested' => 2.5,
				'stitch_job_type'  => 'custom_fit',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 601, $result['item_id'] );
		$this->assertSame( \AIMS_Stitch_Job_Item_Repository::STATUS_ASSIGNED, $item_repo->saved[0]['status'] );
		$this->assertNotEmpty( $item_repo->saved[0]['assigned_at'] );
		$this->assertSame( 14.75, $result['item']['unit_payout_snapshot'] );
		$this->assertSame( 'stitcher_specific', $result['snapshot']['snapshot_source'] );
		$this->assertSame( 'stitcher_specific', $result['item']['snapshot_source'] );
		$this->assertCount( 1, $item_repo->snapshots );
		$this->assertSame( 601, $item_repo->snapshots[0]['item_id'] );
	}

	public function testLifecycleUpdatesCompletedAndReceivedBackQuantities(): void {
		$item_repo = new class() extends \AIMS_Stitch_Job_Item_Repository {
			public array $completed_calls = array();
			public array $received_back_calls = array();

			public function __construct() {}

			public function find( int $item_id ): ?array {
				return 601 === $item_id ? array(
					'id'                 => 601,
					'stitch_job_id'      => 991,
					'product_id'         => 1501,
					'status'             => self::STATUS_ASSIGNED,
					'quantity_requested' => '2.5000',
				) : null;
			}

			public function mark_completed( int $item_id, float $quantity_completed, array $data = array() ): bool {
				$this->completed_calls[] = compact( 'item_id', 'quantity_completed', 'data' );
				return true;
			}

			public function mark_received_back( int $item_id, float $quantity_received_back, array $data = array() ): bool {
				$this->received_back_calls[] = compact( 'item_id', 'quantity_received_back', 'data' );
				return true;
			}
		};

		$service = new \AIMS_Stitch_Job_Item_Service(
			$item_repo,
			new class() extends \AIMS_Stitch_Payout_Snapshot_Service {
				public function __construct() {}
			},
			new class() extends \AIMS_Stitch_Producer_Authorization_Service {
				public function __construct() {}
			}
		);

		$completed = $service->record_completed_quantity( 601, 2.0, array( 'notes' => 'Finished' ) );
		$received_back = $service->record_received_back_quantity( 601, 1.5, array( 'notes' => 'Returned to warehouse' ) );

		$this->assertTrue( $completed['success'] );
		$this->assertTrue( $received_back['success'] );
		$this->assertSame( 2.0, $item_repo->completed_calls[0]['quantity_completed'] );
		$this->assertSame( \AIMS_Stitch_Job_Item_Repository::STATUS_COMPLETED, $item_repo->completed_calls[0]['data']['status'] );
		$this->assertSame( 1.5, $item_repo->received_back_calls[0]['quantity_received_back'] );
		$this->assertSame( \AIMS_Stitch_Job_Item_Repository::STATUS_RECEIVED_BACK, $item_repo->received_back_calls[0]['data']['status'] );
	}
}
