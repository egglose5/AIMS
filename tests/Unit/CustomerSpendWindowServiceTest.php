<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class CustomerSpendWindowServiceTest extends \AIMS\Tests\TestCase {
	public function testDashboardSnapshotReturnsUnresolvedWhenLookupIsMissing(): void {
		$service = new \AIMS_Customer_Spend_Window_Service(
			static function ( string $lookup ) {
				return null;
			},
			static function ( int $customer_id, string $after_iso, array $statuses, int $limit ): array {
				return array();
			}
		);

		$snapshot = $service->get_dashboard_snapshot( '', 30 );

		$this->assertFalse( $snapshot['resolved'] );
		$this->assertSame( 0.0, $snapshot['total_spend'] );
		$this->assertSame( 0, $snapshot['order_count'] );
	}

	public function testDashboardSnapshotAggregatesCustomerSpendWithinWindow(): void {
		$service = new \AIMS_Customer_Spend_Window_Service(
			static function ( string $lookup ): ?array {
				if ( 'customer@example.test' !== $lookup ) {
					return null;
				}

				return array(
					'id'           => 42,
					'display_name' => 'Customer X',
					'email'        => 'customer@example.test',
				);
			},
			static function ( int $customer_id, string $after_iso, array $statuses, int $limit ): array {
				return array(
					array( 'order_id' => 1001, 'total' => 120.25, 'date' => '2026-04-01', 'status' => 'completed' ),
					array( 'order_id' => 1002, 'total' => 55.75, 'date' => '2026-04-05', 'status' => 'processing' ),
				);
			}
		);

		$snapshot = $service->get_dashboard_snapshot( 'customer@example.test', 45, 10 );

		$this->assertTrue( $snapshot['resolved'] );
		$this->assertSame( 42, $snapshot['customer']['id'] );
		$this->assertSame( 176.0, $snapshot['total_spend'] );
		$this->assertSame( 2, $snapshot['order_count'] );
		$this->assertCount( 2, $snapshot['orders'] );
	}

	public function testDashboardSnapshotHandlesUnknownCustomerLookup(): void {
		$service = new \AIMS_Customer_Spend_Window_Service(
			static function ( string $lookup ) {
				return null;
			},
			static function ( int $customer_id, string $after_iso, array $statuses, int $limit ): array {
				return array();
			}
		);

		$snapshot = $service->get_dashboard_snapshot( 'missing-user', 60 );

		$this->assertFalse( $snapshot['resolved'] );
		$this->assertSame( 'missing-user', $snapshot['customer_lookup'] );
		$this->assertStringContainsString( 'not found', (string) $snapshot['message'] );
	}
}
