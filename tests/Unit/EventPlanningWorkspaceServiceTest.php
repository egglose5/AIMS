<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Bucket_Assignment_Repository;
use AIMS_Event_Bucket_Assignment_Service;
use AIMS_Event_Demand_Planning_Service;
use AIMS_Event_Planning_Workspace_Service;
use AIMS_Physical_Bucket_Repository;
use AIMS_Bucket_Inventory_Position_Repository;
use AIMS_Storage_Location_Repository;
use AIMS_Vendor_Event_Assignment_Repository;
use AIMS\Tests\Support\TestState;

final class EventPlanningWorkspaceServiceTest extends \AIMS\Tests\TestCase {
	public function testBuildPageModelScopesSelectedEventAndWorkspaceData(): void {
		TestState::set_user(
			77,
			(object) array(
				'ID' => 77,
				'display_name' => 'Manager One',
			)
		);

		TestState::set_product(
			901,
			new class() {
				public function get_sku(): string {
					return 'SKU-1';
				}

				public function get_name(): string {
					return 'Demo Product';
				}
			}
		);
		TestState::set_product(
			902,
			new class() {
				public function get_sku(): string {
					return 'SKU-2';
				}

				public function get_name(): string {
					return 'Warehouse Product';
				}
			}
		);

		$events = new class() extends \AIMS_Event_Repository {
			public function all(): array {
				return array(
					array(
						'id'                => 10,
						'event_name'        => 'Spring Show',
						'start_date'        => '2026-04-01',
						'end_date'          => '2026-04-03',
						'location_name'     => 'Main Hall',
						'square_location_id' => 'LOC-1',
						'status'            => 'published',
					),
					array(
						'id'                => 20,
						'event_name'        => 'Hidden Show',
						'start_date'        => '2026-05-01',
						'end_date'          => '2026-05-02',
						'location_name'     => 'Side Hall',
						'square_location_id' => 'LOC-2',
						'status'            => 'draft',
					),
				);
			}
		};

		$demand_planning = new AIMS_Event_Demand_Planning_Service(
			new class() {
				public function get_demand_summary_for_event( int $event_id ): array {
					return array(
						array(
							'event_id'               => $event_id,
							'woo_product_id'         => 901,
							'product_sku'            => 'SKU-1',
							'product_name'           => 'Demo Product',
							'total_quantity_requested' => '5.0000',
							'item_count'             => 2,
						),
					);
				}
			}
		);

		$bucket_assignments = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public function __construct() {}

			public function get_active_buckets_for_event( int $event_id ): array {
				return array(
					array(
						'id'                 => 400,
						'event_id'           => $event_id,
						'physical_bucket_id' => 200,
						'assignment_status'   => 'staged',
						'assignment_type'     => 'event_stock',
						'assigned_at'         => '2026-03-20 10:00:00',
						'assigned_by'         => 77,
						'is_active'          => 1,
					),
				);
			}

			public function get_active_for_bucket( int $bucket_id ): ?array {
				if ( 200 === $bucket_id ) {
					return array(
						'id'                 => 400,
						'event_id'           => 10,
						'physical_bucket_id' => 200,
						'assignment_status'   => 'staged',
						'assignment_type'     => 'event_stock',
						'assigned_at'         => '2026-03-20 10:00:00',
						'assigned_by'         => 77,
						'is_active'          => 1,
					);
				}

				if ( 301 === $bucket_id ) {
					return array(
						'id'                 => 401,
						'event_id'           => 99,
						'physical_bucket_id' => 301,
						'assignment_status'   => 'assigned',
						'assignment_type'     => 'event_stock',
						'is_active'          => 1,
					);
				}

				return null;
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function get_for_vendor( int $vendor_id ): array {
				if ( 5 !== $vendor_id ) {
					return array();
				}

				return array(
					array(
						'id'                        => 300,
						'bucket_code'               => 'B-300',
						'bucket_label'              => 'Blue Bin',
						'bucket_type'               => 'standard',
						'status'                    => 'available',
						'current_storage_location_id' => 12,
						'home_storage_location_id'  => 11,
						'vendor_id'                 => 5,
						'barcode_value'             => 'BAR300',
					),
					array(
						'id'                        => 301,
						'bucket_code'               => 'B-301',
						'bucket_label'              => 'Red Bin',
						'bucket_type'               => 'standard',
						'status'                    => 'available',
						'current_storage_location_id' => 12,
						'home_storage_location_id'  => 11,
						'vendor_id'                 => 5,
						'barcode_value'             => 'BAR301',
					),
				);
			}

			public function find( int $bucket_id ): ?array {
				$map = array(
					200 => array(
						'id'                        => 200,
						'bucket_code'               => 'B-200',
						'bucket_label'              => 'Assigned Bin',
						'bucket_type'               => 'standard',
						'status'                    => 'available',
						'current_storage_location_id' => 12,
						'home_storage_location_id'  => 11,
						'vendor_id'                 => 5,
						'barcode_value'             => 'BAR200',
					),
					300 => array(
						'id'                        => 300,
						'bucket_code'               => 'B-300',
						'bucket_label'              => 'Blue Bin',
						'bucket_type'               => 'standard',
						'status'                    => 'available',
						'current_storage_location_id' => 12,
						'home_storage_location_id'  => 11,
						'vendor_id'                 => 5,
						'barcode_value'             => 'BAR300',
					),
				);

				return $map[ $bucket_id ] ?? null;
			}
		};

		$bucket_positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public function get_for_bucket( int $bucket_id ): array {
				if ( 200 === $bucket_id ) {
					return array(
						array(
							'id'                 => 1,
							'bucket_id'          => 200,
							'vendor_id'          => 5,
							'product_id'         => 901,
							'quantity'           => '4.0000',
							'reserved_quantity'   => '1.0000',
							'position_status'     => 'active',
							'last_counted_at'     => '2026-03-20 09:00:00',
						),
					);
				}

				if ( 300 === $bucket_id ) {
					return array(
						array(
							'id'                 => 2,
							'bucket_id'          => 300,
							'vendor_id'          => 5,
							'product_id'         => 902,
							'quantity'           => '8.0000',
							'reserved_quantity'   => '2.0000',
							'position_status'     => 'active',
							'last_counted_at'     => '2026-03-20 09:30:00',
						),
					);
				}

				return array();
			}
		};

		$storage_locations = new class() extends \AIMS_Storage_Location_Repository {
			public function find( int $location_id ): ?array {
				$map = array(
					11 => array(
						'id'            => 11,
						'location_code' => 'WH-A',
						'location_name' => 'Warehouse A',
						'location_type' => 'warehouse',
						'status'        => 'active',
					),
					12 => array(
						'id'            => 12,
						'location_code' => 'STG-A',
						'location_name' => 'Staging A',
						'location_type' => 'staging',
						'status'        => 'active',
					),
				);

				return $map[ $location_id ] ?? null;
			}
		};

		$vendor_event_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function get_for_event( int $event_id ): array {
				return array(
					array(
						'event_id'  => $event_id,
						'vendor_id' => 5,
					),
				);
			}
		};

		$access_service = new class() {
			public function get_current_user_authorized_events(): array {
				return array(
					array(
						'id'                => 10,
						'event_name'        => 'Spring Show',
						'start_date'        => '2026-04-01',
						'end_date'          => '2026-04-03',
						'location_name'     => 'Main Hall',
						'square_location_id' => 'LOC-1',
						'status'            => 'published',
					),
				);
			}
		};

		$service = new \AIMS_Event_Planning_Workspace_Service(
			$events,
			$demand_planning,
			$bucket_assignments,
			$physical_buckets,
			$bucket_positions,
			$storage_locations,
			$vendor_event_assignments,
			$access_service
		);

		$model = $service->get_page_model( array( 'event_id' => 99 ) );

		$this->assertCount( 1, $model['authorized_events'] );
		$this->assertSame( 10, $model['selected_event_id'] );
		$this->assertSame( 'Spring Show', $model['selected_event']['event_name'] );
		$this->assertSame( '', $model['selection_message'] );
		$this->assertCount( 1, $model['workspace']['demand_rows'] );
		$this->assertCount( 1, $model['workspace']['assigned_buckets'] );
		$this->assertCount( 1, $model['workspace']['available_buckets'] );
		$this->assertSame( 300, $model['workspace']['available_buckets'][0]['physical_bucket_id'] );
		$this->assertSame( 'Blue Bin', $model['workspace']['available_buckets'][0]['bucket_label'] );
		$this->assertSame( 'B-300', $model['workspace']['available_buckets'][0]['bucket_code'] );
		$this->assertSame( 3.0, $model['workspace']['assigned_buckets'][0]['content_summary']['total_available_quantity'] );
		$this->assertSame( 'Staging A', $model['workspace']['available_buckets'][0]['storage']['current']['label'] );
		$this->assertSame( 1, $model['workspace']['summary']['assigned_bucket_count'] );
		$this->assertSame( 1, $model['workspace']['summary']['assigned_staged_bucket_count'] );
		$this->assertSame( 1, $model['workspace']['summary']['available_bucket_count'] );
		$this->assertSame( 3.0, $model['workspace']['summary']['assigned_available_quantity'] );
		$this->assertSame( 6.0, $model['workspace']['summary']['available_pool_quantity'] );
		$this->assertCount( 1, $model['workspace']['team_activity'] );
		$this->assertSame( 'Manager One', $model['workspace']['team_activity'][0]['display_name'] );
		$this->assertSame( 1, $model['workspace']['team_activity'][0]['assigned_count'] );
		$this->assertSame( 1, $model['workspace']['team_activity'][0]['staged_count'] );
	}

	public function testGetAuthorizedEventsUsesPlanningAccessServiceShape(): void {
		TestState::set_current_user_id( 77 );

		$events = new class() extends \AIMS_Event_Repository {
			public function all(): array {
				return array(
					array(
						'id'                 => 10,
						'event_name'         => 'Spring Show',
						'start_date'         => '2026-04-01',
						'end_date'           => '2026-04-03',
						'location_name'      => 'Main Hall',
						'square_location_id' => 'LOC-1',
						'status'             => 'published',
					),
				);
			}
		};

		$access_service = new class() {
			public int $calls = 0;
			public array $received_user_ids = array();

			public function get_authorized_events( int $user_id = 0 ): array {
				++$this->calls;
				$this->received_user_ids[] = $user_id;

				return array(
					array(
						'id'                 => 10,
						'event_name'         => 'Spring Show',
						'start_date'         => '2026-04-01',
						'end_date'           => '2026-04-03',
						'location_name'      => 'Main Hall',
						'square_location_id' => 'LOC-1',
						'status'             => 'published',
					),
				);
			}
		};

		$service = new AIMS_Event_Planning_Workspace_Service(
			$events,
			null,
			null,
			null,
			null,
			null,
			null,
			$access_service
		);

		$model = $service->get_page_model();

		$this->assertSame( 1, $access_service->calls );
		$this->assertSame( array( 77 ), $access_service->received_user_ids );
		$this->assertCount( 1, $model['authorized_events'] );
		$this->assertSame( 10, $model['selected_event_id'] );
		$this->assertSame( 'Spring Show', $model['selected_event']['event_name'] );
	}

	public function testGetPageModelFailsClosedWithoutAccessService(): void {
		$service = new AIMS_Event_Planning_Workspace_Service();
		$model   = $service->get_page_model();

		$this->assertSame( array(), $model['authorized_events'] );
		$this->assertSame( 0, $model['selected_event_id'] );
		$this->assertSame( 'No authorized events are available for planning.', $model['selection_message'] );
		$this->assertSame( array(), $model['workspace'] );
	}

	public function testGetPageModelCanFilterToTeamEvents(): void {
		TestState::set_current_user_id( 77 );

		$events = new class() extends \AIMS_Event_Repository {
			public function get_table_name(): string {
				return 'wp_aims_events';
			}
		};

		$this->wpdb()->queue_results(
			array(
				array(
					'id' => 10,
					'event_name' => 'My Event',
					'start_date' => '2026-04-01',
					'end_date' => '2026-04-03',
					'location_name' => 'Main Hall',
				),
				array(
					'id' => 20,
					'event_name' => 'Team Event',
					'start_date' => '2026-05-01',
					'end_date' => '2026-05-03',
					'location_name' => 'Team Hall',
				),
			)
		);

		$access_service = new class() {
			public function get_authorized_event_contexts( int $user_id ): array {
				return array(
					10 => array( 'event_id' => 10, 'source' => 'self' ),
					20 => array( 'event_id' => 20, 'source' => 'team' ),
				);
			}

			public function get_subordinate_user_ids( int $user_id, int $max_depth = 5 ): array {
				return array( 88 );
			}
		};

		$service = new AIMS_Event_Planning_Workspace_Service(
			$events,
			null,
			null,
			null,
			null,
			null,
			null,
			$access_service
		);

		$model = $service->get_page_model(
			array(
				'event_scope' => 'team',
			)
		);

		$this->assertCount( 1, $model['authorized_events'] );
		$this->assertSame( 20, $model['authorized_events'][0]['id'] );
		$this->assertSame( 'team', $model['authorized_events'][0]['visibility_source'] );
		$this->assertTrue( (bool) ( $model['team_context']['is_supervisor'] ?? false ) );
	}

	public function testGetPageModelCanFilterAssignedBucketsByPlanner(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_user(
			77,
			(object) array(
				'ID' => 77,
				'display_name' => 'Manager One',
			)
		);
		TestState::set_user(
			88,
			(object) array(
				'ID' => 88,
				'display_name' => 'Planner Two',
			)
		);

		$events = new class() extends \AIMS_Event_Repository {
			public function all(): array {
				return array(
					array(
						'id' => 10,
						'event_name' => 'Spring Show',
					),
				);
			}
		};

		$bucket_assignments = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public function __construct() {}

			public function get_active_buckets_for_event( int $event_id ): array {
				return array(
					array(
						'id' => 401,
						'event_id' => $event_id,
						'physical_bucket_id' => 200,
						'assignment_status' => 'staged',
						'assigned_by' => 77,
					),
					array(
						'id' => 402,
						'event_id' => $event_id,
						'physical_bucket_id' => 201,
						'assignment_status' => 'staged',
						'assigned_by' => 88,
					),
				);
			}

			public function get_active_for_bucket( int $bucket_id ): ?array {
				return null;
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function find( int $bucket_id ): ?array {
				return array(
					'id' => $bucket_id,
					'bucket_code' => 'B-' . $bucket_id,
					'bucket_label' => 'Bucket ' . $bucket_id,
					'bucket_type' => 'standard',
					'status' => 'available',
					'vendor_id' => 5,
				);
			}
		};

		$access_service = new class() {
			public function get_current_user_authorized_events(): array {
				return array(
					array(
						'id' => 10,
						'event_name' => 'Spring Show',
					),
				);
			}
		};

		$service = new AIMS_Event_Planning_Workspace_Service(
			$events,
			null,
			$bucket_assignments,
			$physical_buckets,
			null,
			null,
			null,
			$access_service
		);

		$model = $service->get_page_model(
			array(
				'event_id' => 10,
				'planner_user_id' => 88,
			)
		);

		$this->assertSame( 88, $model['filter_state']['planner_user_id'] );
		$this->assertCount( 1, $model['workspace']['assigned_buckets'] );
		$this->assertSame( 201, $model['workspace']['assigned_buckets'][0]['physical_bucket_id'] );
		$this->assertCount( 1, $model['workspace']['team_activity'] );
		$this->assertSame( 'Planner Two', $model['workspace']['team_activity'][0]['display_name'] );
		$this->assertSame( 1, $model['workspace']['summary']['assigned_bucket_count'] );
	}

	public function testWorkspaceSummaryIncludesSlaAndTimelineMetrics(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_current_time( '2026-03-25 12:00:00' );
		TestState::set_user(
			77,
			(object) array(
				'ID' => 77,
				'display_name' => 'Manager One',
			)
		);

		$events = new class() extends \AIMS_Event_Repository {
			public function all(): array {
				return array(
					array(
						'id' => 10,
						'event_name' => 'Spring Show',
					),
				);
			}
		};

		$bucket_assignments = new class() extends \AIMS_Event_Bucket_Assignment_Service {
			public function __construct() {}

			public function get_active_buckets_for_event( int $event_id ): array {
				return array(
					array(
						'id' => 501,
						'event_id' => $event_id,
						'physical_bucket_id' => 301,
						'assignment_status' => 'staged',
						'assigned_at' => '2026-03-24 08:00:00',
						'assigned_by' => 77,
					),
					array(
						'id' => 502,
						'event_id' => $event_id,
						'physical_bucket_id' => 302,
						'assignment_status' => 'in_transit',
						'assigned_at' => '2026-03-25 00:00:00',
						'loaded_at' => '2026-03-25 08:00:00',
						'in_transit_at' => '2026-03-25 08:15:00',
						'assigned_by' => 77,
					),
					array(
						'id' => 503,
						'event_id' => $event_id,
						'physical_bucket_id' => 303,
						'assignment_status' => 'at_event',
						'assigned_at' => '2026-03-24 09:00:00',
						'assigned_by' => 77,
					),
				);
			}

			public function get_active_for_bucket( int $bucket_id ): ?array {
				return null;
			}
		};

		$physical_buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function find( int $bucket_id ): ?array {
				return array(
					'id' => $bucket_id,
					'bucket_code' => 'B-' . $bucket_id,
					'bucket_label' => 'Bucket ' . $bucket_id,
					'bucket_type' => 'standard',
					'status' => 'available',
					'vendor_id' => 5,
				);
			}
		};

		$access_service = new class() {
			public function get_current_user_authorized_events(): array {
				return array(
					array(
						'id' => 10,
						'event_name' => 'Spring Show',
					),
				);
			}
		};

		$service = new AIMS_Event_Planning_Workspace_Service(
			$events,
			null,
			$bucket_assignments,
			$physical_buckets,
			null,
			null,
			null,
			$access_service
		);

		$model = $service->get_page_model( array( 'event_id' => 10 ) );

		$this->assertSame( 1, $model['workspace']['summary']['assigned_staged_bucket_count'] );
		$this->assertSame( 1, $model['workspace']['summary']['staged_over_24h_count'] );
		$this->assertSame( 2, $model['workspace']['summary']['open_over_8h_count'] );
		$this->assertCount( 3, $model['workspace']['assignment_timeline'] );
		$this->assertSame( 502, $model['workspace']['assignment_timeline'][0]['assignment_id'] );
		$this->assertSame( '2026-03-25 08:00:00', $model['workspace']['assignment_timeline'][0]['loaded_at'] );
		$this->assertSame( '2026-03-25 08:15:00', $model['workspace']['assignment_timeline'][0]['in_transit_at'] );
		$this->assertSame( '8–24h', $model['workspace']['assignment_timeline'][0]['age_band'] );
		$this->assertSame( 1, $model['workspace']['team_activity'][0]['staged_over_24h_count'] );
	}
}
