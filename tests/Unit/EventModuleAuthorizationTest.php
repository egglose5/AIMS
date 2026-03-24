<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class EventModuleAuthorizationTest extends \AIMS\Tests\TestCase {
	public function testHandleEventSaveBlocksUnauthorizedEventEdit(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_user_capabilities( 77, array( \AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING ) );

		$access_service = new class() {
			public function can_view_all_events( int $user_id ): bool {
				return false;
			}

			public function get_authorized_event_ids( int $user_id ): array {
				return array( 10 );
			}
		};

		$_POST = array(
			'event_id'   => 20,
			'event_name' => 'Unauthorized Edit',
			'start_date' => '2026-03-01',
			'end_date'   => '2026-03-02',
			'status'     => 'draft',
		);

		$module = new \AIMS_Event_Module( $access_service );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission to edit this event.' );

		$module->handle_event_save();
	}

	public function testHandleEventArchiveBlocksUnauthorizedEventMutation(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_user_capabilities( 77, array( \AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING ) );

		$access_service = new class() {
			public function can_view_all_events( int $user_id ): bool {
				return false;
			}

			public function get_authorized_event_ids( int $user_id ): array {
				return array( 10 );
			}
		};

		$_POST = array(
			'event_id' => 20,
		);

		$module = new \AIMS_Event_Module( $access_service );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission to archive this event.' );

		$module->handle_event_archive();
	}
}
