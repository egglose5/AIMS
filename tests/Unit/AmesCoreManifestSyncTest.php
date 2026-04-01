<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Core\Clients\SquareClient;
use AIMS\Core\Clients\WooCommerceClient;
use AIMS\Core\Sync\SyncOrchestrator;
use AIMS\Core\Sync\ManifestGenerator;
use AIMS\Tests\Support\FakeHttpTransport;

final class AmesCoreManifestSyncTest extends \AIMS\Tests\TestCase {
	public function testSingleClickManifestUsesLocalFakesAndResolvesPositionalTruth(): void {
		$transport = new FakeHttpTransport(
			array(
				'/wp-json/wc/v3/products' => array(
					'success' => true,
					'status'  => 200,
					'json'    => array(
						array(
							'id'             => 101,
							'sku'            => 'SKU-101',
							'name'           => 'Stage Light',
							'slug'           => 'stage-light',
							'price'          => '19.99',
							'regular_price'  => '24.99',
							'sale_price'     => '17.99',
							'stock_quantity' => 5,
							'stock_status'   => 'instock',
							'catalog_visibility' => 'visible',
						),
					),
				),
				'/v2/payments' => array(
					'success' => true,
					'status'  => 200,
					'json'    => array(
						'payments' => array(
							array(
								'id'          => 'pay_1',
								'status'      => 'COMPLETED',
								'created_at'  => '2026-04-01T12:00:00Z',
								'updated_at'  => '2026-04-01T12:00:01Z',
								'location_id' => 'loc-1',
								'payment'     => array( 'id' => 'pay_1', 'amount_money' => array( 'amount' => 1999, 'currency' => 'USD' ) ),
							),
						),
					),
				),
				'/v2/orders' => array(
					'success' => true,
					'status'  => 200,
					'json'    => array(
						'orders' => array(
							array(
								'id'          => 'order_1',
								'status'      => 'OPEN',
								'created_at'  => '2026-04-01T11:55:00Z',
								'updated_at'  => '2026-04-01T11:56:00Z',
								'location_id' => 'loc-1',
								'order'       => array( 'id' => 'order_1', 'line_items' => array() ),
							),
						),
					),
				),
			)
		);

		$woo = new WooCommerceClient( 'https://example.test', 'consumer-key', 'consumer-secret', $transport );
		$square = new SquareClient( 'https://square.example.test', 'access-token', $transport );
		$manifestGenerator = new ManifestGenerator( $woo, $square );
		$orchestrator = new SyncOrchestrator( $manifestGenerator );

		$manifest = $orchestrator->buildSingleClickManifest(
			array(
				'ledger_rows' => array(
					array(
						'sku'              => 'SKU-101',
						'show_id'          => 'SHOW-77',
						'location'         => 'backline',
						'quantity'         => 3,
						'last_movement_uuid' => 'move-001',
						'updated_at'       => '2026-04-01T12:01:00Z',
					),
				),
			)
		);

		$this->assertSame( 'single_click', $manifest['sync_mode'] );
		$this->assertSame( 'all_or_nothing_manifest', $manifest['consistency_model'] );
		$this->assertCount( 3, $transport->requests );
		$this->assertSame( 'Basic ' . base64_encode( 'consumer-key:consumer-secret' ), $transport->requests[0]['options']['headers']['authorization'] );
		$this->assertSame( 'SKU-101', $manifest['resolved_truth']['catalog'][0]['sku'] );
		$this->assertSame( 3.0, $manifest['resolved_truth']['catalog'][0]['stock_quantity'] );
		$this->assertSame( 'aims', $manifest['resolved_truth']['catalog'][0]['source_of_truth'] );
		$this->assertNotEmpty( $manifest['resolved_truth']['catalog'][0]['conflicts'] );
		$this->assertSame( 'order_1', $manifest['resolved_truth']['transactions'][0]['id'] );
		$this->assertSame( 'pay_1', $manifest['transactional_truth']['payments'][0]['id'] );
	}
}
