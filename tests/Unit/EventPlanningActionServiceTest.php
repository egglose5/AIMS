<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class EventPlanningActionServiceTest extends \AIMS\Tests\TestCase {
	public function testAssignBucketRejectsUnauthorizedEventBeforeMutation(): void {
		TestState::set_current_user_id( 77 );

		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public int $assign_calls = 0;

			public function __construct() {}

			public function assign_bucket_to_event( array $data ): int {
				++$this->assign_calls;
				return 900;
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

		$service = new \AIMS_Event_Planning_Action_Service(
			$assignment_service,
			$access_service,
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {}
		);

		$result = $service->assign_bucket(
			array(
				'event_id'           => 20,
				'physical_bucket_id' => 300,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'You are not authorized to assign buckets to this event.', $result['message'] );
		$this->assertSame( 0, $assignment_service->assign_calls );
	}

	public function testAssignBucketDefaultsToInTransitStatus(): void {
		TestState::set_current_user_id( 77 );

		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public array $saved = array();

			public function __construct() {}

			public function assign_bucket_to_event( array $data ): int {
				$this->saved[] = $data;
				return 900;
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

		$service = new \AIMS_Event_Planning_Action_Service(
			$assignment_service,
			$access_service,
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {}
		);

		$result = $service->assign_bucket(
			array(
				'event_id'           => 10,
				'physical_bucket_id' => 300,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'in_transit', $assignment_service->saved[0]['assignment_status'] );
	}

	public function testReleaseBucketRejectsAssignmentFromDifferentEvent(): void {
		TestState::set_current_user_id( 77 );

		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public int $release_calls = 0;

			public function __construct() {}

			public function release_bucket_from_event( int $assignment_id, array $data = array() ): bool {
				++$this->release_calls;
				return true;
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

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				if ( 400 === $assignment_id ) {
					return array(
						'id'                 => 400,
						'event_id'           => 20,
						'physical_bucket_id' => 300,
						'assignment_status'   => 'assigned',
						'is_active'          => 1,
					);
				}

				return null;
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			$assignment_service,
			$access_service,
			$assignment_repository
		);

		$result = $service->release_bucket(
			array(
				'event_id'      => 10,
				'assignment_id' => 400,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'A valid assignment is required to release planning inventory.', $result['message'] );
		$this->assertSame( 0, $assignment_service->release_calls );
	}

	public function testMarkInTransitUpdatesStatusWithoutMovement(): void {
		TestState::set_current_user_id( 77 );

		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public array $transitions = array();

			public function __construct() {}

			public function transition_assignment_status( int $assignment_id, string $status, array $data = array() ): bool {
				$this->transitions[] = compact( 'assignment_id', 'status', 'data' );
				return true;
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

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return 400 === $assignment_id ? array(
					'id'                 => 400,
					'event_id'           => 10,
					'physical_bucket_id' => 300,
					'assignment_status'  => 'assigned',
					'is_active'          => 1,
				) : null;
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			$assignment_service,
			$access_service,
			$assignment_repository
		);

		$result = $service->mark_in_transit(
			array(
				'event_id'      => 10,
				'assignment_id' => 400,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'in_transit', $assignment_service->transitions[0]['status'] );
	}

	public function testVendorEventCheckInUsesExecutionServiceAndMarksAtEvent(): void {
		TestState::set_current_user_id( 77 );

		$access_service = new class() {
			public function can_access_event_planning( int $user_id ): bool {
				return 77 === $user_id;
			}

			public function get_authorized_event_ids( int $user_id ): array {
				return 77 === $user_id ? array( 10 ) : array();
			}
		};

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return 400 === $assignment_id ? array(
					'id'                 => 400,
					'event_id'           => 10,
					'physical_bucket_id' => 300,
					'assignment_status'  => 'in_transit',
					'is_active'          => 1,
				) : null;
			}
		};

		$execution_service = new class() extends \AIMS_Event_Execution_Service {
			public array $payloads = array();

			public function __construct() {}

			public function vendor_event_checkin( array $data ): array {
				$this->payloads[] = $data;
				return array(
					'success'       => true,
					'message'       => 'Vendor event check-in recorded.',
					'assignment_id' => (int) ( $data['assignment_id'] ?? 0 ),
					'event_id'      => 10,
					'status'        => 'at_event',
				);
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			new class() extends \AIMS_Event_Bucket_Assignment_Service {
				public function __construct() {}
			},
			$access_service,
			$assignment_repository,
			$execution_service
		);

		$result = $service->vendor_event_check_in(
			array(
				'event_id'       => 10,
				'assignment_id'  => 400,
				'notes'          => 'Arrived with vendor truck',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 400, $execution_service->payloads[0]['assignment_id'] );
		$this->assertSame( 77, $execution_service->payloads[0]['applied_by'] );
		$this->assertSame( 'Arrived with vendor truck', $execution_service->payloads[0]['note'] );
	}

	public function testMarkReturnedUsesExecutionServiceAndMarksReturned(): void {
		TestState::set_current_user_id( 77 );

		$access_service = new class() {
			public function can_access_event_planning( int $user_id ): bool {
				return 77 === $user_id;
			}

			public function get_authorized_event_ids( int $user_id ): array {
				return 77 === $user_id ? array( 10 ) : array();
			}
		};

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return 400 === $assignment_id ? array(
					'id'                 => 400,
					'event_id'           => 10,
					'physical_bucket_id' => 300,
					'assignment_status'  => 'at_event',
					'is_active'          => 1,
				) : null;
			}
		};

		$execution_service = new class() extends \AIMS_Event_Execution_Service {
			public array $payloads = array();

			public function __construct() {}

			public function event_return( array $data ): array {
				$this->payloads[] = $data;
				return array(
					'success'       => true,
					'message'       => 'Event return recorded.',
					'assignment_id' => (int) ( $data['assignment_id'] ?? 0 ),
					'event_id'      => 10,
					'status'        => 'returned',
				);
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			new class() extends \AIMS_Event_Bucket_Assignment_Service {
				public function __construct() {}
			},
			$access_service,
			$assignment_repository,
			$execution_service
		);

		$result = $service->mark_returned(
			array(
				'event_id'       => 10,
				'assignment_id'  => 400,
				'notes'          => 'Returned to warehouse',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 400, $execution_service->payloads[0]['assignment_id'] );
		$this->assertSame( 77, $execution_service->payloads[0]['applied_by'] );
		$this->assertSame( 'Returned to warehouse', $execution_service->payloads[0]['note'] );
	}

	public function testReleaseAfterReturnRequiresReturnedStatusBeforeRelease(): void {
		TestState::set_current_user_id( 77 );

		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public array $releases = array();

			public function __construct() {}

			public function release_bucket_from_event( int $assignment_id, array $data = array() ): bool {
				$this->releases[] = compact( 'assignment_id', 'data' );
				return true;
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

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return 400 === $assignment_id ? array(
					'id'                 => 400,
					'event_id'           => 10,
					'physical_bucket_id' => 300,
					'assignment_status'  => 'returned',
					'is_active'          => 1,
				) : null;
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			$assignment_service,
			$access_service,
			$assignment_repository
		);

		$result = $service->release_after_return(
			array(
				'event_id'      => 10,
				'assignment_id' => 400,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $assignment_service->releases );
		$this->assertSame( 'released', $assignment_service->releases[0]['data']['assignment_status'] );
	}

	public function testHandlerAuthorizationGateUsesPlanningCapabilityCheck(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_user_capabilities( 77, array( \AIMS_Capabilities::CAP_VIEW_DASHBOARD ) );

		$service = new class() extends \AIMS_Event_Planning_Action_Service {
			public function __construct() {}
		};

		$handler = new \AIMS_Event_Planning_Actions( $service );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission to manage event planning buckets.' );

		$handler->handle_assign_bucket();
	}
}
