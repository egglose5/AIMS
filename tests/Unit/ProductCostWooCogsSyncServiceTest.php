<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class ProductCostWooCogsSyncServiceTest extends \AIMS\Tests\TestCase {
	public function testRegisterHooksRegistersRuleSavedListener(): void {
		\AIMS_Product_Cost_Woo_Cogs_Sync_Service::register_hooks();

		$hooks = TestState::get_hook_calls( 'aims_product_cost_rule_saved' );
		$this->assertNotEmpty( $hooks );
	}

	public function testSyncPublicFloorReturnsHelpfulReasonWhenPostMetaFunctionsUnavailable(): void {
		$result = \AIMS_Product_Cost_Woo_Cogs_Sync_Service::sync_public_floor_for_product( 101, array() );

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'post_meta_unavailable', $result['reason'] );
	}
}
