<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Responsibility_Assignment_Repository;
use AIMS_Responsibility_Authorization_Service;

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
}
