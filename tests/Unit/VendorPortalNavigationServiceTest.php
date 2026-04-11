<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Vendor_Service;
use AIMS_Vendor_Event_Assignment_Repository;
use AIMS_Event_Repository;
use AIMS_Vendor_Portal_Navigation_Service;
use AIMS\Tests\Support\TestState;

final class VendorPortalNavigationServiceTest extends \AIMS\Tests\TestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->registerRuntimeRoleFromTemplate(
			'aims_test_vendor_portal_user',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
				\AIMS_Capabilities::CAP_RESP_VENDOR_SUBMIT_CHECKIN => true,
			),
			'Test Vendor Portal User'
		);
	}

	public function testNavModelEmptyWhenNotLoggedIn(): void {
		TestState::set_current_user_id( 0 );

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array();
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service( $vendor_service );
		$model   = $service->get_nav_model();

		$this->assertFalse( $model['logged_in'] );
		$this->assertEmpty( $model['assigned_vendors'] );
		$this->assertEmpty( $model['authorized_events'] );
	}

	public function testNavModelShowsAssignedVendors(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_current_time( '2026-03-25 08:00:00' );
		TestState::set_user(
			42,
			(object) array(
				'ID'          => 42,
				'roles'       => array( 'aims_test_vendor_portal_user' ),
				'user_email'  => 'vendor1@example.com',
				'display_name' => 'Vendor One',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'             => 42,
						'vendor_name'         => 'Vendor One',
						'vendor_code'         => 'V1',
						'status'              => 'active',
					),
					array(
						'user_id'             => 99,
						'vendor_name'         => 'Vendor Two',
						'vendor_code'         => 'V2',
						'status'              => 'active',
					),
				);
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service( $vendor_service );
		$model   = $service->get_nav_model();

		$this->assertTrue( $model['logged_in'] );
		$this->assertCount( 1, $model['assigned_vendors'] );
		$this->assertSame( 'Vendor One', $model['assigned_vendors'][0]['vendor_name'] );
	}

	public function testNavModelSupportsCapabilityOnlyExternalVendorRole(): void {
		TestState::add_role(
			'site_vendor_portal_partner',
			'Site Vendor Portal Partner',
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
				\AIMS_Capabilities::CAP_RESP_VENDOR_SUBMIT_CHECKIN => true,
			)
		);

		TestState::set_current_user_id( 43 );
		TestState::set_current_time( '2026-03-25 08:00:00' );
		TestState::set_user(
			43,
			(object) array(
				'ID'           => 43,
				'roles'        => array( 'site_vendor_portal_partner' ),
				'user_email'   => 'vendor43@example.com',
				'display_name' => 'Vendor Forty Three',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'     => 43,
						'vendor_name' => 'Vendor Forty Three',
						'vendor_code' => 'V43',
						'status'      => 'active',
					),
				);
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service( $vendor_service );
		$model   = $service->get_nav_model();

		$this->assertTrue( $model['logged_in'] );
		$this->assertCount( 1, $model['assigned_vendors'] );
		$this->assertSame( 'Vendor Forty Three', $model['assigned_vendors'][0]['vendor_name'] );
	}

	public function testNavModelHidesPortalForNonVendorPerson(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_user(
			42,
			(object) array(
				'ID'          => 42,
				'roles'       => array( 'customer' ),
				'user_email'  => 'vendor1@example.com',
				'display_name' => 'Customer User',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'       => 42,
						'vendor_name'   => 'Vendor One',
						'status'        => 'active',
					),
				);
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service( $vendor_service );
		$model   = $service->get_nav_model();

		$this->assertTrue( $model['logged_in'] );
		$this->assertEmpty( $model['assigned_vendors'] );
		$this->assertEmpty( $model['authorized_events'] );
	}

	public function testNavModelShowsAuthorizedEvents(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_current_time( '2026-03-26 10:00:00' );
		TestState::set_user(
			42,
			(object) array(
				'ID'         => 42,
				'roles'      => array( 'aims_test_vendor_portal_user' ),
				'user_email' => 'vendor1@example.com',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'       => 42,
						'vendor_name'   => 'Vendor One',
					),
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function __construct() {}

			public function get_for_vendor( int $vendor_id ): array {
				if ( 42 === $vendor_id ) {
					return array(
						array(
							'id'       => 101,
							'event_id' => 10,
							'vendor_id' => 1,
						),
					);
				}

				return array();
			}
		};

		$events_repository = new class() extends \AIMS_Event_Repository {
			public function find( int $event_id ): ?array {
				if ( 10 === $event_id ) {
					return array(
						'id'                 => 10,
						'event_name'         => 'Spring Show',
						'event_start_date'   => '2026-03-28 10:00:00',
						'location_name'      => 'Convention Center',
					);
				}

				return null;
			}
		};

		$auth_service = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_submit_vendor_checkin( int $user_id = 0 ): bool {
				return 42 === $user_id;
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service(
			$vendor_service,
			$vendor_event_assignments,
			$events_repository,
			$auth_service
		);
		$model   = $service->get_nav_model();

		$this->assertCount( 1, $model['authorized_events'] );
		$this->assertSame( 10, $model['authorized_events'][0]['event_id'] );
		$this->assertSame( 'Spring Show', $model['authorized_events'][0]['event_name'] );
		$this->assertTrue( $model['authorized_events'][0]['can_checkin'] );
		$this->assertTrue( $model['authorized_events'][0]['is_upcoming'] );
		$this->assertFalse( $model['authorized_events'][0]['is_past'] );
	}

	public function testNavModelHidesPastEvents(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_current_time( '2026-03-29 12:00:00' );
		TestState::set_user(
			42,
			(object) array(
				'ID'         => 42,
				'roles'      => array( 'aims_test_vendor_portal_user' ),
				'user_email' => 'vendor1@example.com',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'       => 42,
						'vendor_name'   => 'Vendor One',
					),
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function __construct() {}

			public function get_for_vendor( int $vendor_id ): array {
				if ( 42 !== $vendor_id ) {
					return array();
				}

				return array(
					array(
						'id'        => 101,
						'event_id'  => 10,
						'vendor_id' => 1,
					),
				);
			}
		};

		$events_repository = new class() extends \AIMS_Event_Repository {
			public function find( int $event_id ): ?array {
				return array(
					'id'               => 10,
					'event_name'       => 'Spring Show',
					'event_start_date' => '2026-03-28 10:00:00',
					'location_name'    => 'Convention Center',
				);
			}
		};

		$auth_service = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_submit_vendor_checkin( int $user_id = 0 ): bool {
				return 42 === $user_id;
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service(
			$vendor_service,
			$vendor_event_assignments,
			$events_repository,
			$auth_service
		);
		$model   = $service->get_nav_model();

		$this->assertCount( 1, $model['authorized_events'] );
		$this->assertFalse( $model['authorized_events'][0]['can_checkin'] );
		$this->assertFalse( $model['authorized_events'][0]['is_upcoming'] );
		$this->assertTrue( $model['authorized_events'][0]['is_past'] );
	}

	public function testNavModelOnlyEnablesCheckInInPreEventWindow(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_current_time( '2026-03-26 10:00:00' );
		TestState::set_user(
			42,
			(object) array(
				'ID'         => 42,
				'roles'      => array( 'aims_test_vendor_portal_user' ),
				'user_email' => 'vendor1@example.com',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'       => 42,
						'vendor_name'   => 'Vendor One',
					),
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function __construct() {}

			public function get_for_vendor( int $vendor_id ): array {
				if ( 42 !== $vendor_id ) {
					return array();
				}

				return array(
					array(
						'id'        => 101,
						'event_id'  => 10,
						'vendor_id' => 1,
					),
				);
			}
		};

		$events_repository = new class() extends \AIMS_Event_Repository {
			public function find( int $event_id ): ?array {
				return array(
					'id'               => 10,
					'event_name'       => 'Spring Show',
					'event_start_date' => '2026-03-28 10:00:00',
					'location_name'    => 'Convention Center',
				);
			}
		};

		$auth_service = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_submit_vendor_checkin( int $user_id = 0 ): bool {
				return 42 === $user_id;
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service(
			$vendor_service,
			$vendor_event_assignments,
			$events_repository,
			$auth_service
		);
		$model   = $service->get_nav_model();

		// This is safely within the three-day vendor check-in window.
		$this->assertCount( 1, $model['authorized_events'] );
		$this->assertTrue( $model['authorized_events'][0]['can_checkin'] );
	}

	public function testNavModelShowsJoinableUpcomingPublicEvents(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_current_time( '2026-03-26 10:00:00' );
		TestState::set_user(
			42,
			(object) array(
				'ID'         => 42,
				'roles'      => array( 'aims_test_vendor_portal_user' ),
				'user_email' => 'vendor1@example.com',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'     => 42,
						'vendor_name' => 'Vendor One',
					),
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function __construct() {}

			public function get_for_vendor( int $vendor_id ): array {
				if ( 42 !== $vendor_id ) {
					return array();
				}

				return array(
					array(
						'id'        => 101,
						'event_id'  => 10,
						'vendor_id' => 42,
					),
				);
			}
		};

		$public_event_catalog = new class() extends \AIMS_Public_Event_Catalog_Repository {
			public function get_public_events( array $filters = array() ): array {
				return array(
					array(
						'event_id'         => 10,
						'event_name'       => 'Already Joined Show',
						'start_date'       => '2026-03-28 10:00:00',
						'end_date'         => '2026-03-28 16:00:00',
						'location_name'    => 'Convention Center',
						'public_summary'   => 'Assigned already',
					),
					array(
						'event_id'         => 20,
						'event_name'       => 'Summer Show',
						'start_date'       => '2026-04-10 10:00:00',
						'end_date'         => '2026-04-12 18:00:00',
						'location_name'    => 'Expo Hall',
						'public_summary'   => 'Joinable event',
					),
					array(
						'event_id'         => 30,
						'event_name'       => 'Past Show',
						'start_date'       => '2026-03-01 10:00:00',
						'end_date'         => '2026-03-01 16:00:00',
						'location_name'    => 'Old Venue',
						'public_summary'   => 'Past event',
					),
				);
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service(
			$vendor_service,
			$vendor_event_assignments,
			null,
			null,
			null,
			$public_event_catalog
		);
		$model   = $service->get_nav_model();

		$this->assertArrayHasKey( 'available_events', $model );
		$this->assertCount( 1, $model['available_events'] );
		$this->assertSame( 20, $model['available_events'][0]['event_id'] );
		$this->assertSame( 'Summer Show', $model['available_events'][0]['event_name'] );
		$this->assertTrue( $model['available_events'][0]['can_join'] );
	}

	public function testJoinShowCreatesAssignmentForUpcomingEvent(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_current_time( '2026-03-26 10:00:00' );
		TestState::set_user(
			42,
			(object) array(
				'ID'         => 42,
				'roles'      => array( 'aims_test_vendor_portal_user' ),
				'user_email' => 'vendor1@example.com',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'     => 42,
						'vendor_name' => 'Vendor One',
					),
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public array $saved_payloads = array();

			public function __construct() {}

			public function get_for_vendor( int $vendor_id ): array {
				return array();
			}

			public function save( array $data, int $assignment_id = 0 ): int {
				$this->saved_payloads[] = $data;
				return 555;
			}
		};

		$public_event_catalog = new class() extends \AIMS_Public_Event_Catalog_Repository {
			public function get_public_events( array $filters = array() ): array {
				return array(
					array(
						'event_id'       => 20,
						'event_name'     => 'Summer Show',
						'start_date'     => '2026-04-10 10:00:00',
						'end_date'       => '2026-04-12 18:00:00',
						'location_name'  => 'Expo Hall',
						'public_summary' => 'Joinable event',
					),
				);
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service(
			$vendor_service,
			$vendor_event_assignments,
			null,
			null,
			null,
			$public_event_catalog
		);

		$result = $service->join_show(
			array(
				'event_id'  => 20,
				'vendor_id' => 42,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 555, $result['assignment_id'] );
		$this->assertCount( 1, $vendor_event_assignments->saved_payloads );
		$this->assertSame( 20, $vendor_event_assignments->saved_payloads[0]['event_id'] );
		$this->assertSame( 42, $vendor_event_assignments->saved_payloads[0]['vendor_id'] );
	}

	public function testJoinShowRejectsAlreadyAssignedEvent(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_current_time( '2026-03-26 10:00:00' );
		TestState::set_user(
			42,
			(object) array(
				'ID'         => 42,
				'roles'      => array( 'aims_test_vendor_portal_user' ),
				'user_email' => 'vendor1@example.com',
			)
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public function __construct() {}

			public function list_vendors( string $status = '' ): array {
				return array(
					array(
						'user_id'     => 42,
						'vendor_name' => 'Vendor One',
					),
				);
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public int $save_calls = 0;

			public function __construct() {}

			public function get_for_vendor( int $vendor_id ): array {
				return array(
					array(
						'id'        => 101,
						'event_id'  => 20,
						'vendor_id' => 42,
					),
				);
			}

			public function save( array $data, int $assignment_id = 0 ): int {
				++$this->save_calls;
				return 0;
			}
		};

		$public_event_catalog = new class() extends \AIMS_Public_Event_Catalog_Repository {
			public function get_public_events( array $filters = array() ): array {
				return array(
					array(
						'event_id'       => 20,
						'event_name'     => 'Summer Show',
						'start_date'     => '2026-04-10 10:00:00',
						'end_date'       => '2026-04-12 18:00:00',
						'location_name'  => 'Expo Hall',
						'public_summary' => 'Joinable event',
					),
				);
			}
		};

		$service = new AIMS_Vendor_Portal_Navigation_Service(
			$vendor_service,
			$vendor_event_assignments,
			null,
			null,
			null,
			$public_event_catalog
		);

		$result = $service->join_show( array( 'event_id' => 20 ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $vendor_event_assignments->save_calls );
	}
}
