<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Vendor_User_Access_Repository;

final class VendorUserAccessRepositoryTest extends \AIMS\Tests\TestCase {
	public function testVendorAndUserLookupsReturnUniqueIds(): void {
		$this->wpdb()->queue_results(
			array(
				array( 'vendor_id' => 5 ),
				array( 'vendor_id' => 8 ),
				array( 'vendor_id' => 5 ),
			)
		);
		$this->wpdb()->queue_results(
			array(
				array( 'user_id' => 42 ),
				array( 'user_id' => 99 ),
				array( 'user_id' => 42 ),
			)
		);

		$repo = new AIMS_Vendor_User_Access_Repository();

		$this->assertSame( array( 5, 8 ), $repo->get_vendor_ids_for_user( 42 ) );
		$this->assertSame( array( 42, 99 ), $repo->get_user_ids_for_vendor( 5 ) );
	}
}
