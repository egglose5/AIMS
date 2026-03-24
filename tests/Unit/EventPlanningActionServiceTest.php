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
		$this->assertSame( 'The submitted assignment does not belong to the selected event.', $result['message'] );
		$this->assertSame( 0, $assignment_service->release_calls );
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
