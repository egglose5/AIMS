<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class HotDbHealthServiceTest extends \AIMS\Tests\TestCase {
	public function testSnapshotReturnsGreenBandForComfortZone(): void {
		$wpdb = new \AIMS\Tests\Support\FakeWpdb( 'wp_' );
		$wpdb->queue_var( 20000 );
		$wpdb->queue_var( 15000 );
		$wpdb->queue_var( 10000 );
		$wpdb->queue_var( 5000 );

		$service  = new \AIMS_Hot_Db_Health_Service( $wpdb );
		$snapshot = $service->get_dashboard_snapshot();

		$this->assertSame( 'green', $snapshot['band'] );
		$this->assertSame( 50000, $snapshot['total_hot_rows'] );
		$this->assertSame( 20, $snapshot['usage_percent'] );
		$this->assertSame( 5000, $snapshot['estimated_order_equivalent'] );
	}

	public function testSnapshotReturnsYellowBandBeforeHardLimit(): void {
		$wpdb = new \AIMS\Tests\Support\FakeWpdb( 'wp_' );
		$wpdb->queue_var( 60000 );
		$wpdb->queue_var( 30000 );
		$wpdb->queue_var( 20000 );
		$wpdb->queue_var( 10000 );

		$service  = new \AIMS_Hot_Db_Health_Service( $wpdb );
		$snapshot = $service->get_dashboard_snapshot();

		$this->assertSame( 'yellow', $snapshot['band'] );
		$this->assertSame( 120000, $snapshot['total_hot_rows'] );
		$this->assertSame( 48, $snapshot['usage_percent'] );
		$this->assertStringContainsString( 'entering its caution band', $snapshot['message'] );
	}

	public function testSnapshotWarnsAsArchiveThresholdApproaches(): void {
		$wpdb = new \AIMS\Tests\Support\FakeWpdb( 'wp_' );
		$wpdb->queue_var( 30000 );
		$wpdb->queue_var( 25000 );
		$wpdb->queue_var( 20000 );
		$wpdb->queue_var( 10000 );

		$service  = new \AIMS_Hot_Db_Health_Service( $wpdb );
		$snapshot = $service->get_dashboard_snapshot();

		$this->assertSame( 'green', $snapshot['band'] );
		$this->assertTrue( $snapshot['should_warn'] );
		$this->assertFalse( $snapshot['should_auto_archive'] );
		$this->assertSame( 'info', $snapshot['warning_level'] );
		$this->assertStringContainsString( 'approaching the automatic archive threshold', $snapshot['message'] );
	}

	public function testSnapshotFlagsAutomaticArchiveAtOperationalThreshold(): void {
		$wpdb = new \AIMS\Tests\Support\FakeWpdb( 'wp_' );
		$wpdb->queue_var( 40000 );
		$wpdb->queue_var( 30000 );
		$wpdb->queue_var( 20000 );
		$wpdb->queue_var( 15000 );

		$service  = new \AIMS_Hot_Db_Health_Service( $wpdb );
		$snapshot = $service->get_dashboard_snapshot();

		$this->assertSame( 'yellow', $snapshot['band'] );
		$this->assertTrue( $snapshot['should_warn'] );
		$this->assertTrue( $snapshot['should_auto_archive'] );
		$this->assertSame( 'archive', $snapshot['warning_level'] );
		$this->assertStringContainsString( 'automatic archive rotation', $snapshot['message'] );
	}

	public function testSnapshotReturnsRedBandAtSharedHostPressurePoint(): void {
		$wpdb = new \AIMS\Tests\Support\FakeWpdb( 'wp_' );
		$wpdb->queue_var( 100000 );
		$wpdb->queue_var( 80000 );
		$wpdb->queue_var( 50000 );
		$wpdb->queue_var( 30000 );

		$service  = new \AIMS_Hot_Db_Health_Service( $wpdb );
		$snapshot = $service->get_dashboard_snapshot();

		$this->assertSame( 'red', $snapshot['band'] );
		$this->assertSame( 260000, $snapshot['total_hot_rows'] );
		$this->assertSame( 100, $snapshot['usage_percent'] );
		$this->assertStringContainsString( 'ERP migration work', $snapshot['message'] );
	}
}
