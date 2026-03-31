<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class VendorPersonRepositoryTest extends \AIMS\Tests\TestCase {
	public function testVendorPersonRepositoryExistsAndLoadable(): void {
		$this->assertTrue( class_exists( 'AIMS_Vendor_Person_Repository' ) );
	}

	public function testVendorMetadataConstantsAreAccessible(): void {
		$this->assertTrue( defined( 'AIMS_Vendor_Metadata::META_VENDOR_CODE' ) || class_exists( 'AIMS_Vendor_Metadata' ) );
		$this->assertTrue( class_exists( 'AIMS_Vendor_Metadata' ) );

		$meta_keys = \AIMS_Vendor_Metadata::get_all_meta_keys();
		$this->assertIsArray( $meta_keys );
		$this->assertGreaterThan( 10, count( $meta_keys ) );
	}

	public function testVendorMetadataSchemaIsComplete(): void {
		$schema = \AIMS_Vendor_Metadata::get_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'vendor_code', $schema );
		$this->assertArrayHasKey( 'vendor_name', $schema );
		$this->assertArrayHasKey( 'commission_rate', $schema );
		$this->assertArrayHasKey( 'address', $schema );
	}

	public function testVendorUserMetadataServiceExistsAndInstantiable(): void {
		$service = new \AIMS_Vendor_User_Metadata_Service();
		$this->assertIsObject( $service );
	}

	public function testVendorPersonRepositoryInstantiatesWithMetadataService(): void {
		$metadata_service = new \AIMS_Vendor_User_Metadata_Service();
		$repo = new \AIMS_Vendor_Person_Repository( $metadata_service );
		$this->assertIsObject( $repo );
	}

	public function testVendorMetadataDefinesDefaultValues(): void {
		$this->assertFalse( \AIMS_Vendor_Metadata::get_default( \AIMS_Vendor_Metadata::META_IS_VENDOR ) );
		$this->assertSame( 0.0, \AIMS_Vendor_Metadata::get_default( \AIMS_Vendor_Metadata::META_COMMISSION_RATE ) );
		$this->assertSame( 'US', \AIMS_Vendor_Metadata::get_default( \AIMS_Vendor_Metadata::META_COUNTRY_CODE ) );
		$this->assertSame( 'active', \AIMS_Vendor_Metadata::get_default( \AIMS_Vendor_Metadata::META_VENDOR_STATUS ) );
	}

	public function testVendorMetadataIdentifiesNumericFields(): void {
		$this->assertTrue( \AIMS_Vendor_Metadata::should_be_numeric( \AIMS_Vendor_Metadata::META_COMMISSION_RATE ) );
		$this->assertTrue( \AIMS_Vendor_Metadata::should_be_numeric( \AIMS_Vendor_Metadata::META_DEFAULT_BUCKET_ID ) );
	}

	public function testVendorMetadataIdentifiesBooleanFields(): void {
		$this->assertTrue( \AIMS_Vendor_Metadata::should_be_boolean( \AIMS_Vendor_Metadata::META_IS_VENDOR ) );
	}

	public function testVendorUserAccessRepositoryAdapterInstantiates(): void {
		$adapter = new \AIMS_Vendor_User_Access_Repository_Adapter();
		$this->assertIsObject( $adapter );
	}

	public function testVendorResponsibilityConstantsExist(): void {
		$auth = new \AIMS_Responsibility_Authorization_Service( 
			new class() extends \AIMS_Responsibility_Assignment_Repository {
				public function __construct() {}
			}
		);

		// Verify granular vendor capabilities were added
		$reflection = new \ReflectionClass( $auth );
		$constants = $reflection->getConstants();

		$this->assertArrayHasKey( 'RESP_VENDOR_SUBMIT_CHECKIN', $constants );
		$this->assertArrayHasKey( 'RESP_VENDOR_VIEW_COMMISSION', $constants );
		$this->assertArrayHasKey( 'RESP_VENDOR_MANAGE_INVENTORY', $constants );
	}

	public function testVendorResponsibilityMethodsExist(): void {
		$auth = new \AIMS_Responsibility_Authorization_Service(
			new class() extends \AIMS_Responsibility_Assignment_Repository {
				public function __construct() {}
			}
		);

		$this->assertTrue( method_exists( $auth, 'can_submit_vendor_checkin' ) );
		$this->assertTrue( method_exists( $auth, 'can_view_vendor_commission' ) );
		$this->assertTrue( method_exists( $auth, 'can_manage_vendor_inventory' ) );
	}
}
