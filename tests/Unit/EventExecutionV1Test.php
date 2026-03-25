<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Bucket_Movement_Service;
use AIMS_Event_Bucket_Assignment_Service;
use AIMS_Event_Planning_Action_Service;
use AIMS\Tests\Support\TestState;

final class EventExecutionV1Test extends \AIMS\Tests\TestCase {
	public function testPlanningAssignmentDefaultsBucketStatusToStaged(): void {
		TestState::set_current_user_id( 77 );

		$assignment_repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public array $saved = array();

			public function save( array $data, int $assignment_id = 0 ): int {
				$this->saved[] = array(
					'data'          => $data,
					'assignment_id' => $assignment_id,
				);

				return 501;
			}
		};

		$access_service = new class() {
			public function can_access_event_planning( int $user_id ): bool {
				return 77 === $user_id;
			}

			public function get_authorized_event_ids( int $user_id ): array {
				return 77 === $user_id ? array( 10 ) : array();
			}
		};

		$service = new AIMS_Event_Planning_Action_Service(
			new AIMS_Event_Bucket_Assignment_Service( $assignment_repo ),
			$access_service,
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {}
		);

		$result = $service->assign_bucket(
			array(
				'event_id'           => 10,
				'physical_bucket_id' => 200,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 501, $result['assignment_id'] );
		$this->assertCount( 1, $assignment_repo->saved );
		$this->assertSame( 10, $assignment_repo->saved[0]['data']['event_id'] );
		$this->assertSame( 200, $assignment_repo->saved[0]['data']['physical_bucket_id'] );
		$this->assertSame( \AIMS_Event_Bucket_Assignment_Repository::STATUS_STAGED, $assignment_repo->saved[0]['data']['assignment_status'] );
	}

	public function testTransitionAssignmentStatusPersistsValidStatusWithoutLedgerSideEffects(): void {
		$repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public array $saved = array();

			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 10,
					'physical_bucket_id' => 200,
					'assignment_status'   => 'assigned',
					'is_active'          => 1,
				);
			}

			public function save( array $data, int $assignment_id = 0 ): int {
				$this->saved[] = array(
					'data'          => $data,
					'assignment_id' => $assignment_id,
				);

				return $assignment_id;
			}
		};

		$service = new AIMS_Event_Bucket_Assignment_Service( $repo );
		$result  = $service->transition_assignment_status( 88, \AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT );

		$this->assertTrue( $result );
		$this->assertCount( 1, $repo->saved );
		$this->assertSame( \AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT, $repo->saved[0]['data']['assignment_status'] );
	}

	public function testTransitionAssignmentStatusNormalizesInvalidStatusToAssigned(): void {
		$repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public array $saved = array();

			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 10,
					'physical_bucket_id' => 200,
					'assignment_status'   => 'assigned',
					'is_active'          => 1,
				);
			}

			public function save( array $data, int $assignment_id = 0 ): int {
				$this->saved[] = array(
					'data'          => $data,
					'assignment_id' => $assignment_id,
				);

				return $assignment_id;
			}
		};

		$service = new AIMS_Event_Bucket_Assignment_Service( $repo );
		$result  = $service->transition_assignment_status( 88, 'not-a-real-status' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $repo->saved );
		$this->assertSame( \AIMS_Event_Bucket_Assignment_Repository::STATUS_ASSIGNED, $repo->saved[0]['data']['assignment_status'] );
	}

	public function testVendorEventCheckinWritesPhysicalMovementLedger(): void {
		$movements = new class() {
			public array $created = array();

			public function has_reference_application( string $reference_type, string $reference_id, int $product_id, int $bucket_id, string $movement_type ): bool {
				return false;
			}

			public function create( array $data ): int {
				$this->created[] = $data;

				return 321;
			}

			public function get_balance_for_bucket_product( int $bucket_id, int $vendor_id, int $product_id ): float {
				return 9.5;
			}
		};

		$positions = new class() {
			public array $updated = array();

			public function upsert_position( array $data ): void {
				$this->updated[] = $data;
			}
		};

		$service = new AIMS_Bucket_Movement_Service( $movements, $positions );
		$result  = $service->record_movement(
			array(
				'bucket_id'      => 200,
				'vendor_id'      => 5,
				'product_id'     => 901,
				'reference_type' => 'vendor_event_checkin',
				'reference_id'   => 'CHK-100',
				'movement_type'  => 'allocate_to_event_prepack',
				'quantity_delta' => 3.0,
				'position_status' => 'active',
			)
		);

		$this->assertSame( 321, $result['movement_id'] );
		$this->assertSame( 9.5, $result['current_quantity'] );
		$this->assertCount( 1, $movements->created );
		$this->assertSame( 'allocate_to_event_prepack', $movements->created[0]['movement_type'] );
		$this->assertCount( 1, $positions->updated );
		$this->assertSame( 321, $positions->updated[0]['last_bucket_movement_id'] );
	}

	public function testEventReturnWritesPhysicalReturnLedger(): void {
		$movements = new class() {
			public array $created = array();

			public function has_reference_application( string $reference_type, string $reference_id, int $product_id, int $bucket_id, string $movement_type ): bool {
				return false;
			}

			public function create( array $data ): int {
				$this->created[] = $data;

				return 654;
			}

			public function get_balance_for_bucket_product( int $bucket_id, int $vendor_id, int $product_id ): float {
				return 6.0;
			}
		};

		$positions = new class() {
			public array $updated = array();

			public function upsert_position( array $data ): void {
				$this->updated[] = $data;
			}
		};

		$service = new AIMS_Bucket_Movement_Service( $movements, $positions );
		$result  = $service->record_event_return(
			array(
				'bucket_id'      => 200,
				'vendor_id'      => 5,
				'product_id'     => 901,
				'reference_type' => 'vendor_event_return',
				'reference_id'   => 'RET-100',
				'quantity_delta' => 2.0,
				'position_status' => 'active',
			)
		);

		$this->assertSame( 654, $result['movement_id'] );
		$this->assertSame( 6.0, $result['current_quantity'] );
		$this->assertCount( 1, $movements->created );
		$this->assertSame( 'return_from_event', $movements->created[0]['movement_type'] );
		$this->assertCount( 1, $positions->updated );
		$this->assertSame( 654, $positions->updated[0]['last_bucket_movement_id'] );
	}

	public function testVendorEventCheckinTriggersLedgerOnlyForPrimaryVendor(): void {
		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public array $transitions = array();

			public function __construct() {}

			public function transition_assignment_status( int $assignment_id, string $status, array $data = array() ): bool {
				$this->transitions[] = compact( 'assignment_id', 'status', 'data' );
				return true;
			}
		};

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 10,
					'physical_bucket_id' => 200,
					'assignment_status'  => 'staged',
					'is_active'          => 1,
				);
			}
		};

		$bucket_positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function get_for_bucket( int $bucket_id ): array {
				return array(
					array(
						'product_id' => 901,
						'vendor_id'  => 5,
						'quantity'   => 3.0,
					),
				);
			}
		};

		$bucket_movement_service = new class() extends \AIMS_Bucket_Movement_Service {
			public array $calls = array();

			public function __construct() {}

			public function record_event_load_out( array $data ) {
				$this->calls[] = $data;

				return array(
					'movement_id'      => 321,
					'current_quantity' => 7.0,
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function get_primary_for_event( int $event_id ): ?array {
				return array(
					'vendor_id' => 5,
				);
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function find( int $bucket_id ): ?array {
				return array(
					'id'        => $bucket_id,
					'vendor_id' => 5,
				);
			}
		};

		$execution = new \AIMS_Event_Execution_Service(
			$assignment_service,
			$assignment_repository,
			$bucket_positions,
			$bucket_movement_service,
			$vendor_event_assignments,
			$physical_buckets
		);

		$result = $execution->vendor_event_checkin(
			array(
				'assignment_id' => 400,
				'reference_id'  => 'CHK-400',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['movement_triggered'] );
		$this->assertSame( 1, $result['movements_applied'] );
		$this->assertCount( 1, $bucket_movement_service->calls );
		$this->assertCount( 1, $assignment_service->transitions );
		$this->assertSame( \AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT, $assignment_service->transitions[0]['status'] );
	}

	public function testVendorEventCheckinSkipsLedgerForNonPrimaryVendor(): void {
		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public array $transitions = array();

			public function __construct() {}

			public function transition_assignment_status( int $assignment_id, string $status, array $data = array() ): bool {
				$this->transitions[] = compact( 'assignment_id', 'status', 'data' );
				return true;
			}
		};

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 10,
					'physical_bucket_id' => 200,
					'assignment_status'  => 'staged',
					'is_active'          => 1,
				);
			}
		};

		$bucket_positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function get_for_bucket( int $bucket_id ): array {
				return array(
					array(
						'product_id' => 901,
						'vendor_id'  => 5,
						'quantity'   => 3.0,
					),
				);
			}
		};

		$bucket_movement_service = new class() extends \AIMS_Bucket_Movement_Service {
			public array $calls = array();

			public function __construct() {}

			public function record_event_load_out( array $data ) {
				$this->calls[] = $data;

				return array(
					'movement_id'      => 321,
					'current_quantity' => 7.0,
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function get_primary_for_event( int $event_id ): ?array {
				return array(
					'vendor_id' => 99,
				);
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function find( int $bucket_id ): ?array {
				return array(
					'id'        => $bucket_id,
					'vendor_id' => 5,
				);
			}
		};

		$execution = new \AIMS_Event_Execution_Service(
			$assignment_service,
			$assignment_repository,
			$bucket_positions,
			$bucket_movement_service,
			$vendor_event_assignments,
			$physical_buckets
		);

		$result = $execution->vendor_event_checkin(
			array(
				'assignment_id' => 400,
				'reference_id'  => 'CHK-400',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['movement_triggered'] );
		$this->assertSame( 0, $result['movements_applied'] );
		$this->assertCount( 0, $bucket_movement_service->calls );
		$this->assertCount( 1, $assignment_service->transitions );
		$this->assertSame( \AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT, $assignment_service->transitions[0]['status'] );
	}
}
