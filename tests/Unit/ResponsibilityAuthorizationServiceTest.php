<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Responsibility_Assignment_Repository;
use AIMS_Responsibility_Authorization_Service;
use AIMS\Tests\Support\TestState;

final class ResponsibilityAuthorizationServiceTest extends \AIMS\Tests\TestCase {
	public function testSystemAdminCanViewAllEvents(): void {
		update_option( AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		$repo = new class() extends AIMS_Responsibility_Assignment_Repository {
			public function has_active_assignments_for_user( int $user_id ): bool {
				return 11 === $user_id;
			}

			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				return 11 === $user_id && AIMS_Responsibility_Authorization_Service::RESP_SYSTEM_ADMIN === $responsibility_key;
			}

			public function get_scope_ref_ids_for_user( int $user_id, string $responsibility_key, string $scope_type ): array {
				return array();
			}
		};

		$service = new AIMS_Responsibility_Authorization_Service( $repo );

		$this->assertTrue( $service->has_any_assignments_for_user( 11 ) );
		$this->assertTrue( $service->can_view_all_events( 11 ) );
		$this->assertTrue( $service->can_manage_event_planning( 11 ) );
		$this->assertTrue( $service->can_mutate_event( 11, 99 ) );
		$this->assertSame( array(), $service->get_authorized_event_ids( 11 ) );
	}

	public function testEventScopedMutateOnlyAllowsMatchingEvent(): void {
		update_option( AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		$repo = new class() extends AIMS_Responsibility_Assignment_Repository {
			public function has_active_assignments_for_user( int $user_id ): bool {
				return 22 === $user_id;
			}

			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				if ( 22 !== $user_id ) {
					return false;
				}

				if ( AIMS_Responsibility_Authorization_Service::RESP_EVENT_PLANNING_MUTATE !== $responsibility_key ) {
					return false;
				}

				if ( self::SCOPE_EVENT === $scope_type ) {
					return 45 === $scope_ref_id;
				}

				return false;
			}

			public function get_scope_ref_ids_for_user( int $user_id, string $responsibility_key, string $scope_type ): array {
				if ( 22 !== $user_id || self::SCOPE_EVENT !== $scope_type ) {
					return array();
				}

				if ( AIMS_Responsibility_Authorization_Service::RESP_EVENT_PLANNING_MUTATE === $responsibility_key ) {
					return array( 45 );
				}

				if ( AIMS_Responsibility_Authorization_Service::RESP_EVENT_PLANNING_ACCESS === $responsibility_key ) {
					return array( 44, 45 );
				}

				return array();
			}
		};

		$service = new AIMS_Responsibility_Authorization_Service( $repo );

		$this->assertTrue( $service->can_manage_event_planning( 22 ) );
		$this->assertTrue( $service->can_mutate_event( 22, 45 ) );
		$this->assertFalse( $service->can_mutate_event( 22, 44 ) );
		$this->assertSame( array( 44, 45 ), $service->get_authorized_event_ids( 22 ) );
	}

	public function testGlobalResponsibilitiesAuthorizeVendorSquareAndReportsChecks(): void {
		update_option( AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		$repo = new class() extends AIMS_Responsibility_Assignment_Repository {
			public function has_active_assignments_for_user( int $user_id ): bool {
				return 33 === $user_id;
			}

			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				if ( 33 !== $user_id ) {
					return false;
				}

				return in_array(
					$responsibility_key,
					array(
						AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT,
						AIMS_Responsibility_Authorization_Service::RESP_SQUARE_SYNC_MANAGEMENT,
						AIMS_Responsibility_Authorization_Service::RESP_SQUARE_SYNC_REPLAY,
						AIMS_Responsibility_Authorization_Service::RESP_REPORTS_VIEW,
					),
					true
				);
			}

			public function get_scope_ref_ids_for_user( int $user_id, string $responsibility_key, string $scope_type ): array {
				return array();
			}
		};

		$service = new AIMS_Responsibility_Authorization_Service( $repo );

		$this->assertTrue( $service->can_manage_vendors( 33 ) );
		$this->assertTrue( $service->can_manage_square_sync( 33 ) );
		$this->assertTrue( $service->can_run_square_sync_replay( 33 ) );
		$this->assertTrue( $service->can_view_reports( 33 ) );
		$this->assertTrue( $service->can_run_square_sync_undo( 33 ) );
	}

	public function testVendorScopedInventoryAuthorizationRequiresAimsPerson(): void {
		update_option( AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		TestState::set_user(
			70,
			(object) array(
				'ID'    => 70,
				'roles' => array( 'customer' ),
			)
		);

		$repo = new class() extends AIMS_Responsibility_Assignment_Repository {
			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				return 70 === $user_id && AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGE_INVENTORY === $responsibility_key;
			}
		};

		$service = new AIMS_Responsibility_Authorization_Service( $repo );

		$this->assertFalse( $service->can_manage_vendor_inventory_for_vendor( 70, 200 ) );
	}

	public function testVendorScopedInventoryAuthorizationAllowsAimsManagerWithScope(): void {
		update_option( AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );

		TestState::set_user(
			71,
			(object) array(
				'ID'    => 71,
				'roles' => array( 'aims_manager_user' ),
			)
		);

		$repo = new class() extends AIMS_Responsibility_Assignment_Repository {
			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				if ( 71 !== $user_id || AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGE_INVENTORY !== $responsibility_key ) {
					return false;
				}

				return self::SCOPE_VENDOR === $scope_type && 201 === $scope_ref_id;
			}
		};

		$service = new AIMS_Responsibility_Authorization_Service( $repo );

		$this->assertTrue( $service->can_manage_vendor_inventory_for_vendor( 71, 201 ) );
		$this->assertFalse( $service->can_manage_vendor_inventory_for_vendor( 71, 202 ) );
	}

	public function testCapabilityMappedResponsibilitiesAuthorizeWithoutAssignments(): void {
		TestState::set_user(
			88,
			(object) array(
				'ID'    => 88,
				'roles' => array( 'aims_custom_reports_manager' ),
			)
		);

		\AIMS_Capabilities::create_or_update_custom_role(
			'aims_custom_reports_manager',
			'Reports Manager',
			\AIMS_Capabilities::ROLE_MANAGER_USER,
			array(
				\AIMS_Capabilities::CAP_RESP_REPORTS_VIEW => true,
				\AIMS_Capabilities::CAP_RESP_VENDOR_MANAGEMENT => true,
			)
		);

		$repo = new class() extends AIMS_Responsibility_Assignment_Repository {
			public function has_active_assignments_for_user( int $user_id ): bool {
				return false;
			}

			public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
				return false;
			}
		};

		$service = new AIMS_Responsibility_Authorization_Service( $repo );

		$this->assertTrue( $service->can_view_reports( 88 ) );
		$this->assertTrue( $service->can_manage_vendors( 88 ) );
	}
}
