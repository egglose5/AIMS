<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class VendorDataMigrationServiceTest extends \AIMS\Tests\TestCase {
	public function testMigrationServiceCanBeInstantiated(): void {
		$migration_service = new \AIMS_Vendor_Data_Migration_Service();

		$this->assertInstanceOf( \AIMS_Vendor_Data_Migration_Service::class, $migration_service );
	}

}
