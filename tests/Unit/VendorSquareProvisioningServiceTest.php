<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class VendorSquareProvisioningServiceTest extends \AIMS\Tests\TestCase {
	public function testProvisionVendorReplacesSharedSquareLocationWithVendorSpecificLocation(): void {
		$vendors = new class() extends \AIMS_Vendor_Service {
			public array $vendors = array(
				702 => array(
					'id'                    => 702,
					'user_id'               => 702,
					'vendor_code'           => 'ven-702',
					'vendor_name'           => 'Vendor North',
					'email_address'         => 'north@example.com',
					'phone_number'          => '555-0702',
					'square_location_id'    => 'LOC-SHARED',
					'square_team_member_id' => '',
					'status'                => 'active',
				),
			);
			public array $updates = array();

			public function __construct() {}

			public function get_vendor( int $vendor_id ): ?array {
				return $this->vendors[ $vendor_id ] ?? null;
			}

			public function get_sync_mapping_by_square_location( string $square_location_id ): ?array {
				if ( 'LOC-SHARED' !== $square_location_id ) {
					return null;
				}

				return array(
					'vendor_id'          => 701,
					'square_location_id' => 'LOC-SHARED',
				);
			}

			public function update_vendor( int $vendor_id, array $data ): int {
				$this->updates[] = array(
					'vendor_id' => $vendor_id,
					'data'      => $data,
				);
				$this->vendors[ $vendor_id ] = array_merge( $this->vendors[ $vendor_id ] ?? array(), $data );

				return $vendor_id;
			}
		};

		$sync = new class() extends \AIMS_Vendor_Square_Sync_Service {
			public function __construct() {}

			public function plan_vendor_sync_from_record( array $vendor, array $square_team_members = array() ): array {
				unset( $square_team_members );

				return array(
					'vendor_id'           => (int) $vendor['id'],
					'state'               => 'matched',
					'search_strategy'     => array(),
					'matched_team_member' => array(
						'id'                => 'TM-702',
						'display_name'      => (string) $vendor['vendor_name'],
						'email_address'     => (string) $vendor['email_address'],
						'status'            => 'ACTIVE',
					),
					'create_payload'      => array(),
					'vendor_context'      => $vendor,
					'reasons'             => array(),
				);
			}
		};

		$client = new class() extends \AIMS_Headless_Api_Client {
			public array $location_payloads = array();

			public function __construct() {}

			public function create_square_location( array $payload ): array {
				$this->location_payloads[] = $payload;

				return array(
					'success' => true,
					'json'    => array(
						'location' => array(
							'id'   => 'LOC-SQ-702',
							'name' => 'Vendor North',
						),
					),
				);
			}
		};

		$team_members = new class() extends \AIMS_Square_Team_Member_Repository {
			public function __construct() {}

			public function save( array $data, int $member_id = 0 ): int {
				unset( $data, $member_id );
				return 92;
			}
		};

		$service = new \AIMS_Vendor_Square_Provisioning_Service( $vendors, $sync, $client, $team_members );
		$result  = $service->provision_vendor( 702 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'LOC-SQ-702', $result['square_location_id'] );
		$this->assertSame( 'LOC-SQ-702', $vendors->vendors[702]['square_location_id'] );
		$this->assertCount( 1, $client->location_payloads );
	}

	public function testProvisionVendorCreatesSquareLocationAndTeamMemberAndStoresIds(): void {
		$vendors = new class() extends \AIMS_Vendor_Service {
			public array $vendors = array(
				701 => array(
					'id'                    => 701,
					'user_id'               => 701,
					'vendor_code'           => 'ven-701',
					'vendor_name'           => 'Vendor South',
					'email_address'         => 'south@example.com',
					'phone_number'          => '555-0701',
					'square_location_id'    => '',
					'square_team_member_id' => '',
					'status'                => 'active',
				),
			);
			public array $updates = array();

			public function __construct() {}

			public function get_vendor( int $vendor_id ): ?array {
				return $this->vendors[ $vendor_id ] ?? null;
			}

			public function update_vendor( int $vendor_id, array $data ): int {
				$this->updates[] = array(
					'vendor_id' => $vendor_id,
					'data'      => $data,
				);
				$this->vendors[ $vendor_id ] = array_merge( $this->vendors[ $vendor_id ] ?? array(), $data );

				return $vendor_id;
			}
		};

		$sync = new class() extends \AIMS_Vendor_Square_Sync_Service {
			public function __construct() {}

			public function plan_vendor_sync_from_record( array $vendor, array $square_team_members = array() ): array {
				unset( $square_team_members );

				return array(
					'vendor_id'           => (int) $vendor['id'],
					'state'               => 'create_required',
					'search_strategy'     => array(),
					'matched_team_member' => null,
					'create_payload'      => array(
						'display_name' => (string) $vendor['vendor_name'],
						'reference_id' => (string) $vendor['vendor_code'],
					),
					'vendor_context'      => $vendor,
					'reasons'             => array(),
				);
			}
		};

		$client = new class() extends \AIMS_Headless_Api_Client {
			public array $location_payloads = array();
			public array $team_payloads = array();

			public function __construct() {}

			public function create_square_location( array $payload ): array {
				$this->location_payloads[] = $payload;

				return array(
					'success' => true,
					'json'    => array(
						'location' => array(
							'id'   => 'LOC-SQ-701',
							'name' => 'Vendor South',
						),
					),
				);
			}

			public function create_square_team_member( array $payload ): array {
				$this->team_payloads[] = $payload;

				return array(
					'success' => true,
					'json'    => array(
						'team_member' => array(
							'id'            => 'TM-701',
							'display_name'  => 'Vendor South',
							'email_address' => 'south@example.com',
							'status'        => 'ACTIVE',
						),
					),
				);
			}
		};

		$team_members = new class() extends \AIMS_Square_Team_Member_Repository {
			public array $saved = array();

			public function __construct() {}

			public function save( array $data, int $member_id = 0 ): int {
				unset( $member_id );
				$this->saved[] = $data;
				return 91;
			}
		};

		$service = new \AIMS_Vendor_Square_Provisioning_Service( $vendors, $sync, $client, $team_members );
		$result  = $service->provision_vendor( 701 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'LOC-SQ-701', $vendors->vendors[701]['square_location_id'] );
		$this->assertSame( 'TM-701', $vendors->vendors[701]['square_team_member_id'] );
		$this->assertCount( 1, $client->location_payloads );
		$this->assertCount( 1, $client->team_payloads );
		$this->assertCount( 1, $team_members->saved );
	}
}
