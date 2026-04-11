<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class VendorModuleTest extends \AIMS\Tests\TestCase {
	protected function tearDown(): void {
		parent::tearDown();
		$_POST = array();
		TestState::set_throw_on_redirect( false );
	}

	public function testHandleVendorSaveIgnoresPostedSquareManagedIdsOnUpdate(): void {
		TestState::set_current_user_id( 44 );
		TestState::set_throw_on_redirect( true );

		$_POST = array(
			'vendor_id'              => 9001,
			'vendor_name'            => 'Vendor South',
			'vendor_code'            => 'VEN-9001',
			'square_location_id'     => 'LOC-TAMPERED',
			'square_team_member_id'  => 'TM-TAMPERED',
			'default_bucket_code'    => 'BUCKET-12',
			'commission_rate'        => '0.1250',
			'email_address'          => 'south@example.com',
			'phone_number'           => '555-9001',
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public array $updates = array();

			public function __construct() {}

			public function update_vendor( int $vendor_id, array $data ): int {
				$this->updates[] = array(
					'vendor_id' => $vendor_id,
					'data'      => $data,
				);

				return $vendor_id;
			}
		};

		$responsibility_auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_manage_vendors( int $user_id = 0 ): bool {
				return $user_id > 0;
			}
		};

		$module = new \AIMS_Vendor_Module( $vendor_service, $responsibility_auth );

		try {
			$module->handle_vendor_save();
			$this->fail( 'Expected redirect exception was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'aims_vendor_status=success', $exception->getMessage() );
			$this->assertStringContainsString( 'Vendor+updated.', $exception->getMessage() );
		}

		$this->assertCount( 1, $vendor_service->updates );
		$this->assertSame( 9001, $vendor_service->updates[0]['vendor_id'] );
		$this->assertArrayNotHasKey( 'square_location_id', $vendor_service->updates[0]['data'] );
		$this->assertArrayNotHasKey( 'square_team_member_id', $vendor_service->updates[0]['data'] );
		$this->assertSame( 'Vendor South', $vendor_service->updates[0]['data']['vendor_name'] );
	}

	public function testHandleVendorSaveRollsBackVendorWhenSquareProvisioningFails(): void {
		TestState::set_current_user_id( 44 );
		TestState::set_throw_on_redirect( true );

		$_POST = array(
			'vendor_id'     => 0,
			'vendor_name'   => 'Vendor South',
			'vendor_code'   => 'VEN-9001',
			'email_address' => 'south@example.com',
		);

		$vendor_service = new class() extends \AIMS_Vendor_Service {
			public array $deleted = array();

			public function __construct() {}

			public function create_vendor( array $data ): int {
				unset( $data );
				return 9001;
			}

			public function delete_vendor( int $vendor_id ): bool {
				$this->deleted[] = $vendor_id;
				return true;
			}
		};

		$responsibility_auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}

			public function can_manage_vendors( int $user_id = 0 ): bool {
				return $user_id > 0;
			}
		};

		$provisioning = new class() extends \AIMS_Vendor_Square_Provisioning_Service {
			public function __construct() {}

			public function provision_vendor( int $vendor_id ): array {
				unset( $vendor_id );

				return array(
					'success' => false,
					'message' => 'Square location provisioning failed.',
				);
			}
		};

		$module = new \AIMS_Vendor_Module( $vendor_service, $responsibility_auth, $provisioning );

		try {
			$module->handle_vendor_save();
			$this->fail( 'Expected redirect exception was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'aims_vendor_status=error', $exception->getMessage() );
			$this->assertStringContainsString( 'Square+location+provisioning+failed.', $exception->getMessage() );
		}

		$this->assertSame( array( 9001 ), $vendor_service->deleted );
	}
}
