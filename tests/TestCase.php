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
		$this->wpdb = $GLOBALS['wpdb'];
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	protected function wpdb(): FakeWpdb {
		return $this->wpdb;
	}
}
