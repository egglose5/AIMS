<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Repository;
use AIMS_Source_Of_Truth;

final class EventRepositoryTest extends \AIMS\Tests\TestCase {
	public function testSaveSetsSourceToAimsWhenNotProvided(): void {
		$this->wpdb()->reset();
		$this->wpdb()->prefix = '';

		$event_repository = new AIMS_Event_Repository();
		$event_id = $event_repository->save(
			array(
				'event_name' => 'Test Event',
				'event_code' => 'test-event',
				'start_date' => '2026-05-01',
				'end_date' => '2026-05-02',
				'location_name' => 'Test Hall',
				'square_location_id' => 'LOC-100',
			)
		);

		$this->assertSame( 1, $event_id );
		$this->assertCount( 1, $this->wpdb()->inserted );
		$insert = $this->wpdb()->inserted[0];
		$this->assertSame( 'aims_events', $insert['table'] );
		$this->assertSame( AIMS_Source_Of_Truth::AIMS, $insert['data']['source'] );
	}

	public function testSavePreservesExplicitSource(): void {
		$this->wpdb()->reset();
		$this->wpdb()->prefix = '';

		$event_repository = new AIMS_Event_Repository();
		$event_id = $event_repository->save(
			array(
				'event_name' => 'Woo Event',
				'event_code' => 'woo-event',
				'start_date' => '2026-06-01',
				'end_date' => '2026-06-02',
				'location_name' => 'Marketplace',
				'square_location_id' => 'LOC-200',
				'source' => AIMS_Source_Of_Truth::WOO,
			)
		);

		$this->assertSame( 1, $event_id );
		$this->assertCount( 1, $this->wpdb()->inserted );
		$insert = $this->wpdb()->inserted[0];
		$this->assertSame( AIMS_Source_Of_Truth::WOO, $insert['data']['source'] );
	}
}
