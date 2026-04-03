<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareLocationPushPolicyServiceTest extends \AIMS\Tests\TestCase {
	public function testBlocksManifestPushWhenSquareBackedEventIsLive(): void {
		$events = new class() extends \AIMS_Event_Repository {
			public function __construct() {}

			public function all(): array {
				return array(
					array(
						'id'                 => 12,
						'event_name'         => 'PAX East',
						'start_date'         => '2026-04-02',
						'end_date'           => '2026-04-04',
						'square_location_id' => 'LOC-12',
					),
					array(
						'id'                 => 13,
						'event_name'         => 'Future Show',
						'start_date'         => '2026-05-01',
						'end_date'           => '2026-05-02',
						'square_location_id' => 'LOC-99',
					),
				);
			}
		};

		$service = new \AIMS_Square_Location_Push_Policy_Service(
			$events,
			static function (): string {
				return '2026-04-03 10:00:00';
			}
		);

		$gate = $service->get_manifest_sync_gate();

		$this->assertFalse( $gate['allowed'] );
		$this->assertCount( 1, $gate['active_events'] );
		$this->assertSame( 'PAX East', $gate['active_events'][0]['event_name'] );
	}

	public function testAllowsManifestPushOutsideLiveEventWindows(): void {
		$events = new class() extends \AIMS_Event_Repository {
			public function __construct() {}

			public function all(): array {
				return array(
					array(
						'id'                 => 25,
						'event_name'         => 'PAX East',
						'start_date'         => '2026-04-02',
						'end_date'           => '2026-04-04',
						'square_location_id' => 'LOC-12',
					),
				);
			}
		};

		$service = new \AIMS_Square_Location_Push_Policy_Service(
			$events,
			static function (): string {
				return '2026-04-10 08:00:00';
			}
		);

		$gate = $service->get_manifest_sync_gate();

		$this->assertTrue( $gate['allowed'] );
		$this->assertCount( 0, $gate['active_events'] );
	}
}
