<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Public_Event_Catalog_Repository;
use AIMS_Public_Event_Projection_Service;

final class PublicEventUpdateProjectionTest extends \AIMS\Tests\TestCase {
	public function testSavePublicEventUpdateProjectsSafeFieldsOnly(): void {
		$events = new class() extends \AIMS_Event_Repository {
			public function find( int $event_id ): ?array {
				if ( 10 !== $event_id ) {
					return null;
				}

				return array(
					'id'                => 10,
					'event_code'        => 'HARVEST',
					'event_name'        => 'Harvest Fest',
					'location_name'     => 'Main Hall',
					'start_date'        => '2026-10-01',
					'end_date'          => '2026-10-03',
				);
			}

			public function all(): array {
				return array( $this->find( 10 ) );
			}
		};

		$updates = new class() extends \AIMS_Public_Event_Update_Repository {
			public array $saved = array();

			public function save( array $data, int $update_id = 0 ): int {
				$this->saved[] = array(
					'data'       => $data,
					'update_id'  => $update_id,
				);

				return 77;
			}
		};

		$service = new AIMS_Public_Event_Projection_Service( $events, $updates );
		$result  = $service->save_public_event_update(
			array(
				'event_id'             => 10,
				'update_slug'          => 'doors-open',
				'update_type'          => 'announcement',
				'update_title'         => 'Doors Open',
				'update_summary'       => 'Public summary',
				'update_body'          => 'Public body',
				'public_status'        => 'published',
				'is_pinned'            => 1,
				'published_at'         => '2026-03-24 18:00:00',
				'source_label'         => 'Event Team',
				'source_reference'     => 'public-feed-1',
				'assignment_id'        => 500,
				'physical_bucket_id'   => 200,
				'reference_type'       => 'vendor_event_checkin',
				'reference_id'         => 'CHK-100',
				'movement_type'        => 'event_load_out',
				'checkin_team_member'  => 15,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 77, $result['update_id'] );
		$this->assertCount( 1, $updates->saved );
		$this->assertSame( 10, $updates->saved[0]['data']['event_id'] );
		$this->assertSame( 'doors-open', $updates->saved[0]['data']['update_slug'] );
		$this->assertSame( 'announcement', $updates->saved[0]['data']['update_type'] );
		$this->assertSame( 'Doors Open', $updates->saved[0]['data']['update_title'] );
		$this->assertSame( 'Public summary', $updates->saved[0]['data']['update_summary'] );
		$this->assertSame( 'Public body', $updates->saved[0]['data']['update_body'] );
		$this->assertSame( 'published', $updates->saved[0]['data']['public_status'] );
		$this->assertSame( 1, $updates->saved[0]['data']['is_pinned'] );
		$this->assertArrayNotHasKey( 'assignment_id', $updates->saved[0]['data'] );
		$this->assertArrayNotHasKey( 'physical_bucket_id', $updates->saved[0]['data'] );
		$this->assertArrayNotHasKey( 'movement_type', $updates->saved[0]['data'] );
		$this->assertArrayNotHasKey( 'checkin_team_member', $updates->saved[0]['data'] );
	}

	public function testUpdatesShortcodeRendersProjectedPublicUpdatesFromFeed(): void {
		$catalog = new class() extends AIMS_Public_Event_Catalog_Repository {
			public function find_public_event( int $event_id = 0, string $slug = '' ): ?array {
				if ( 10 !== $event_id ) {
					return null;
				}

				return array(
					'event_id'               => 10,
					'event_code'             => 'HARVEST',
					'event_slug'             => 'harvest-fest',
					'event_name'             => 'Harvest Fest',
					'status'                 => 'published',
					'public_status'          => 'published',
					'start_date'             => '2026-10-01',
					'end_date'               => '2026-10-03',
					'location_name'          => 'Main Hall',
					'public_summary'         => '',
					'hero_image_reference'   => '',
					'is_featured'            => 1,
					'request_intake_enabled' => 0,
					'date_range_label'       => 'October 1, 2026 - October 3, 2026',
				);
			}
		};

		$projection = new class() extends AIMS_Public_Event_Projection_Service {
			public function __construct() {}

			public function get_public_event_updates( int $event_id, array $args = array() ): array {
				return array(
					array(
						'update_id'            => 1,
						'event_id'             => 10,
						'update_slug'          => 'doors-open',
						'update_type_label'    => 'Announcement',
						'update_title'         => 'Doors Open',
						'update_summary'       => 'Public note for guests.',
						'update_body'          => 'Doors open at 6:00 PM.',
						'public_status'        => 'published',
						'is_pinned'            => 1,
						'published_at_label'   => 'March 24, 2026 6:00 PM',
						'source_label'         => 'Event Team',
					),
				);
			}
		};

		$controller = new \AIMS_Event_Public_Projection_Controller( $catalog, $projection );
		$html       = $controller->render_updates_shortcode(
			array(
				'event_id'         => 10,
				'title'            => 'Latest Updates',
			)
		);

		$this->assertStringContainsString( 'Latest Updates', $html );
		$this->assertStringContainsString( 'Doors Open', $html );
		$this->assertStringContainsString( 'Public note for guests.', $html );
		$this->assertStringContainsString( 'Doors open at 6:00 PM.', $html );
		$this->assertStringNotContainsString( 'vendor_event_checkin', $html );
		$this->assertStringNotContainsString( 'physical_bucket_id', $html );
	}
}
