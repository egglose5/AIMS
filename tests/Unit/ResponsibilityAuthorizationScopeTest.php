<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class ResponsibilityAuthorizationScopeTest extends \AIMS\Tests\TestCase {

	public function testScopeCustodyConstantsExistOnRepository(): void {
		$this->assertSame( 'custody', \AIMS_Responsibility_Assignment_Repository::SCOPE_CUSTODY );
		$this->assertSame( 'subordinate_tree', \AIMS_Responsibility_Assignment_Repository::SCOPE_SUBORDINATE_TREE );
	}

	public function testGetAuthorizedCustodyEndpointIdsReturnsEndpointsFromAssignmentTable(): void {
		update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );
		// User 70 has a SCOPE_CUSTODY assignment for endpoint 500.
		$this->wpdb()->queue_results( array(
			array(
				'id'                 => 10,
				'user_id'            => 70,
				'responsibility_key' => 'vendor_manage_inventory',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_CUSTODY,
				'scope_ref_id'       => 500,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
		) );

		TestState::set_user_capabilities( 70, array( \AIMS_Capabilities::CAP_MANAGE_INVENTORY ) );

		$service = new \AIMS_Responsibility_Authorization_Service();
		$ids     = $service->get_authorized_custody_endpoint_ids( 70, 'vendor_manage_inventory' );

		$this->assertContains( 500, $ids, 'Endpoint 500 should be in the authorized custody endpoint list.' );
	}

	public function testUserCanAccessCustodyEndpointReturnsTrueForDirectAssignment(): void {
		update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );
		// User 71 has a SCOPE_CUSTODY assignment for node 600 (any responsibility_key).
		$this->wpdb()->queue_results( array(
			array(
				'id'                 => 11,
				'user_id'            => 71,
				'responsibility_key' => 'event_planning_access',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_CUSTODY,
				'scope_ref_id'       => 600,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
		) );

		$service = new \AIMS_Responsibility_Authorization_Service();
		$result  = $service->user_can_access_custody_endpoint( 71, 600 );

		$this->assertTrue( $result, 'User 71 should have custody access to endpoint 600 via responsibility assignment.' );
	}

	public function testUserCanAccessCustodyEndpointReturnsFalseWhenNotAssigned(): void {
		// User 72 has NO SCOPE_CUSTODY assignment at all.
		$this->wpdb()->queue_results( array() );

		$service = new \AIMS_Responsibility_Authorization_Service();
		$result  = $service->user_can_access_custody_endpoint( 72, 700 );

		$this->assertFalse( $result, 'User 72 has no custody assignment — should return false.' );
	}

	public function testGetSubordinateUserIdsForUserReturnsDataFromSubordinateTreeAssignments(): void {
		update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );
		// User 80 has two SCOPE_SUBORDINATE_TREE assignments (subordinates 201 and 202).
		$this->wpdb()->queue_results( array(
			array(
				'id'                 => 20,
				'user_id'            => 80,
				'responsibility_key' => 'event_planning_access',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_SUBORDINATE_TREE,
				'scope_ref_id'       => 201,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
			array(
				'id'                 => 21,
				'user_id'            => 80,
				'responsibility_key' => 'event_planning_mutate',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_SUBORDINATE_TREE,
				'scope_ref_id'       => 202,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
		) );

		$service = new \AIMS_Responsibility_Authorization_Service();
		$ids     = $service->get_subordinate_user_ids_for_user( 80 );

		$this->assertContains( 201, $ids );
		$this->assertContains( 202, $ids );
	}

	public function testIsSupervisorReturnsTrueWhenSubordinateTreeAssignmentsExist(): void {
		update_option( \AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1' );
		$this->wpdb()->queue_results( array(
			array(
				'id'                 => 22,
				'user_id'            => 81,
				'responsibility_key' => 'event_planning_access',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_SUBORDINATE_TREE,
				'scope_ref_id'       => 301,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
		) );

		$service = new \AIMS_Responsibility_Authorization_Service();
		$this->assertTrue( $service->is_supervisor( 81 ) );
	}

	public function testIsSupervisorReturnsFalseWhenNoSubordinateTreeAssignments(): void {
		// User 82 has a global assignment but no SCOPE_SUBORDINATE_TREE.
		$this->wpdb()->queue_results( array(
			array(
				'id'                 => 23,
				'user_id'            => 82,
				'responsibility_key' => 'event_planning_access',
				'scope_type'         => \AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL,
				'scope_ref_id'       => 0,
				'is_active'          => 1,
				'revoked_at'         => null,
			),
		) );

		$service = new \AIMS_Responsibility_Authorization_Service();
		$this->assertFalse( $service->is_supervisor( 82 ) );
	}
}
