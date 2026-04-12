<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class PluginCustomerSpendSettingsTest extends \AIMS\Tests\TestCase {
	public function testSanitizeCustomerSpendWindowDaysUsesSafeBounds(): void {
		$this->assertSame( 30, \AIMS_Plugin::sanitize_customer_spend_window_days( '0' ) );
		$this->assertSame( 14, \AIMS_Plugin::sanitize_customer_spend_window_days( '14' ) );
		$this->assertSame( 3650, \AIMS_Plugin::sanitize_customer_spend_window_days( '999999' ) );
	}

	public function testSanitizeCustomerSpendQualifyAmountUsesSafeBounds(): void {
		$this->assertSame( 0.0, \AIMS_Plugin::sanitize_customer_spend_qualify_amount( '-10' ) );
		$this->assertSame( 125.56, \AIMS_Plugin::sanitize_customer_spend_qualify_amount( '125.556' ) );
		$this->assertSame( 1000000000.0, \AIMS_Plugin::sanitize_customer_spend_qualify_amount( '10000000000000' ) );
	}
}
