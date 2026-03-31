<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Physical_Bucket_Types;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AIMS_Physical_Bucket_Types constants and validation methods
 */
final class PhysicalBucketTypesTest extends TestCase {

	/**
	 * Test that all bucket type constants are defined
	 */
	public function testBucketTypeConstantsAreDefined(): void {
		$this->assertEquals( 'warehouse_stock', AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK );
		$this->assertEquals( 'warehouse_event_prepack', AIMS_Physical_Bucket_Types::WAREHOUSE_EVENT_PREPACK );
		$this->assertEquals( 'woo_fulfillment', AIMS_Physical_Bucket_Types::WOO_FULFILLMENT );
		$this->assertEquals( 'show_live', AIMS_Physical_Bucket_Types::SHOW_LIVE );
		$this->assertEquals( 'stitcher', AIMS_Physical_Bucket_Types::STITCHER );
		$this->assertEquals( 'return_reconciliation', AIMS_Physical_Bucket_Types::RETURN_RECONCILIATION );
		$this->assertEquals( 'damage_hold', AIMS_Physical_Bucket_Types::DAMAGE_HOLD );
		$this->assertEquals( 'consumed_virtual', AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL );
		$this->assertEquals( 'shrink_virtual', AIMS_Physical_Bucket_Types::SHRINK_VIRTUAL );
	}

	/**
	 * Test that allowed() returns exactly 10 allowed bucket types
	 */
	public function testAllowedReturnsNineTypes(): void {
		$allowed = AIMS_Physical_Bucket_Types::allowed();
		$this->assertCount( 10, $allowed );

		// Verify all expected types are present
		$this->assertContains( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::WAREHOUSE_EVENT_PREPACK, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::WOO_FULFILLMENT, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::SHOW_LIVE, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::STITCHER, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::RETURN_RECONCILIATION, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::DAMAGE_HOLD, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::SHRINK_VIRTUAL, $allowed );
		$this->assertContains( AIMS_Physical_Bucket_Types::PRODUCTION_VIRTUAL, $allowed );
	}

	/**
	 * Test is_allowed() validates bucket types
	 */
	public function testIsAllowedValidatesBucketType(): void {
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_allowed( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK ) );
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_allowed( 'warehouse_stock' ) );
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_allowed( AIMS_Physical_Bucket_Types::STITCHER ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_allowed( 'invalid_type' ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_allowed( 'standard' ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_allowed( '' ) );
	}

	/**
	 * Test normalize() returns valid types and defaults to warehouse_stock
	 */
	public function testNormalizeReturnsValidTypeOrDefault(): void {
		$this->assertEquals( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, AIMS_Physical_Bucket_Types::normalize( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK ) );
		$this->assertEquals( AIMS_Physical_Bucket_Types::STITCHER, AIMS_Physical_Bucket_Types::normalize( AIMS_Physical_Bucket_Types::STITCHER ) );
		$this->assertEquals( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, AIMS_Physical_Bucket_Types::normalize( 'invalid' ) );
		$this->assertEquals( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, AIMS_Physical_Bucket_Types::normalize( '' ) );
		$this->assertEquals( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, AIMS_Physical_Bucket_Types::normalize( 'standard' ) );
	}

	/**
	 * Test description() returns meaningful descriptions
	 */
	public function testDescriptionReturnsHumanReadableText(): void {
		$this->assertEquals( 'Normal sellable warehouse inventory', AIMS_Physical_Bucket_Types::description( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK ) );
		$this->assertEquals( 'Event-specific separated inventory', AIMS_Physical_Bucket_Types::description( AIMS_Physical_Bucket_Types::WAREHOUSE_EVENT_PREPACK ) );
		$this->assertEquals( 'Inventory in custody of a stitcher', AIMS_Physical_Bucket_Types::description( AIMS_Physical_Bucket_Types::STITCHER ) );
		$this->assertEquals( 'Sold/consumed stock', AIMS_Physical_Bucket_Types::description( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL ) );
		$this->assertStringContainsString( 'Unknown', AIMS_Physical_Bucket_Types::description( 'nonexistent' ) );
	}

	/**
	 * Test by_category() organizes types into logical groups
	 */
	public function testByCategoryOrganizesTypesIntoFourCategories(): void {
		$categories = AIMS_Physical_Bucket_Types::by_category();

		$this->assertArrayHasKey( 'warehouse', $categories );
		$this->assertArrayHasKey( 'destination', $categories );
		$this->assertArrayHasKey( 'reconciliation', $categories );
		$this->assertArrayHasKey( 'virtual', $categories );

		// Verify warehouse category contains the two warehouse types
		$this->assertCount( 2, $categories['warehouse'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, $categories['warehouse'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::WAREHOUSE_EVENT_PREPACK, $categories['warehouse'] );

		// Verify destination category
		$this->assertCount( 3, $categories['destination'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::WOO_FULFILLMENT, $categories['destination'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::SHOW_LIVE, $categories['destination'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::STITCHER, $categories['destination'] );

		// Verify reconciliation category
		$this->assertCount( 2, $categories['reconciliation'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::RETURN_RECONCILIATION, $categories['reconciliation'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::DAMAGE_HOLD, $categories['reconciliation'] );

		// Verify virtual category
		$this->assertCount( 3, $categories['virtual'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL, $categories['virtual'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::SHRINK_VIRTUAL, $categories['virtual'] );
		$this->assertContains( AIMS_Physical_Bucket_Types::PRODUCTION_VIRTUAL, $categories['virtual'] );
	}

	/**
	 * Test is_virtual() correctly identifies virtual bucket types
	 */
	public function testIsVirtualIdentifiesLedgerBuckets(): void {
		// Virtual ledger buckets
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_virtual( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL ) );
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_virtual( AIMS_Physical_Bucket_Types::SHRINK_VIRTUAL ) );
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_virtual( AIMS_Physical_Bucket_Types::PRODUCTION_VIRTUAL ) );

		// Physical buckets should return false
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_virtual( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_virtual( AIMS_Physical_Bucket_Types::STITCHER ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_virtual( AIMS_Physical_Bucket_Types::SHOW_LIVE ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_virtual( AIMS_Physical_Bucket_Types::WOO_FULFILLMENT ) );
	}

	/**
	 * Test allowed_statuses_for_type() returns type-specific statuses
	 */
	public function testAllowedStatusesForTypeReturnsTypeSpecificValues(): void {
		// Virtual buckets always have 'available'
		$virtual_statuses = AIMS_Physical_Bucket_Types::allowed_statuses_for_type( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL );
		$this->assertCount( 1, $virtual_statuses );
		$this->assertContains( 'available', $virtual_statuses );

		// Destination buckets have their own set
		$destination_statuses = AIMS_Physical_Bucket_Types::allowed_statuses_for_type( AIMS_Physical_Bucket_Types::WOO_FULFILLMENT );
		$this->assertContains( 'available', $destination_statuses );
		$this->assertContains( 'in_transit', $destination_statuses );
		$this->assertContains( 'in_use', $destination_statuses );
		$this->assertContains( 'returned', $destination_statuses );

		// Warehouse and reconciliation buckets
		$warehouse_statuses = AIMS_Physical_Bucket_Types::allowed_statuses_for_type( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK );
		$this->assertContains( 'available', $warehouse_statuses );
		$this->assertContains( 'staging', $warehouse_statuses );
		$this->assertContains( 'in_transit', $warehouse_statuses );

		$damage_statuses = AIMS_Physical_Bucket_Types::allowed_statuses_for_type( AIMS_Physical_Bucket_Types::DAMAGE_HOLD );
		$this->assertContains( 'available', $damage_statuses );
		$this->assertContains( 'staging', $damage_statuses );
	}

	/**
	 * Test is_valid_status_for_type() validates status-type combinations
	 */
	public function testIsValidStatusForTypeValidatesCombinations(): void {
		// Valid combinations
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_valid_status_for_type( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, 'available' ) );
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_valid_status_for_type( AIMS_Physical_Bucket_Types::WOO_FULFILLMENT, 'in_transit' ) );
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_valid_status_for_type( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL, 'available' ) );

		// Invalid combinations
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_valid_status_for_type( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL, 'staging' ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_valid_status_for_type( AIMS_Physical_Bucket_Types::WOO_FULFILLMENT, 'staging' ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_valid_status_for_type( 'invalid_type', 'available' ) );
	}

	/**
	 * Test primary_warehouse() returns correct type
	 */
	public function testPrimaryWarehouseReturnsWarehouseStockType(): void {
		$this->assertEquals( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, AIMS_Physical_Bucket_Types::primary_warehouse() );
	}

	/**
	 * Test is_warehouse_type() identifies both warehouse buckets
	 */
	public function testIsWarehouseTypeIdentifiesWarehouseBuckets(): void {
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_warehouse_type( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK ) );
		$this->assertTrue( AIMS_Physical_Bucket_Types::is_warehouse_type( AIMS_Physical_Bucket_Types::WAREHOUSE_EVENT_PREPACK ) );

		// Non-warehouse buckets should return false
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_warehouse_type( AIMS_Physical_Bucket_Types::STITCHER ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_warehouse_type( AIMS_Physical_Bucket_Types::SHOW_LIVE ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_warehouse_type( AIMS_Physical_Bucket_Types::WOO_FULFILLMENT ) );
		$this->assertFalse( AIMS_Physical_Bucket_Types::is_warehouse_type( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL ) );
	}

	/**
	 * Test that all types in allowed() are correctly validated
	 */
	public function testAllAllowedTypesPassValidation(): void {
		foreach ( AIMS_Physical_Bucket_Types::allowed() as $type ) {
			$this->assertTrue( AIMS_Physical_Bucket_Types::is_allowed( $type ) );
			$this->assertFalse( empty( AIMS_Physical_Bucket_Types::description( $type ) ) );
		}
	}

	/**
	 * Test operational flow alignment (origin → warehouse → destinations → return)
	 */
	public function testOperationalFlowTypes(): void {
		// Warehouse sourcing (where allocations originate)
		$warehouse_types = array( AIMS_Physical_Bucket_Types::WAREHOUSE_STOCK, AIMS_Physical_Bucket_Types::WAREHOUSE_EVENT_PREPACK );
		foreach ( $warehouse_types as $type ) {
			$this->assertTrue( AIMS_Physical_Bucket_Types::is_warehouse_type( $type ) );
		}

		// Destination types (where inventory is allocated to)
		$destinations = array(
			AIMS_Physical_Bucket_Types::WOO_FULFILLMENT,
			AIMS_Physical_Bucket_Types::SHOW_LIVE,
			AIMS_Physical_Bucket_Types::STITCHER,
		);
		$categories = AIMS_Physical_Bucket_Types::by_category();
		foreach ( $destinations as $type ) {
			$this->assertContains( $type, $categories['destination'] );
		}

		// Return/reconciliation types (where items come back to)
		$returns = array( AIMS_Physical_Bucket_Types::RETURN_RECONCILIATION, AIMS_Physical_Bucket_Types::DAMAGE_HOLD );
		foreach ( $returns as $type ) {
			$this->assertContains( $type, $categories['reconciliation'] );
		}

		// Virtual ledger (consumed/shrink tracking)
		$virtual = array( AIMS_Physical_Bucket_Types::CONSUMED_VIRTUAL, AIMS_Physical_Bucket_Types::SHRINK_VIRTUAL );
		foreach ( $virtual as $type ) {
			$this->assertTrue( AIMS_Physical_Bucket_Types::is_virtual( $type ) );
		}
	}
}
