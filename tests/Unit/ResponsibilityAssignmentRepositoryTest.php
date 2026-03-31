<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Responsibility_Assignment_Repository;

final class ResponsibilityAssignmentRepositoryTest extends \AIMS\Tests\TestCase {
	public function testGetUserIdsForResponsibilityReturnsMatchingUsers(): void {
		$this->wpdb()->queue_col( array( 21, 22, 22, '23' ) );

		$repo = new AIMS_Responsibility_Assignment_Repository();
		$user_ids = $repo->get_user_ids_for_responsibility(
			'AIMS_TEST_RESPONSIBILITY',
			AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR,
			501
		);

		$this->assertSame( array( 21, 22, 22, 23 ), $user_ids );
		$this->assertStringContainsString( 'SELECT DISTINCT user_id FROM', $this->wpdb()->last_query );
		$this->assertStringContainsString( 'responsibility_key = aims_test_responsibility', $this->wpdb()->last_query );
		$this->assertSame( 'aims_test_responsibility', $this->wpdb()->last_prepare_args[0] );
		$this->assertSame( 'vendor', $this->wpdb()->last_prepare_args[1] );
		$this->assertSame( 501, $this->wpdb()->last_prepare_args[2] );
	}

	public function testGetUserIdsForResponsibilityReturnsEmptyArrayForBlankResponsibility(): void {
		$repo = new AIMS_Responsibility_Assignment_Repository();

		$this->assertSame( array(), $repo->get_user_ids_for_responsibility( '' ) );
	}
}
