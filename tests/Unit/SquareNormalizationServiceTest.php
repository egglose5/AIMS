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

	public function testAnalyzeOrderPayloadMatchesSavedChargeRulesAndSetsFlags(): void {
		update_option(
			\AIMS_Square_Order_Charge_Rule_Service::OPTION_RULES,
			array(
				array(
					'code'                  => 'line_unfulfilled',
					'label'                 => 'Unfulfilled Line Charge',
					'square_charge_name'    => 'Unfulfilled Line Charge',
					'flag_key'              => 'needs_manual_review',
					'apply_projection_charge' => true,
					'force_unfulfilled'     => true,
				),
			)
		);

		$service  = new \AIMS_Square_Normalization_Service();
		$analysis = $service->analyze_order_payload(
			array(
				'id'              => 'order-101',
				'location_id'     => 'loc-7',
				'service_charges' => array(
					array(
						'uid'          => 'svc-88',
						'name'         => 'Unfulfilled Line Charge',
						'amount_money' => array(
							'amount'   => 350,
							'currency' => 'USD',
						),
					),
				),
			)
		);

		$this->assertTrue( $analysis['charge_markers']['flags']['needs_manual_review'] );
		$this->assertCount( 1, $analysis['charge_markers']['projection_charges'] );
		$this->assertSame( 'line_unfulfilled', $analysis['charge_markers']['projection_charges'][0]['code'] );

		$record = $service->normalize_sale_record(
			array(
				'id'          => 'order-101',
				'location_id' => 'loc-7',
				'created_at'  => '2026-04-01 10:00:00',
			),
			array(
				'uid'            => 'line-56',
				'woo_product_id' => 45,
				'sku'            => 'SKU-56',
				'quantity'       => 1,
				'gross_amount'   => 10.00,
				'net_amount'     => 10.00,
				'tax_amount'     => 0.0,
			),
			$analysis,
			array(
				'vendor_id' => 9,
				'event_id'  => 3,
			)
		);

		$this->assertSame( array( 'needs_manual_review' => true ), $record['payload']['charge_flags'] );
		$this->assertSame( array( 'line_unfulfilled' ), $record['payload']['matched_charge_codes'] );
		$this->assertSame( \AIMS_Square_Sale_Repository::STATUS_PENDING, $record['fulfillment_status'] );
	}
}
