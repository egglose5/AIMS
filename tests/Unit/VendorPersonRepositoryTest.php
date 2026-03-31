<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class VendorPersonRepositoryTest extends \AIMS\Tests\TestCase {
	public function testAllReturnsVendorUsersFromMetadataService(): void {
		TestState::set_user(
			301,
			(object) array(
				'ID'            => 301,
				'display_name'  => 'Vendor Alpha',
				'user_email'    => 'alpha@example.com',
				'user_registered' => '2026-03-01 10:00:00',
			)
		);
		TestState::set_user(
			302,
			(object) array(
				'ID'            => 302,
				'display_name'  => 'Vendor Beta',
				'user_email'    => 'beta@example.com',
				'user_registered' => '2026-03-02 10:00:00',
			)
		);

		$metadata_service = new class() extends \AIMS_Vendor_User_Metadata_Service {
			public function __construct() {}

			public function get_all_vendors( string $status = '' ): array {
				unset( $status );

				return array( 301, 302 );
			}

			public function is_vendor( int $user_id ): bool {
				return in_array( $user_id, array( 301, 302 ), true );
			}

			public function get_vendor_code( int $user_id ): string {
				return 301 === $user_id ? 'VEN-301' : 'VEN-302';
			}

			public function get_vendor_name( int $user_id ): string {
				return 301 === $user_id ? 'Vendor Alpha' : 'Vendor Beta';
			}

			public function get_commission_rate( int $user_id ): float {
				return 301 === $user_id ? 0.14 : 0.17;
			}

			public function get_square_location_id( int $user_id ): string {
				return 301 === $user_id ? 'LOC-301' : 'LOC-302';
			}

			public function get_vendor_status( int $user_id ): string {
				return 301 === $user_id ? 'active' : 'inactive';
			}

			public function get_vendor_meta( int $user_id, string $meta_key, $default = null ) {
				$values = array(
					301 => array(
						\AIMS_Vendor_Metadata::META_PHONE_NUMBER => '555-0301',
						\AIMS_Vendor_Metadata::META_EMAIL_ADDRESS => 'alpha@example.com',
						\AIMS_Vendor_Metadata::META_VENDOR_NOTES => 'Alpha note',
					),
					302 => array(
						\AIMS_Vendor_Metadata::META_PHONE_NUMBER => '555-0302',
						\AIMS_Vendor_Metadata::META_EMAIL_ADDRESS => 'beta@example.com',
						\AIMS_Vendor_Metadata::META_VENDOR_NOTES => 'Beta note',
					),
				);

				return $values[ $user_id ][ $meta_key ] ?? $default;
			}
		};

		$repo    = new \AIMS_Vendor_Person_Repository( $metadata_service );
		$vendors = $repo->all();

		$this->assertCount( 2, $vendors );
		$this->assertSame( 301, (int) $vendors[0]['user_id'] );
		$this->assertSame( 'Vendor Alpha', $vendors[0]['vendor_name'] );
		$this->assertSame( 'alpha@example.com', $vendors[0]['email_address'] );
		$this->assertSame( 302, (int) $vendors[1]['user_id'] );
		$this->assertSame( 'Vendor Beta', $vendors[1]['vendor_name'] );
	}

	public function testGetUsersWithVendorResponsibilityDelegatesToAssignmentRepository(): void {
		$assignment_repo = new class() extends \AIMS_Responsibility_Assignment_Repository {
			public array $calls = array();

			public function __construct() {}

			public function get_user_ids_for_responsibility( string $responsibility_key, string $scope_type = self::SCOPE_GLOBAL, int $scope_ref_id = 0 ): array {
				$this->calls[] = compact( 'responsibility_key', 'scope_type', 'scope_ref_id' );

				if ( \AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT !== $responsibility_key ) {
					return array();
				}

				return array( 11, 12 );
			}
		};

		$repo = new \AIMS_Vendor_Person_Repository(
			new \AIMS_Vendor_User_Metadata_Service(),
			$assignment_repo
		);

		$user_ids = $repo->get_users_with_vendor_responsibility(
			\AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT
		);

		$this->assertSame( array( 11, 12 ), $user_ids );
		$this->assertCount( 1, $assignment_repo->calls );
		$this->assertSame( \AIMS_Responsibility_Authorization_Service::RESP_VENDOR_MANAGEMENT, $assignment_repo->calls[0]['responsibility_key'] );
		$this->assertSame( \AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL, $assignment_repo->calls[0]['scope_type'] );
		$this->assertSame( 0, $assignment_repo->calls[0]['scope_ref_id'] );
	}

	public function testFindReturnsNullForNonVendorUser(): void {
		TestState::set_user(
			401,
			(object) array(
				'ID'           => 401,
				'display_name' => 'Customer User',
				'user_email'   => 'customer@example.com',
			)
		);

		$metadata_service = new class() extends \AIMS_Vendor_User_Metadata_Service {
			public function __construct() {}

			public function is_vendor( int $user_id ): bool {
				return 401 !== $user_id;
			}
		};

		$repo = new \AIMS_Vendor_Person_Repository( $metadata_service );

		$this->assertNull( $repo->find( 401 ) );
	}

	public function testSaveMarksExistingUserAsVendorAndWritesMetadata(): void {
		TestState::set_user(
			501,
			(object) array(
				'ID'           => 501,
				'display_name' => 'Vendor Save Target',
				'user_email'   => 'save@example.com',
			)
		);

		$metadata_service = new class() extends \AIMS_Vendor_User_Metadata_Service {
			public array $written_meta = array();
			public bool $marked_vendor = false;

			public function __construct() {}

			public function mark_as_vendor( int $user_id ): bool {
				$this->marked_vendor = 501 === $user_id;

				return $this->marked_vendor;
			}

			public function update_vendor_meta( int $user_id, string $meta_key, $value ): bool {
				$this->written_meta[ $meta_key ] = array(
					'user_id' => $user_id,
					'value'   => $value,
				);

				return true;
			}
		};

		$repo = new \AIMS_Vendor_Person_Repository( $metadata_service );
		$result = $repo->save(
			array(
				'vendor_code'       => 'VEN-501',
				'vendor_name'       => 'Vendor Save Target',
				'email_address'     => 'save@example.com',
				'square_location_id' => 'LOC-501',
				'status'            => 'active',
			),
			501
		);

		$this->assertSame( 501, $result );
		$this->assertTrue( $metadata_service->marked_vendor );
		$this->assertArrayHasKey( \AIMS_Vendor_Metadata::META_VENDOR_CODE, $metadata_service->written_meta );
		$this->assertArrayHasKey( \AIMS_Vendor_Metadata::META_VENDOR_NAME, $metadata_service->written_meta );
		$this->assertArrayHasKey( \AIMS_Vendor_Metadata::META_SQUARE_LOCATION_ID, $metadata_service->written_meta );
		$this->assertArrayHasKey( \AIMS_Vendor_Metadata::META_VENDOR_STATUS, $metadata_service->written_meta );
		$this->assertSame( 'VEN-501', $metadata_service->written_meta[ \AIMS_Vendor_Metadata::META_VENDOR_CODE ]['value'] );
		$this->assertSame( 'Vendor Save Target', $metadata_service->written_meta[ \AIMS_Vendor_Metadata::META_VENDOR_NAME ]['value'] );
	}
}
