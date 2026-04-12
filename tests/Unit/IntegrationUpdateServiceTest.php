<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class IntegrationUpdateServiceTest extends \AIMS\Tests\TestCase {
	public function testIngestUpdatesStoresNormalizedRows(): void {
		$service = new \AIMS_Integration_Update_Service(
			new class() extends \AIMS_Low_Stock_Alert_Service {
				public function __construct() {
				}

				public function get_dashboard_snapshot( int $limit = 25 ): array {
					unset( $limit );

					return array(
						'threshold'          => 5,
						'active_positions'   => 0,
						'tracked_products'   => 0,
						'low_stock_products' => 0,
						'alerts'             => array(),
					);
				}
			}
		);

		$summary = $service->ingest_updates(
			array(
				'updates' => array(
					array(
						'sku'                => 'WIDGET-001',
						'external_product_id'=> 'x-77',
						'available_quantity' => 2,
						'updated_at'         => '2026-04-10T12:00:00Z',
						'source'             => 'partner_system',
					),
				),
			)
		);

		$this->assertSame( 1, $summary['accepted'] );
		$this->assertSame( 0, $summary['skipped'] );
		$this->assertSame( 1, $summary['total_stored'] );

		$updates = $service->get_updates( 10 );
		$this->assertSame( 1, $updates['count'] );
		$this->assertSame( 'WIDGET-001', $updates['updates'][0]['sku'] );
		$this->assertSame( 'low', $updates['updates'][0]['status'] );
	}

	public function testFeedSnapshotReturnsStoredUpdatesAndLowStockSummary(): void {
		$service = new \AIMS_Integration_Update_Service(
			new class() extends \AIMS_Low_Stock_Alert_Service {
				public function __construct() {
				}

				public function get_dashboard_snapshot( int $limit = 25 ): array {
					unset( $limit );

					return array(
						'threshold'          => 7,
						'active_positions'   => 9,
						'tracked_products'   => 3,
						'low_stock_products' => 1,
						'alerts'             => array(
							array(
								'product_id'         => 44,
								'product_name'       => 'Tape',
								'available_quantity' => 1,
							),
						),
					);
				}
			}
		);

		$service->ingest_updates(
			array(
				'sku'                => 'PATCH-9',
				'available_quantity' => 1,
				'updated_at'         => '2026-04-11T10:30:00Z',
			)
		);

		$feed = $service->get_feed_snapshot( '', 25 );

		$this->assertSame( 1, $feed['updates_count'] );
		$this->assertSame( 7, $feed['low_stock_summary']['threshold'] );
		$this->assertSame( 1, $feed['low_stock_summary']['low_stock_products'] );
		$this->assertSame( 'PATCH-9', $feed['updates'][0]['sku'] );
	}
}