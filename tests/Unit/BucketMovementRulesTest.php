<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PHPUnit\Framework\TestCase;

/**
 * Tests for AIMS_Bucket_Movement_Rules state machine and validation
 */
class AIMS_Bucket_Movement_RulesTest extends TestCase {

	/**
	 * Test that rules() returns exactly 8 movement rules
	 */
	public function testRulesReturnsEightMovements(): void {
		$rules = AIMS_Bucket_Movement_Rules::rules();
		$this->assertCount( 8, $rules );
	}

	/**
	 * Test that all required keys are present in each rule
	 */
	public function testEachRuleHasRequiredKeys(): void {
		$rules = AIMS_Bucket_Movement_Rules::rules();
		$required_keys = array( 'from_bucket', 'to_bucket', 'qty_change', 'requires_event', 'requires_notes', 'description' );

		foreach ( $rules as $movement_type => $rule ) {
			foreach ( $required_keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$rule,
					sprintf( 'Rule "%s" is missing key "%s"', $movement_type, $key )
				);
			}
		}
	}

	/**
	 * Test stitch_order_release movement rule
	 */
	public function testStitchOrderReleaseRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'stitch_order_release' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'production_virtual', $rule['from_bucket'] );
		$this->assertEquals( 'stitcher', $rule['to_bucket'] );
		$this->assertEquals( '+', $rule['qty_change'] );
		$this->assertFalse( $rule['requires_event'] );
		$this->assertFalse( $rule['requires_notes'] );
	}

	/**
	 * Test stitcher_to_warehouse movement rule
	 */
	public function testStitcherToWarehouseRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'stitcher_to_warehouse' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'stitcher', $rule['from_bucket'] );
		$this->assertEquals( 'warehouse_stock', $rule['to_bucket'] );
		$this->assertEquals( '0', $rule['qty_change'] );
		$this->assertFalse( $rule['requires_event'] );
		$this->assertFalse( $rule['requires_notes'] );
	}

	/**
	 * Test warehouse_to_event_prepack movement rule
	 */
	public function testWarehouseToEventPrepackRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'warehouse_to_event_prepack' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'warehouse_stock', $rule['from_bucket'] );
		$this->assertEquals( 'warehouse_event_prepack', $rule['to_bucket'] );
		$this->assertEquals( '0', $rule['qty_change'] );
		$this->assertTrue( $rule['requires_event'] );
		$this->assertFalse( $rule['requires_notes'] );
	}

	/**
	 * Test event_prepack_to_show movement rule
	 */
	public function testEventPrepackToShowRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'event_prepack_to_show' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'warehouse_event_prepack', $rule['from_bucket'] );
		$this->assertEquals( 'show_live', $rule['to_bucket'] );
		$this->assertEquals( '0', $rule['qty_change'] );
		$this->assertTrue( $rule['requires_event'] );
		$this->assertFalse( $rule['requires_notes'] );
	}

	/**
	 * Test show_sale movement rule
	 */
	public function testShowSaleRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'show_sale' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'show_live', $rule['from_bucket'] );
		$this->assertEquals( 'consumed_virtual', $rule['to_bucket'] );
		$this->assertEquals( '−', $rule['qty_change'] );
		$this->assertTrue( $rule['requires_event'] );
		$this->assertFalse( $rule['requires_notes'] );
	}

	/**
	 * Test show_return_checkin movement rule
	 */
	public function testShowReturnCheckinRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'show_return_checkin' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'show_live', $rule['from_bucket'] );
		$this->assertEquals( 'return_reconciliation', $rule['to_bucket'] );
		$this->assertEquals( '0', $rule['qty_change'] );
		$this->assertTrue( $rule['requires_event'] );
		$this->assertFalse( $rule['requires_notes'] );
	}

	/**
	 * Test return_restock_to_warehouse movement rule
	 */
	public function testReturnRestockToWarehouseRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'return_restock_to_warehouse' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'return_reconciliation', $rule['from_bucket'] );
		$this->assertEquals( 'warehouse_stock', $rule['to_bucket'] );
		$this->assertEquals( '0', $rule['qty_change'] );
		$this->assertTrue( $rule['requires_event'] );
		$this->assertFalse( $rule['requires_notes'] );
	}

	/**
	 * Test show_shrink_writeoff movement rule
	 */
	public function testShowShrinkWriteoffRule(): void {
		$rule = AIMS_Bucket_Movement_Rules::get_rule( 'show_shrink_writeoff' );
		$this->assertNotNull( $rule );
		$this->assertEquals( 'show_live', $rule['from_bucket'] );
		$this->assertEquals( 'shrink_virtual', $rule['to_bucket'] );
		$this->assertEquals( '−', $rule['qty_change'] );
		$this->assertTrue( $rule['requires_event'] );
		$this->assertTrue( $rule['requires_notes'] );
	}

	/**
	 * Test is_allowed_movement validates movement types
	 */
	public function testIsAllowedMovementValidatesTypes(): void {
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_movement( 'stitch_order_release' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_movement( 'show_sale' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_allowed_movement( 'invalid_movement' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_allowed_movement( '' ) );
	}

	/**
	 * Test allowed_movements returns all 8 movement types
	 */
	public function testAllowedMovementsReturnsEightTypes(): void {
		$movements = AIMS_Bucket_Movement_Rules::allowed_movements();
		$this->assertCount( 8, $movements );
		$this->assertContains( 'stitch_order_release', $movements );
		$this->assertContains( 'show_sale', $movements );
		$this->assertContains( 'show_shrink_writeoff', $movements );
	}

	/**
	 * Test is_allowed_transition validates bucket paths
	 */
	public function testIsAllowedTransitionValidatesPaths(): void {
		// Valid transitions
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'production_virtual', 'stitcher' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'warehouse_stock', 'warehouse_event_prepack' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'show_live', 'consumed_virtual' ) );

		// Invalid transitions
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'warehouse_stock', 'show_live' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'stitcher', 'show_live' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'damaged_hold', 'warehouse_stock' ) );
	}

	/**
	 * Test get_movements_for_transition returns matching movement types
	 */
	public function testGetMovementsForTransitionReturnsMatches(): void {
		$movements = AIMS_Bucket_Movement_Rules::get_movements_for_transition( 'stitcher', 'warehouse_stock' );
		$this->assertCount( 1, $movements );
		$this->assertContains( 'stitcher_to_warehouse', $movements );

		$movements = AIMS_Bucket_Movement_Rules::get_movements_for_transition( 'warehouse_stock', 'invalid_bucket' );
		$this->assertCount( 0, $movements );
	}

	/**
	 * Test requires_event identifies event-dependent movements
	 */
	public function testRequiresEventIdentifiesConstraints(): void {
		// Non-event movements
		$this->assertFalse( AIMS_Bucket_Movement_Rules::requires_event( 'stitch_order_release' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::requires_event( 'stitcher_to_warehouse' ) );

		// Event-required movements
		$this->assertTrue( AIMS_Bucket_Movement_Rules::requires_event( 'warehouse_to_event_prepack' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::requires_event( 'show_sale' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::requires_event( 'show_shrink_writeoff' ) );
	}

	/**
	 * Test requires_notes identifies note-dependent movements
	 */
	public function testRequiresNotesIdentifiesConstraints(): void {
		// Most movements do not require notes
		$this->assertFalse( AIMS_Bucket_Movement_Rules::requires_notes( 'stitch_order_release' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::requires_notes( 'show_sale' ) );

		// Only shrink writeoff requires notes
		$this->assertTrue( AIMS_Bucket_Movement_Rules::requires_notes( 'show_shrink_writeoff' ) );
	}

	/**
	 * Test qty_change_for_movement returns correct semantics
	 */
	public function testQtyChangeForMovementReturnsSemantic(): void {
		// Creation movement
		$this->assertEquals( '+', AIMS_Bucket_Movement_Rules::qty_change_for_movement( 'stitch_order_release' ) );

		// Consumption movements
		$this->assertEquals( '−', AIMS_Bucket_Movement_Rules::qty_change_for_movement( 'show_sale' ) );
		$this->assertEquals( '−', AIMS_Bucket_Movement_Rules::qty_change_for_movement( 'show_shrink_writeoff' ) );

		// Transfer movements
		$this->assertEquals( '0', AIMS_Bucket_Movement_Rules::qty_change_for_movement( 'stitcher_to_warehouse' ) );
		$this->assertEquals( '0', AIMS_Bucket_Movement_Rules::qty_change_for_movement( 'warehouse_to_event_prepack' ) );
	}

	/**
	 * Test is_creation_movement identifies creation moves
	 */
	public function testIsCreationMovementIdentifiesWorks(): void {
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_creation_movement( 'stitch_order_release' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_creation_movement( 'show_sale' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_creation_movement( 'stitcher_to_warehouse' ) );
	}

	/**
	 * Test is_consumption_movement identifies consume moves
	 */
	public function testIsConsumptionMovementIdentifiesRemoval(): void {
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_consumption_movement( 'show_sale' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_consumption_movement( 'show_shrink_writeoff' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_consumption_movement( 'stitch_order_release' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_consumption_movement( 'stitcher_to_warehouse' ) );
	}

	/**
	 * Test is_transfer_movement identifies transfer moves
	 */
	public function testIsTransferMovementIdentifiesTransfers(): void {
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'stitcher_to_warehouse' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'warehouse_to_event_prepack' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'warehouse_to_event_prepack' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'show_sale' ) );
		$this->assertFalse( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'stitch_order_release' ) );
	}

	/**
	 * Test validate_movement comprehensive validation
	 */
	public function testValidateMovementComprehensiveValidation(): void {
		// Valid transfer movement without event
		$result = AIMS_Bucket_Movement_Rules::validate_movement(
			'stitcher_to_warehouse',
			'stitcher',
			'warehouse_stock',
			0,
			''
		);
		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );

		// Valid event movement with event
		$result = AIMS_Bucket_Movement_Rules::validate_movement(
			'warehouse_to_event_prepack',
			'warehouse_stock',
			'warehouse_event_prepack',
			123,
			''
		);
		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );

		// Invalid: event movement missing event_id
		$result = AIMS_Bucket_Movement_Rules::validate_movement(
			'warehouse_to_event_prepack',
			'warehouse_stock',
			'warehouse_event_prepack',
			0,
			''
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );

		// Invalid: shrink writeoff missing notes
		$result = AIMS_Bucket_Movement_Rules::validate_movement(
			'show_shrink_writeoff',
			'show_live',
			'shrink_virtual',
			123,
			''
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );

		// Valid: shrink writeoff with notes
		$result = AIMS_Bucket_Movement_Rules::validate_movement(
			'show_shrink_writeoff',
			'show_live',
			'shrink_virtual',
			123,
			'Items damaged during transport'
		);
		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test get_movements_from_bucket returns source movements
	 */
	public function testGetMovementsFromBucketReturnsOrigins(): void {
		// Warehouse_stock has one outbound movement
		$movements = AIMS_Bucket_Movement_Rules::get_movements_from_bucket( 'warehouse_stock' );
		$this->assertContains( 'warehouse_to_event_prepack', $movements );

		// Show_live has multiple outbound movements
		$movements = AIMS_Bucket_Movement_Rules::get_movements_from_bucket( 'show_live' );
		$this->assertContains( 'show_sale', $movements );
		$this->assertContains( 'show_return_checkin', $movements );
		$this->assertContains( 'show_shrink_writeoff', $movements );
	}

	/**
	 * Test get_movements_to_bucket returns destination movements
	 */
	public function testGetMovementsToBucketReturnsDestinations(): void {
		// warehouse_stock receives from stitcher and returns
		$movements = AIMS_Bucket_Movement_Rules::get_movements_to_bucket( 'warehouse_stock' );
		$this->assertContains( 'stitcher_to_warehouse', $movements );
		$this->assertContains( 'return_restock_to_warehouse', $movements );

		// stitcher receives from production_virtual
		$movements = AIMS_Bucket_Movement_Rules::get_movements_to_bucket( 'stitcher' );
		$this->assertContains( 'stitch_order_release', $movements );
	}

	/**
	 * Test by_category organization
	 */
	public function testByCategoryOrganizesMovements(): void {
		$categories = AIMS_Bucket_Movement_Rules::by_category();

		$this->assertArrayHasKey( 'work_orders', $categories );
		$this->assertArrayHasKey( 'returns', $categories );
		$this->assertArrayHasKey( 'event_allocation', $categories );
		$this->assertArrayHasKey( 'pos_transactions', $categories );
		$this->assertArrayHasKey( 'disposition', $categories );

		// Verify categorization
		$this->assertContains( 'stitch_order_release', $categories['work_orders'] );
		$this->assertContains( 'stitcher_to_warehouse', $categories['returns'] );
		$this->assertContains( 'warehouse_to_event_prepack', $categories['event_allocation'] );
		$this->assertContains( 'show_sale', $categories['pos_transactions'] );
	}

	/**
	 * Test quantity flow consistency
	 */
	public function testQuantityFlowConsistency(): void {
		// Work orders start with creation (+)
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_creation_movement( 'stitch_order_release' ) );

		// Transfers maintain quantity (0)
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'stitcher_to_warehouse' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'warehouse_to_event_prepack' ) );

		// Sales and losses consume (−)
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_consumption_movement( 'show_sale' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_consumption_movement( 'show_shrink_writeoff' ) );
	}

	/**
	 * Test operational workflow path
	 */
	public function testOperationalWorkflowPath(): void {
		// Work order creation (production → stitcher)
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'production_virtual', 'stitcher' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_creation_movement( 'stitch_order_release' ) );

		// Stitcher completion (stitcher → warehouse)
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'stitcher', 'warehouse_stock' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_transfer_movement( 'stitcher_to_warehouse' ) );

		// Event workflow (warehouse → prepack → show)
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'warehouse_stock', 'warehouse_event_prepack' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'warehouse_event_prepack', 'show_live' ) );

		// Show outcomes (sale to consumed, return to reconciliation)
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'show_live', 'consumed_virtual' ) );
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'show_live', 'return_reconciliation' ) );

		// Return disposition
		$this->assertTrue( AIMS_Bucket_Movement_Rules::is_allowed_transition( 'return_reconciliation', 'warehouse_stock' ) );
	}
}
