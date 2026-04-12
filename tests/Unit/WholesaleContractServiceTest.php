<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class WholesaleContractServiceTest extends \AIMS\Tests\TestCase {
	public function testParseTierRatesSortsAndNormalizesRows(): void {
		$service = new \AIMS_Wholesale_Contract_Service();

		$rows = $service->parse_tier_rates( "25:12.5\n10:5\n100:22.77777\n" );

		$this->assertCount( 3, $rows );
		$this->assertSame( 10, $rows[0]['min_qty'] );
		$this->assertSame( 5.0, $rows[0]['discount_percent'] );
		$this->assertSame( 25, $rows[1]['min_qty'] );
		$this->assertSame( 12.5, $rows[1]['discount_percent'] );
		$this->assertSame( 100, $rows[2]['min_qty'] );
		$this->assertSame( 22.7778, $rows[2]['discount_percent'] );
	}

	public function testSanitizeTierRatesRawDropsInvalidRowsAndFormatsOutput(): void {
		$service = new \AIMS_Wholesale_Contract_Service();

		$sanitized = $service->sanitize_tier_rates_raw( "bad\n5:4\n\n200:15.5\n" );

		$this->assertSame( "5:4\n200:15.5", $sanitized );
	}

	public function testResolveDiscountForQuantitySelectsHighestMatchingTier(): void {
		$service = new \AIMS_Wholesale_Contract_Service();
		$tier_rates = $service->parse_tier_rates( "5:3\n10:5\n30:12" );

		$this->assertSame( 0.0, $service->resolve_discount_for_quantity( $tier_rates, 4 ) );
		$this->assertSame( 3.0, $service->resolve_discount_for_quantity( $tier_rates, 5 ) );
		$this->assertSame( 5.0, $service->resolve_discount_for_quantity( $tier_rates, 10 ) );
		$this->assertSame( 12.0, $service->resolve_discount_for_quantity( $tier_rates, 45 ) );
	}

	public function testParseTierRatesSkipsMalformedQuantityOrDiscountRows(): void {
		$service = new \AIMS_Wholesale_Contract_Service();
		$rows = $service->parse_tier_rates( "abc:10\n20:oops\n30:15\n" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 30, $rows[0]['min_qty'] );
		$this->assertSame( 15.0, $rows[0]['discount_percent'] );
	}
}
