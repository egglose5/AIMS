<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class WorkflowSurfaceRegistryTest extends \AIMS\Tests\TestCase {
	public function testDefinitionsCoverPublicWorkflowsButExcludeRoleEditor(): void {
		$definitions = \AIMS_Workflow_Surface_Registry::get_definitions();

		$this->assertArrayHasKey( 'event_catalog', $definitions );
		$this->assertArrayHasKey( 'event_demand_form', $definitions );
		$this->assertArrayHasKey( 'vendor_portal_navigation', $definitions );
		$this->assertArrayHasKey( 'vendor_event_checkin', $definitions );
		$this->assertArrayHasKey( 'stitch_portal', $definitions );
		$this->assertArrayHasKey( 'cycle_count_portal', $definitions );
		$this->assertArrayHasKey( 'wholesale_portal', $definitions );
		$this->assertArrayNotHasKey( 'role_editor', $definitions );

		foreach ( $definitions as $surface ) {
			$this->assertInstanceOf( \AIMS_Admin_Meta_Object::class, $surface );
			$this->assertNotSame( '', (string) $surface->get( 'shortcode', '' ) );
		}
	}

	public function testRegisterWiresHubShortcodeTemplateFilterAndWidgetAction(): void {
		$registry = new \AIMS_Workflow_Surface_Registry();
		$registry->register();

		$shortcode_calls = TestState::get_hook_calls( 'add_shortcode' );
		$this->assertNotEmpty( $shortcode_calls );
		$this->assertSame( 'aims_workflow_hub', $shortcode_calls[0]['args']['tag'] );

		$widget_calls = TestState::get_hook_calls( 'widgets_init' );
		$this->assertCount( 1, $widget_calls );

		$template_calls = TestState::get_hook_calls( 'theme_page_templates' );
		$this->assertCount( 1, $template_calls );
	}

	public function testRegisterWidgetsRegistersThemeFriendlyWorkflowWidget(): void {
		$registry = new \AIMS_Workflow_Surface_Registry();
		$registry->register_widgets();

		$widget_calls = TestState::get_hook_calls( 'register_widget' );
		$this->assertCount( 1, $widget_calls );
		$this->assertSame( 'AIMS_Workflow_Surface_Widget', $widget_calls[0]['args']['widget_class'] );
	}

	public function testHubCanRenderOnlyRequestedWorkflows(): void {
		$registry = new \AIMS_Workflow_Surface_Registry();

		$html = $registry->render_hub_shortcode(
			array(
				'workflow' => 'event_catalog,stitch_portal',
			)
		);

		$this->assertStringContainsString( 'Event Catalog', $html );
		$this->assertStringContainsString( 'Stitch Portal', $html );
		$this->assertStringNotContainsString( 'Wholesale Portal', $html );
		$this->assertStringContainsString( '[aims_events_catalog]', $html );
		$this->assertStringContainsString( '[aims_stitch_portal]', $html );

		$shortcode_calls = TestState::get_hook_calls( 'do_shortcode' );
		$this->assertCount( 2, $shortcode_calls );
	}
}
