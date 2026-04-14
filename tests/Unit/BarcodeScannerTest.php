<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class BarcodeScannerTest extends \AIMS\Tests\TestCase {
	public function testFrontendAssetsDoNotEnqueueWithoutScannerShortcodeContext(): void {
		$scanner = new \AIMS_Barcode_Scanner();
		$scanner->enqueue_assets();

		$this->assertCount( 0, TestState::get_hook_calls( 'wp_enqueue_script' ) );
		$this->assertCount( 0, TestState::get_hook_calls( 'wp_enqueue_style' ) );
	}

	public function testRenderingScannerShortcodeForcesAssetsToEnqueue(): void {
		$scanner = new \AIMS_Barcode_Scanner();
		$html    = $scanner->render_shortcode(
			array(
				'target' => 'scanner-field',
				'label'  => 'Scan Now',
			)
		);

		$this->assertStringContainsString( 'scanner-field', $html );
		$this->assertCount( 2, TestState::get_hook_calls( 'wp_enqueue_script' ) );
		$this->assertCount( 1, TestState::get_hook_calls( 'wp_enqueue_style' ) );
	}
}
