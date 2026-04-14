<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchModulePublicHooksTest extends \AIMS\Tests\TestCase {
	public function testRegisterPublicHooksRegistersStitchPortalShortcodeAndActions(): void {
		$module = new \AIMS_Stitch_Module();
		$module->register_public_hooks();

		$shortcode_calls = TestState::get_hook_calls( 'add_shortcode' );
		$this->assertCount( 1, $shortcode_calls );
		$this->assertSame( 'aims_stitch_portal', $shortcode_calls[0]['args']['tag'] );

		$completion_calls = TestState::get_hook_calls( 'admin_post_aims_stitch_complete_item' );
		$this->assertCount( 1, $completion_calls );

		$guest_completion_calls = TestState::get_hook_calls( 'admin_post_nopriv_aims_stitch_complete_item' );
		$this->assertCount( 1, $guest_completion_calls );
	}
}
