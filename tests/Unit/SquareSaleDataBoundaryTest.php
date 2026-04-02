<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareSaleDataBoundaryTest extends \AIMS\Tests\TestCase {
	public function testNormalizeSaleRecordCapturesActualPaidAmountAndTax(): void {
		$service = new \AIMS_Square_Normalization_Service();

		$record = $service->normalize_sale_record(
			array(
				'id'         => 'order-1',
				'location_id'=> 'loc-1',
				'created_at' => '2026-04-01T16:00:00Z',
			),
			array(
				'uid'                 => 'line-1',
				'sku'                 => 'SKU-1',
				'quantity'            => '1',
				'gross_amount_money'  => array( 'amount' => 2400 ),
				'net_amount_money'    => array( 'amount' => 1800 ),
				'tax_amount_money'    => array( 'amount' => 144 ),
				'applied_discounts'   => array(
					array(
						'applied_money' => array( 'amount' => 600 ),
					),
				),
			)
		);

		$this->assertSame( 24.0, $record['gross_amount'] );
		$this->assertSame( 18.0, $record['net_amount'] );
		$this->assertSame( 18.0, $record['amount_paid'] );
		$this->assertSame( 1.44, $record['tax_amount'] );
		$this->assertSame( 6.0, $record['discount_amount'] );
	}

	public function testSquareSaleRepositoryPersistsTaxAmount(): void {
		$repository = new \AIMS_Square_Sale_Repository();

		$insert_id = $repository->save(
			array(
				'square_order_id'      => 'order-1',
				'square_line_item_uid' => 'line-1',
				'sku'                  => 'SKU-1',
				'tax_amount'           => 1.44,
				'amount_paid'          => 18.00,
				'gross_amount'         => 24.00,
				'net_amount'           => 18.00,
				'quantity'             => 1,
			)
		);

		$this->assertSame( 1, $insert_id );
		$this->assertSame( '1.44', $this->wpdb()->inserted[0]['data']['tax_amount'] );
		$this->assertSame( '18.00', $this->wpdb()->inserted[0]['data']['amount_paid'] );
	}

	public function testSchemaDefinesSaleAmountTrackingOnSquareSalesTable(): void {
		$definitions = implode( "\n", ( new \AIMS_Schema() )->get_table_definitions() );

		$this->assertStringContainsString( 'tax_amount decimal(20,2) NOT NULL DEFAULT 0.00', $definitions );
		$this->assertStringContainsString( 'KEY tax_amount (tax_amount)', $definitions );
		$this->assertStringContainsString( 'amount_paid decimal(20,2) NOT NULL DEFAULT 0.00', $definitions );
		$this->assertStringContainsString( 'KEY amount_paid (amount_paid)', $definitions );
	}
}
