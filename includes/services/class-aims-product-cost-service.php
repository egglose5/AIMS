<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Product_Cost_Service {
	private $rules;

	public function __construct( AIMS_Product_Cost_Rule_Repository $rules ) {
		$this->rules = $rules;
	}

	public function resolve_unit_cost( int $product_id, int $vendor_id = 0 ): float {
		$matched_category_cost = 0.0;

		foreach ( $this->rules->get_active_rules() as $rule ) {
			$rule_vendor_id = (int) $rule['vendor_id'];

			if ( $rule_vendor_id > 0 && $rule_vendor_id !== $vendor_id ) {
				continue;
			}

			if (
				AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_PRODUCT === $rule['assignment_type'] &&
				(int) $rule['product_id'] === $product_id
			) {
				return (float) $rule['unit_cost'];
			}

			if (
				AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_CATEGORY === $rule['assignment_type'] &&
				(int) $rule['category_term_id'] > 0 &&
				has_term( (int) $rule['category_term_id'], 'product_cat', $product_id )
			) {
				$matched_category_cost = (float) $rule['unit_cost'];
			}
		}

		return $matched_category_cost;
	}
}

