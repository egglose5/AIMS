<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

/**
 * Tests for event planning actions service using responsibility-based authorization (RBAC).
 * 
 * Legacy tests for vendor user access and supervisor hierarchy fallbacks have been removed
 * as this product is preproduction RBAC-only and no longer supports hybrid authorization.
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
