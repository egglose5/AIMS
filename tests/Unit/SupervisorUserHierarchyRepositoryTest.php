<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SupervisorUserHierarchyRepositoryTest extends \AIMS\Tests\TestCase {
	public function testGetSubordinatesReturnsRecursiveTree(): void {
		$this->wpdb()->queue_results(
			array(
				array( 'subordinate_user_id' => 2 ),
				array( 'subordinate_user_id' => 3 ),
			)
		);
		$this->wpdb()->queue_results(
			array(
				array( 'subordinate_user_id' => 4 ),
			)
		);
		$this->wpdb()->queue_results( array() );
		$this->wpdb()->queue_results( array() );

		$repository = new \AIMS_Supervisor_User_Hierarchy_Repository();
		$subordinates = $repository->get_subordinates_for_supervisor( 1, 5 );

		$this->assertSame( array( 2, 3, 4 ), $subordinates );
	}

	public function testIsSubordinateOfRespectsDepthLimit(): void {
		$this->wpdb()->queue_results(
			array(
				array( 'subordinate_user_id' => 2 ),
			)
		);

		$repository = new \AIMS_Supervisor_User_Hierarchy_Repository();

		$this->assertFalse( $repository->is_subordinate_of( 4, 1, 1 ) );
	}

	public function testHasActiveRelationshipForUserReturnsTrueWhenRowExists(): void {
		$this->wpdb()->queue_results(
			array(
				array( 'id' => 99 ),
			)
		);

		$repository = new \AIMS_Supervisor_User_Hierarchy_Repository();

		$this->assertTrue( $repository->has_active_relationship_for_user( 77 ) );
	}
}
