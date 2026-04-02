<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareNormalizationServiceTest extends \AIMS\Tests\TestCase {
	public function testNormalizeSaleRecordStoresLeanOperationalPayloadAndActualPaidAmount(): void {
		$service = new \AIMS_Square_Normalization_Service();

		$record = $service->normalize_sale_record(
			array(
				'id'          => 'order-100',
				'location_id' => 'loc-7',
				'created_at'  => '2026-04-01 10:00:00',
			),
			array(
				'uid'             => 'line-55',
				'woo_product_id'  => 44,
				'sku'             => 'SKU-55',
				'quantity'        => 2,
				'gross_amount'    => 24.00,
				'net_amount'      => 18.00,
				'discount_amount' => 6.00,
				'tax_amount'      => 1.44,
				'name'            => 'Catalog Title',
				'variation_name'  => 'Large / Blue',
				'image_url'       => 'https://example.com/image.jpg',
			),
			array(),
			array(
				'vendor_id'          => 9,
				'event_id'           => 3,
				'woo_product_id'     => 44,
				'square_location_id' => 'loc-7',
			)
		);

		$this->assertSame( 18.0, $record['net_amount'] );
		$this->assertSame( 'SKU-55', $record['payload']['sku'] );
		$this->assertSame( 18.0, $record['payload']['amount_paid'] );
		$this->assertSame( 1.44, $record['payload']['tax_amount'] );
		$this->assertArrayNotHasKey( 'name', $record['payload'] );
		$this->assertArrayNotHasKey( 'variation_name', $record['payload'] );
		$this->assertArrayNotHasKey( 'image_url', $record['payload'] );
	}
}
