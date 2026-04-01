<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchProducerAuthorizationServiceTest extends \AIMS\Tests\TestCase {
	public function testCanManageStitchOrdersViaCapability(): void {
		TestState::set_current_user_id( 88 );
		TestState::set_user_capabilities(
			88,
			array( \AIMS_Capabilities::CAP_MANAGE_STITCH_ORDERS )
		);

		$service = new \AIMS_Stitch_Producer_Authorization_Service(
			new class() extends \AIMS_Responsibility_Assignment_Repository {
				public function __construct() {}

				public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
					unset( $user_id, $responsibility_key, $scope_type, $scope_ref_id );
					return false;
				}
			}
		);

		$this->assertTrue( $service->can_manage_stitch_orders( 88 ) );
	}

	public function testCanManageStitchOrdersDoesNotGrantFromAssignmentOnly(): void {
		$service = new \AIMS_Stitch_Producer_Authorization_Service(
			new class() extends \AIMS_Responsibility_Assignment_Repository {
				public function __construct() {}

				public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
					unset( $scope_type, $scope_ref_id );
					return 77 === $user_id && \AIMS_Stitch_Producer_Authorization_Service::RESP_STITCH_ORDER_MANAGEMENT === $responsibility_key;
				}
			}
		);

		$this->assertFalse( $service->can_manage_stitch_orders( 77 ) );
	}

	public function testCanManageStitchOrdersReturnsFalseWithoutAccess(): void {
		$service = new \AIMS_Stitch_Producer_Authorization_Service(
			new class() extends \AIMS_Responsibility_Assignment_Repository {
				public function __construct() {}

				public function user_has_responsibility( int $user_id, string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
					unset( $user_id, $responsibility_key, $scope_type, $scope_ref_id );
					return false;
				}
			}
		);

		$this->assertFalse( $service->can_manage_stitch_orders( 99 ) );
	}
}
