<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Planning_Access_Service;
use AIMS\Tests\Support\TestState;

final class EventPlanningAccessServiceTest extends \AIMS\Tests\TestCase {
	public function testAdminCanViewAllAuthorizedEvents(): void {
		TestState::set_user(
			1,
			(object) array(
				'ID'    => 1,
				'roles' => array( 'administrator' ),
			)
		);

		$events = new class() extends \AIMS_Event_Repository {
			public function all(): array {
				return array(
					array( 'id' => 10, 'event_name' => 'Spring Show', 'start_date' => '2026-04-01' ),
					array( 'id' => 20, 'event_name' => 'Summer Show', 'start_date' => '2026-06-01' ),
				);
			}
		};

		$service = new AIMS_Event_Planning_Access_Service(
			new class() extends \AIMS_Vendor_User_Access_Repository {},
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {},
			$events
		);

		$this->assertTrue( $service->can_access_event_planning( 1 ) );
		$this->assertTrue( $service->can_view_all_events( 1 ) );
		$this->assertSame( array( 10, 20 ), $service->get_authorized_event_ids( 1 ) );
		$this->assertCount( 2, $service->get_authorized_events( 1 ) );
	}

	public function testManagerSeesOnlyEventsForAuthorizedVendors(): void {
		TestState::set_user(
			42,
			(object) array(
				'ID'    => 42,
				'roles' => array( 'aims_manager_user' ),
			)
		);

		$vendor_access = new class() extends \AIMS_Vendor_User_Access_Repository {
			public function get_vendor_ids_for_user( int $user_id ): array {
				return 42 === $user_id ? array( 5 ) : array();
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {};
		$events = new class() extends \AIMS_Event_Repository {};
		$hierarchy = new class() extends \AIMS_Supervisor_User_Hierarchy_Repository {
			public function has_active_relationship_for_user( int $user_id ): bool {
				return 42 === $user_id;
			}

			public function get_subordinates_for_supervisor( int $supervisor_user_id, int $max_depth = 5 ): array {
				return array();
			}
		};

		$this->wpdb()->queue_results(
			array(
				array( 'event_id' => 10 ),
				array( 'event_id' => 20 ),
			)
		);
		$this->wpdb()->queue_results(
			array(
				array( 'event_id' => 10 ),
				array( 'event_id' => 20 ),
			)
		);
		$this->wpdb()->queue_results(
			array(
				array(
					'id'                 => 10,
					'event_name'         => 'Spring Show',
					'start_date'         => '2026-04-01',
					'end_date'           => '2026-04-03',
					'location_name'      => 'Main Hall',
					'square_location_id' => 'LOC-1',
					'status'             => 'published',
				),
				array(
					'id'                 => 20,
					'event_name'         => 'Summer Show',
					'start_date'         => '2026-06-01',
					'end_date'           => '2026-06-02',
					'location_name'      => 'Second Hall',
					'square_location_id' => 'LOC-2',
					'status'             => 'published',
				),
			)
		);

		$service = new AIMS_Event_Planning_Access_Service(
			$vendor_access,
			$vendor_event_assignments,
			$events,
			$hierarchy
		);

		$this->assertTrue( $service->can_access_event_planning( 42 ) );
		$this->assertFalse( $service->can_view_all_events( 42 ) );
		$this->assertSame( array( 5 ), $service->get_authorized_vendor_ids( 42 ) );
		$this->assertSame( array( 10, 20 ), $service->get_authorized_event_ids( 42 ) );
		$authorized_events = $service->get_authorized_events( 42 );
		$this->assertCount( 2, $authorized_events );
		$this->assertSame( 'Spring Show', $authorized_events[0]['event_name'] );
	}

	public function testManagerFailsClosedWhenHierarchyMappingMissing(): void {
		TestState::set_user(
			42,
			(object) array(
				'ID'    => 42,
				'roles' => array( 'aims_manager_user' ),
			)
		);

		$vendor_access = new class() extends \AIMS_Vendor_User_Access_Repository {
			public function get_vendor_ids_for_user( int $user_id ): array {
				return array( 5 );
			}
		};

		$hierarchy = new class() extends \AIMS_Supervisor_User_Hierarchy_Repository {
			public function has_active_relationship_for_user( int $user_id ): bool {
				return false;
			}
		};

		$service = new AIMS_Event_Planning_Access_Service(
			$vendor_access,
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {},
			new class() extends \AIMS_Event_Repository {},
			$hierarchy
		);

		$this->assertSame( array(), $service->get_team_user_ids( 42 ) );
		$this->assertSame( array(), $service->get_authorized_vendor_ids( 42 ) );
		$this->assertSame( array(), $service->get_authorized_event_ids( 42 ) );
	}

	public function testResponsibilityAssignmentsOverrideLegacyVendorScope(): void {
		TestState::set_user(
			52,
			(object) array(
				'ID'    => 52,
				'roles' => array( 'aims_manager_user' ),
			)
		);

		$vendor_access = new class() extends \AIMS_Vendor_User_Access_Repository {
			public function get_vendor_ids_for_user( int $user_id ): array {
				return array( 5 );
			}
		};

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
			$vendor_access,
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {},
			new class() extends \AIMS_Event_Repository {},
			new class() extends \AIMS_Supervisor_User_Hierarchy_Repository {},
			$responsibility_auth
		);

		$this->assertTrue( $service->can_access_event_planning( 52 ) );
		$this->assertSame( array( 900, 901 ), $service->get_authorized_event_ids( 52 ) );
	}
}
