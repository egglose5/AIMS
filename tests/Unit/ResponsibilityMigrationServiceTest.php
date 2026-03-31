<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Responsibility_Authorization_Service;
use AIMS_Responsibility_Migration_Service;

final class ResponsibilityMigrationServiceTest extends \AIMS\Tests\TestCase {
	public function testLegacyVendorAccessSeedsResponsibilityRowsAndEnablesModel(): void {
		$this->wpdb()->queue_results(
			array(
				array( 'user_id' => 10, 'vendor_id' => 5, 'access_role' => 'viewer' ),
				array( 'user_id' => 11, 'vendor_id' => 8, 'access_role' => 'editor' ),
			)
		);
		$this->wpdb()->queue_results(
			array(
				array( 'supervisor_user_id' => 99, 'subordinate_user_id' => 10 ),
			)
		);

		$service = new AIMS_Responsibility_Migration_Service();
		$service->maybe_seed_from_legacy();

		$this->assertSame( AIMS_Responsibility_Migration_Service::SEED_VERSION, (string) get_option( AIMS_Responsibility_Migration_Service::OPTION_SEED_VERSION, '' ) );
		$this->assertSame( '1', (string) get_option( AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '0' ) );
		$this->assertNotEmpty( $this->wpdb()->inserted );

		$inserted_keys = array_map(
			static function ( array $row ): string {
				$data = (array) ( $row['data'] ?? array() );
				return implode(
					':',
					array(
						(int) ( $data['user_id'] ?? 0 ),
						(string) ( $data['responsibility_key'] ?? '' ),
						(string) ( $data['scope_type'] ?? '' ),
						(int) ( $data['scope_ref_id'] ?? 0 ),
					)
				);
			},
			$this->wpdb()->inserted
		);

		$this->assertContains( '10:event_planning_access:vendor:5', $inserted_keys );
		$this->assertContains( '11:event_planning_access:vendor:8', $inserted_keys );
		$this->assertContains( '11:event_planning_mutate:vendor:8', $inserted_keys );
		$this->assertContains( '99:event_planning_access:vendor:5', $inserted_keys );
	}
}
