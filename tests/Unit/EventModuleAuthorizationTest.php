<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class EventModuleAuthorizationTest extends \AIMS\Tests\TestCase {
	public function testHandleEventSaveDeniesUserWithoutManageCapability(): void {
		TestState::set_current_user_id( 77 );

		$_POST = array(
			'event_id'   => 10,
			'event_name' => 'Unauthorized Edit',
			'start_date' => '2026-03-01',
			'end_date'   => '2026-03-02',
			'status'     => 'draft',
		);

		$module = new \AIMS_Event_Module();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission to manage events.' );

		$module->handle_event_save();
	}

	public function testHandleEventSaveCallsNonceCheckBeforeRedirectValidationError(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_user_capabilities( 77, array( \AIMS_Capabilities::CAP_MANAGE_EVENTS ) );
		TestState::set_throw_on_redirect( true );

		$_POST = array(
			'event_id'   => 10,
			'event_name' => '',
			'start_date' => '2026-03-01',
			'end_date'   => '2026-03-02',
			'status'     => 'draft',
		);

		$module = new \AIMS_Event_Module(
			new class() {
				public function can_view_all_events( int $user_id ): bool {
					return true;
				}

				public function get_authorized_event_ids( int $user_id ): array {
					return array();
				}
			}
		);

		try {
			$module->handle_event_save();
			$this->fail( 'Expected redirect exception.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringStartsWith( 'redirect:', $exception->getMessage() );
		}

		$nonce_calls = TestState::get_hook_calls( 'check_admin_referer' );
		$this->assertCount( 1, $nonce_calls );
		$this->assertSame( 'aims_event_save', $nonce_calls[0]['args']['action'] );
		$this->assertSame( '_wpnonce', $nonce_calls[0]['args']['query_arg'] );
	}

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
