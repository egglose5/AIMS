<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class ProductCostRuleRepositoryTest extends \AIMS\Tests\TestCase {
	public function testSaveFiresRuleSavedActionForInsert(): void {
		$repository = new \AIMS_Product_Cost_Rule_Repository();

		$rule_id = $repository->save(
			array(
				'assignment_type' => \AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_PRODUCT,
				'product_id'      => 41,
				'unit_cost'       => 12.5,
				'is_active'       => 1,
			)
		);

		$this->assertGreaterThan( 0, $rule_id );
		$hooks = TestState::get_hook_calls( 'aims_product_cost_rule_saved' );
		$this->assertCount( 1, $hooks );
		$this->assertSame( $rule_id, (int) ( $hooks[0]['args']['args'][0] ?? 0 ) );
		$this->assertSame( 41, (int) ( $hooks[0]['args']['args'][1]['product_id'] ?? 0 ) );
	}

	public function testSaveFiresRuleSavedActionForUpdate(): void {
		$repository = new \AIMS_Product_Cost_Rule_Repository();

		$rule_id = $repository->save(
			array(
				'assignment_type' => \AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_PRODUCT,
				'product_id'      => 55,
				'unit_cost'       => 8.25,
				'is_active'       => 1,
			),
			77
		);

		$this->assertSame( 77, $rule_id );
		$hooks = TestState::get_hook_calls( 'aims_product_cost_rule_saved' );
		$this->assertCount( 1, $hooks );
		$this->assertSame( 77, (int) ( $hooks[0]['args']['args'][0] ?? 0 ) );
		$this->assertSame( 55, (int) ( $hooks[0]['args']['args'][1]['product_id'] ?? 0 ) );
	}
}
