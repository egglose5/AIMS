<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Bucket_Movement_Service;
use AIMS_Event_Bucket_Assignment_Service;
use AIMS_Event_Planning_Action_Service;
use AIMS\Tests\Support\TestState;

final class EventExecutionV1Test extends \AIMS\Tests\TestCase {
	public function testTransitionAssignmentStatusPersistsValidStatusWithoutLedgerSideEffects(): void {
		TestState::set_current_time( '2026-03-26 09:30:00' );

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
		$this->assertSame( '2026-03-26 09:30:00', $repo->saved[0]['data']['loaded_at'] );
		$this->assertSame( '2026-03-26 09:30:00', $repo->saved[0]['data']['in_transit_at'] );
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

	public function testVendorEventCheckinUpdatesBucketSealProjectionAndWritesSealCheckpoint(): void {
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
					'assignment_status'  => 'in_transit',
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
						'quantity'   => 2.0,
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
					'movement_id'      => 900,
					'current_quantity' => 2.0,
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function get_primary_for_event( int $event_id ): ?array {
				return array( 'vendor_id' => 5 );
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public array $updates = array();

			public function find( int $bucket_id ): ?array {
				return array(
					'id'                => $bucket_id,
					'vendor_id'         => 5,
					'square_location_id'=> 'LOC-5',
				);
			}

			public function update_sealed_state( int $bucket_id, bool $is_sealed ): bool {
				$this->updates[] = compact( 'bucket_id', 'is_sealed' );
				return true;
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
				'sealed_state'  => false,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'LOC-5', $result['square_location_id'] );
		$this->assertSame( 0, $result['sealed_state'] );
		$this->assertCount( 1, $bucket_movement_service->calls );
		$this->assertSame( 'LOC-5', $bucket_movement_service->calls[0]['square_location_id'] );
		$this->assertSame( 0, $bucket_movement_service->calls[0]['sealed_state'] );
		$this->assertCount( 1, $physical_buckets->updates );
		$this->assertFalse( $physical_buckets->updates[0]['is_sealed'] );
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
					'id'                => $bucket_id,
					'vendor_id'         => 5,
					'square_location_id'=> 'LOC-5',
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
		$this->assertSame( 'LOC-5', $result['square_location_id'] );
		$this->assertSame( 1, $result['movements_applied'] );
		$this->assertCount( 1, $bucket_movement_service->calls );
		$this->assertSame( 'LOC-5', $bucket_movement_service->calls[0]['square_location_id'] );
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
					'id'                => $bucket_id,
					'vendor_id'         => 5,
					'square_location_id'=> 'LOC-5',
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
		$this->assertSame( 'LOC-5', $result['square_location_id'] );
		$this->assertSame( 0, $result['movements_applied'] );
		$this->assertCount( 0, $bucket_movement_service->calls );
		$this->assertCount( 1, $assignment_service->transitions );
		$this->assertSame( \AIMS_Event_Bucket_Assignment_Repository::STATUS_AT_EVENT, $assignment_service->transitions[0]['status'] );
	}

	public function testVendorEventCheckinMirrorsPhysicalExecutionIntoHeadlessCore(): void {
		TestState::set_product(
			901,
			new class() {
				public function get_sku(): string {
					return 'SKU-901';
				}
			}
		);

		TestState::set_remote_response(
			array(
				'code' => 201,
				'body' => wp_json_encode(
					array(
						'ok'   => true,
						'move' => array( 'movement_uuid' => 'mv-remote' ),
					)
				),
			)
		);

		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public function __construct() {}

			public function transition_assignment_status( int $assignment_id, string $status, array $data = array() ): bool {
				return true;
			}
		};

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 77,
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
			public function __construct() {}

			public function record_event_load_out( array $data ) {
				return array(
					'movement_id'      => 321,
					'current_quantity' => 7.0,
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function get_primary_for_event( int $event_id ): ?array {
				return array( 'vendor_id' => 5 );
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function find_with_context( int $bucket_id ): ?array {
				return array(
					'id'                   => $bucket_id,
					'vendor_id'            => 5,
					'bucket_code'          => 'BIN-77',
					'square_location_id'   => 'LOC-77',
					'current_location_code'=> 'WH-A',
					'home_location_code'   => 'WH-A',
				);
			}
		};

		$execution = new \AIMS_Event_Execution_Service(
			$assignment_service,
			$assignment_repository,
			$bucket_positions,
			$bucket_movement_service,
			$vendor_event_assignments,
			$physical_buckets,
			new \AIMS_Headless_Execution_Mirror_Service(
				new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' )
			)
		);

		$result = $execution->vendor_event_checkin(
			array(
				'assignment_id' => 400,
				'reference_id'  => 'CHK-400',
			)
		);

		$requests = TestState::get_remote_requests();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['headless_mirror']['attempted'] );
		$this->assertSame( 1, $result['headless_mirror']['succeeded'] );
		$this->assertCount( 1, $requests );

		$payload = json_decode( (string) $requests[0]['args']['body'], true );
		$this->assertSame( 'SKU-901', $payload['sku'] );
		$this->assertSame( 'WH-A', $payload['from_location'] );
		$this->assertSame( 'event:77', $payload['to_location'] );
	}
}
