<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

/**
 * Tests for event planning actions service using responsibility-based authorization (RBAC).
	 * This product is preproduction RBAC-only and no longer supports hybrid authorization.
 */
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

	public function testAssignBucketUsesResponsibilityModelWhenAssignmentsExist(): void {
		TestState::set_current_user_id( 77 );

		$assignment_service = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public int $assign_calls = 0;

			public function __construct() {}

			public function assign_bucket_to_event( array $data ): int {
				++$this->assign_calls;
				return 901;
			}
		};

		$access_service = new class() {
			public function can_access_event_planning( int $user_id ): bool {
				return true;
			}

			public function get_authorized_event_ids( int $user_id ): array {
				return array( 10 );
			}
		};

		$responsibility_auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function has_any_assignments_for_user( int $user_id = 0 ): bool {
				return 77 === $user_id;
			}

			public function can_manage_event_planning( int $user_id = 0 ): bool {
				return 77 === $user_id;
			}

			public function can_mutate_event( int $user_id, int $event_id ): bool {
				return false;
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			$assignment_service,
			$access_service,
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {},
			null,
			$responsibility_auth
		);

		$result = $service->assign_bucket(
			array(
				'event_id'           => 10,
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
		$this->assertSame( 'A valid assignment is required to release planning inventory.', $result['message'] );
		$this->assertSame( 0, $assignment_service->release_calls );
	}

	public function testMarkReturnedRejectsUnauthorizedEventBeforeExecutionMutation(): void {
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
					'event_id'           => 20,
					'physical_bucket_id' => 300,
					'assignment_status'  => 'at_event',
					'is_active'          => 1,
				) : null;
			}
		};

		$execution_service = new class() extends \AIMS_Event_Execution_Service {
			public int $calls = 0;

			public function __construct() {}

			public function event_return( array $data ): array {
				++$this->calls;
				return array( 'success' => true );
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
				'event_id'      => 20,
				'assignment_id' => 400,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'You are not authorized to mark this event returned.', $result['message'] );
		$this->assertSame( 0, $execution_service->calls );
	}

	public function testMarkInTransitRequiresExplicitSealCheck(): void {
		TestState::set_current_user_id( 77 );

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 10,
					'physical_bucket_id' => 300,
					'assignment_status'  => 'staged',
					'is_active'          => 1,
				);
			}
		};

		$responsibility_auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_manage_event_planning( int $user_id = 0 ): bool {
				return 77 === $user_id;
			}

			public function can_mutate_event( int $user_id, int $event_id ): bool {
				return 77 === $user_id && 10 === $event_id;
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			new class() extends \AIMS_Event_Bucket_Assignment_Service {
				public function __construct() {}
			},
			null,
			$assignment_repository,
			null,
			$responsibility_auth
		);

		$result = $service->mark_in_transit(
			array(
				'event_id'      => 10,
				'assignment_id' => 400,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'A specific sealed check is required before temporary release.', $result['message'] );
	}

	public function testMarkInTransitUpdatesSealProjectionAndStatus(): void {
		TestState::set_current_user_id( 77 );

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
					'physical_bucket_id' => 300,
					'assignment_status'  => 'staged',
					'is_active'          => 1,
				);
			}
		};

		$execution_service = new class() extends \AIMS_Event_Execution_Service {
			public array $seal_updates = array();

			public function __construct() {}

			public function update_bucket_sealed_state( int $bucket_id, bool $is_sealed ): bool {
				$this->seal_updates[] = compact( 'bucket_id', 'is_sealed' );
				return true;
			}
		};

		$responsibility_auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_manage_event_planning( int $user_id = 0 ): bool {
				return 77 === $user_id;
			}

			public function can_mutate_event( int $user_id, int $event_id ): bool {
				return 77 === $user_id && 10 === $event_id;
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			$assignment_service,
			null,
			$assignment_repository,
			$execution_service,
			$responsibility_auth
		);

		$result = $service->mark_in_transit(
			array(
				'event_id'      => 10,
				'assignment_id' => 400,
				'sealed_state'  => '1',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['sealed_state'] );
		$this->assertCount( 1, $assignment_service->transitions );
		$this->assertSame( \AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT, $assignment_service->transitions[0]['status'] );
		$this->assertCount( 1, $execution_service->seal_updates );
		$this->assertSame( 300, $execution_service->seal_updates[0]['bucket_id'] );
		$this->assertTrue( $execution_service->seal_updates[0]['is_sealed'] );
	}

	public function testVendorEventCheckInForwardsExplicitSealState(): void {
		TestState::set_current_user_id( 77 );

		$assignment_repository = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 10,
					'physical_bucket_id' => 300,
					'assignment_status'  => 'in_transit',
					'is_active'          => 1,
				);
			}
		};

		$execution_service = new class() extends \AIMS_Event_Execution_Service {
			public array $calls = array();

			public function __construct() {}

			public function vendor_event_checkin( array $data ): array {
				$this->calls[] = $data;
				return array( 'success' => true, 'message' => 'ok' );
			}
		};

		$responsibility_auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_manage_event_planning( int $user_id = 0 ): bool {
				return 77 === $user_id;
			}

			public function can_mutate_event( int $user_id, int $event_id ): bool {
				return 77 === $user_id && 10 === $event_id;
			}
		};

		$service = new \AIMS_Event_Planning_Action_Service(
			new class() extends \AIMS_Event_Bucket_Assignment_Service { public function __construct() {} },
			null,
			$assignment_repository,
			$execution_service,
			$responsibility_auth
		);

		$result = $service->vendor_event_check_in(
			array(
				'event_id'      => 10,
				'assignment_id' => 400,
				'sealed_state'  => '0',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $execution_service->calls );
		$this->assertFalse( $execution_service->calls[0]['sealed_state'] );
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

	public function testHandleAssignBucketCallsExpectedNonceVerification(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_throw_on_redirect( true );

		$service = new class() extends \AIMS_Event_Planning_Action_Service {
			public function __construct() {}

			public function can_current_user_manage_planning(): bool {
				return true;
			}

			public function assign_bucket( array $request ): array {
				return array(
					'success'  => true,
					'message'  => 'Bucket assigned to event planning.',
					'event_id' => (int) ( $request['event_id'] ?? 0 ),
				);
			}
		};

		$handler = new \AIMS_Event_Planning_Actions( $service );

		$_POST = array(
			'event_id'   => 10,
			'bucket_id'  => 300,
			'return_url' => admin_url( 'admin.php?page=aims-event-planning-workspace' ),
		);

		try {
			$handler->handle_assign_bucket();
			$this->fail( 'Expected redirect exception.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringStartsWith( 'redirect:', $exception->getMessage() );
		}

		$nonce_calls = TestState::get_hook_calls( 'check_admin_referer' );
		$this->assertCount( 1, $nonce_calls );
		$this->assertSame( 'aims_event_planning_assign_bucket', $nonce_calls[0]['args']['action'] );
		$this->assertSame( '_aims_event_planning_assign_nonce', $nonce_calls[0]['args']['query_arg'] );
	}

	public function testHandleBulkAssignCallsExpectedNonceVerification(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_throw_on_redirect( true );

		$service = new class() extends \AIMS_Event_Planning_Action_Service {
			public function __construct() {}

			public function can_current_user_manage_planning(): bool {
				return true;
			}

			public function assign_buckets_bulk( array $request ): array {
				return array(
					'success'  => true,
					'message'  => 'Assigned 1 bucket(s).',
					'event_id' => (int) ( $request['event_id'] ?? 0 ),
				);
			}
		};

		$handler = new \AIMS_Event_Planning_Actions( $service );

		$_POST = array(
			'event_id'             => 10,
			'physical_bucket_ids'  => array( 300 ),
			'return_url'           => admin_url( 'admin.php?page=aims-event-planning-workspace' ),
		);

		try {
			$handler->handle_bulk_assign_buckets();
			$this->fail( 'Expected redirect exception.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringStartsWith( 'redirect:', $exception->getMessage() );
		}

		$nonce_calls = TestState::get_hook_calls( 'check_admin_referer' );
		$this->assertCount( 1, $nonce_calls );
		$this->assertSame( 'aims_event_planning_bulk_assign_buckets', $nonce_calls[0]['args']['action'] );
		$this->assertSame( '_aims_event_planning_bulk_assign_nonce', $nonce_calls[0]['args']['query_arg'] );
	}
}
