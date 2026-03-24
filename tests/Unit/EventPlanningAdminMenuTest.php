<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class EventPlanningAdminMenuTest extends \AIMS\Tests\TestCase {
	public function testRegisterAddsThePlanningWorkspaceUnderTheEventsMenu(): void {
		$menu = new \AIMS_Admin_Menu();
		$menu->register();

		$submenu_calls = array_values(
			array_filter(
				TestState::get_hook_calls( 'add_submenu_page' ),
				static function ( array $call ): bool {
					return 'aims-events' === (string) ( $call['args'][0] ?? '' );
				}
			)
		);

		$this->assertNotEmpty( $submenu_calls );

		$planning_call = null;
		$workspace_call = null;

		foreach ( $submenu_calls as $call ) {
			if ( 'Event Planning' === (string) ( $call['args'][1] ?? '' ) ) {
				$planning_call = $call;
			}

			if ( 'Event Planning Workspace' === (string) ( $call['args'][1] ?? '' ) ) {
				$workspace_call = $call;
			}
		}

		$this->assertNotNull( $planning_call );
		$this->assertSame( 'aims-event-planning', $planning_call['args'][4] );
		$this->assertSame( array( $menu, 'render_event_planning' ), $planning_call['args'][5] );

		$this->assertNotNull( $workspace_call );
		$this->assertSame( \AIMS_Event_Planning_Workspace_Page::PAGE_SLUG, $workspace_call['args'][4] );
		$this->assertSame( array( $menu, 'render_event_planning_workspace' ), $workspace_call['args'][5] );

		$remove_calls = TestState::get_hook_calls( 'remove_submenu_page' );
		$this->assertNotEmpty( $remove_calls );
		$this->assertSame( 'aims-events', $remove_calls[0]['args'][0] );
		$this->assertSame( \AIMS_Event_Planning_Workspace_Page::PAGE_SLUG, $remove_calls[0]['args'][1] );
	}
}
