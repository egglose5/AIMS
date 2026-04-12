<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class ShippingQueueDataProviderTest extends \AIMS\Tests\TestCase {
	public function testFifoLocationUrlDoesNotDoubleEncodeSku(): void {
		$sales = new class() extends \AIMS_Square_Sale_Repository {
			public function __construct() {}

			public function get_by_fulfillment_statuses( array $statuses, int $limit = 100 ): array {
				unset( $statuses, $limit );
				return array(
					array(
						'square_order_id'      => 'SQ-1',
						'sku'                  => 'AB/C 1%',
						'quantity'             => 1,
						'fulfillment_status'   => self::STATUS_NEEDS_SHIPPING,
						'event_id'             => 2,
						'woo_order_id'         => 3,
						'sold_at'              => '2026-04-11 10:00:00',
					),
				);
			}
		};

		$provider = new \AIMS_Shipping_Queue_Data_Provider( $sales );
		$rows     = $provider->get_rows();
		$url      = (string) ( $rows[0]['fifo_location_url'] ?? '' );

		$this->assertStringContainsString( 'aims_fifo_sku=AB%2FC+1%25', $url );
		$this->assertStringNotContainsString( '%252F', $url );
	}
}
