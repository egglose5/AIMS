<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Planning_Access_Service;
use AIMS\Tests\Support\TestState;

final class EventPlanningAccessServiceTest extends \AIMS\Tests\TestCase {
	public function testResponsibilityAuthorizationDeterminesesEventAccess(): void {
		TestState::set_current_user_id( 52 );

		$responsibility_auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function has_any_assignments_for_user( int $user_id = 0 ): bool {
				return 52 === $user_id;
			}

			public function can_manage_event_planning( int $user_id = 0 ): bool {
				return 52 === $user_id;
			}

			public function can_view_all_events( int $user_id = 0 ): bool {
				return false;
			}

			public function get_authorized_event_ids( int $user_id = 0 ): array {
				return 52 === $user_id ? array( 900, 901 ) : array();
			}
		};

		$service = new AIMS_Event_Planning_Access_Service(
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {},
			new class() extends \AIMS_Event_Repository {},
			$responsibility_auth
		);

		$this->assertTrue( $service->can_access_event_planning( 52 ) );
		$this->assertFalse( $service->can_view_all_events( 52 ) );
		$this->assertSame( array( 900, 901 ), $service->get_authorized_event_ids( 52 ) );
	}
}
