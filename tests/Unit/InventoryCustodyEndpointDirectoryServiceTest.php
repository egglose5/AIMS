<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class InventoryCustodyEndpointDirectoryServiceTest extends \AIMS\Tests\TestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb()->reset();
	}

	public function testRuntimeEndPointsPreferPersistedCustodyGraphData(): void {
		$this->registerRuntimeRoleFromTemplate(
			'aims_test_inventory_vendor_user',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
			),
			'Inventory Vendor Runtime User'
		);

		TestState::set_current_user_id( 61 );
		TestState::set_user(
			61,
			(object) array(
				'ID'           => 61,
				'display_name' => 'Vendor User',
				'roles'        => array( 'aims_test_inventory_vendor_user' ),
			)
		);

		$endpoint_repo = new class() extends \AIMS_Inventory_Custody_Endpoint_Repository {
			public array $last_node_ref = array();

			public function __construct() {}

			public function get_active_for_node( string $node_ref_type, int $node_ref_id ): array {
				$this->last_node_ref = array(
					'node_ref_type' => $node_ref_type,
					'node_ref_id'   => $node_ref_id,
				);

				return array(
					array(
						'id'             => 401,
						'endpoint_key'   => 'vendor-dock',
						'endpoint_name'  => 'Vendor Dock',
						'endpoint_type'  => 'vendor',
						'endpoint_status'=> 'active',
						'node_ref_type'  => 'vendor',
						'node_ref_id'    => 61,
						'default_route_policy' => 'guidance',
						'allows_direct_collection' => 1,
						'allows_direct_recovery' => 1,
					),
					array(
						'id'             => 402,
						'endpoint_key'   => 'vendor-receipt',
						'endpoint_name'  => 'Vendor Receipt',
						'endpoint_type'  => 'vendor',
						'endpoint_status'=> 'active',
						'node_ref_type'  => 'vendor',
						'node_ref_id'    => 61,
						'default_route_policy' => 'guidance',
						'allows_direct_collection' => 1,
						'allows_direct_recovery' => 1,
					),
				);
			}
		};

		$route_guidance = new class() extends \AIMS_Inventory_Custody_Route_Guidance_Service {
			public function __construct() {}

			public function get_route_guidance_for_node( string $node_ref_type, int $node_ref_id ): array {
				return array(
					'success' => true,
					'node_ref_type' => $node_ref_type,
					'node_ref_id' => $node_ref_id,
					'guidance' => array(
						array(
							'endpoint' => array(
								'endpoint_key' => 'vendor-dock',
								'endpoint_label' => 'Vendor Dock',
							),
							'routes' => array(
								array(
									'relationship' => array(
										'guidance_label' => 'Dock-to-dock',
										'guidance_notes' => 'Use the dock-to-dock custody route.',
									),
									'target_endpoint' => array(
										'endpoint_key' => 'vendor-receipt',
										'endpoint_label' => 'Vendor Receipt',
									),
								),
							),
						),
					),
				);
			}
		};

		$directory = new \AIMS_Inventory_Custody_Endpoint_Directory_Service( $endpoint_repo, $route_guidance );

		$runtime = $directory->get_runtime_endpoints( 61 );
		$suggestions = $directory->get_route_suggestions( 61 );
		$resolved = $directory->resolve_runtime_endpoint( 61, 'vendor-dock' );
		$label = $directory->get_suggested_route_label( 61 );
		$note = $directory->get_suggested_route_note( 61 );

		$this->assertArrayHasKey( 'vendor-dock', $runtime );
		$this->assertArrayHasKey( 'vendor-receipt', $runtime );
		$this->assertSame( 61, (int) $endpoint_repo->last_node_ref['node_ref_id'] );
		$this->assertSame( 'vendor', $endpoint_repo->last_node_ref['node_ref_type'] );
		$this->assertSame( 'vendor-dock', $resolved['endpoint_key'] );
		$this->assertCount( 1, $suggestions );
		$this->assertSame( 'vendor-dock', $suggestions[0]['source_endpoint_key'] );
		$this->assertSame( 'vendor-receipt', $suggestions[0]['target_endpoint_key'] );
		$this->assertSame( 'Dock-to-dock', $label );
		$this->assertStringContainsString( 'dock-to-dock custody route', $note );
	}
}
