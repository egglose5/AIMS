<?php

declare( strict_types=1 );

namespace AIMS\Tests;

use AIMS\Tests\Support\FakeWpdb;
use AIMS\Tests\Support\TestState;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
	protected FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		TestState::reset();

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof FakeWpdb ) {
			$GLOBALS['wpdb']->reset();
		}

		$this->wpdb = $GLOBALS['wpdb'];
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	protected function wpdb(): FakeWpdb {
		return $this->wpdb;
	}

	protected function registerRuntimeRoleFromTemplate( string $role_slug, string $template_key, array $caps = array(), ?string $role_name = null ): array {
		$template = \AIMS_Capabilities::get_role_templates()[ $template_key ] ?? array();
		$resolved_role_name = $role_name ?: (string) ( $template['role_name'] ?? $role_slug );

		$result = \AIMS_Capabilities::create_or_update_custom_role(
			$role_slug,
			$resolved_role_name,
			$template_key,
			$caps
		);

		$this->assertTrue( $result['success'] ?? false, 'Expected runtime role registration to succeed.' );

		return (array) ( $result['role'] ?? array() );
	}
}
