<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class VendorSquareSyncServiceTest extends \AIMS\Tests\TestCase {
	public function testPlanVendorSyncBuildsCreatePayloadWithAssignedSquareLocation(): void {
		$vendors = new class() extends \AIMS_Vendor_Person_Repository {
			public function __construct() {}
		};

		$team_members = new class() extends \AIMS_Square_Team_Member_Repository {
			public function __construct() {}

			public function all(): array {
				return array();
			}
		};

		$service = new \AIMS_Vendor_Square_Sync_Service( $vendors, $team_members );
		$plan    = $service->plan_vendor_sync_from_record(
			array(
				'id'                    => 77,
				'vendor_code'           => 'ven-77',
				'vendor_name'           => 'Vendor South',
				'email_address'         => 'south@example.com',
				'phone_number'          => '555-7777',
				'square_location_id'    => 'LOC-77',
				'square_team_member_id' => '',
			)
		);

		$this->assertSame( 'create_required', $plan['state'] );
		$this->assertSame( 'LOC-77', $plan['vendor_context']['square_location_id'] );
		$this->assertSame(
			array(
				'assignment_type' => 'EXPLICIT_LOCATIONS',
				'location_ids'    => array( 'LOC-77' ),
			),
			$plan['create_payload']['assigned_locations']
		);
	}
}
