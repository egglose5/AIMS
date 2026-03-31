<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Inventory_Movement_Events;

final class InventoryMovementEventsTest extends \AIMS\Tests\TestCase {
	public function testNewMovementTypesAreAllowed(): void {
		$new_types = array(
			AIMS_Inventory_Movement_Events::ORIGIN_INBOUND,
			AIMS_Inventory_Movement_Events::WAREHOUSE_TRANSFER,
			AIMS_Inventory_Movement_Events::ALLOCATE_TO_EVENT_PREPACK,
			AIMS_Inventory_Movement_Events::ALLOCATE_TO_WOO_FULFILLMENT,
			AIMS_Inventory_Movement_Events::ALLOCATE_TO_STITCHER,
			AIMS_Inventory_Movement_Events::SHOW_CONSUMPTION,
			AIMS_Inventory_Movement_Events::RETURN_FROM_EVENT,
			AIMS_Inventory_Movement_Events::RETURN_FROM_STITCHER,
			AIMS_Inventory_Movement_Events::ADJUSTMENT,
		);

		foreach ( $new_types as $type ) {
			$this->assertTrue( AIMS_Inventory_Movement_Events::is_allowed( $type ) );
		}
	}


	public function testOriginInboundHasCorrectReferences(): void {
		$allowed_refs = AIMS_Inventory_Movement_Events::allowed_references_for_movement(
			AIMS_Inventory_Movement_Events::ORIGIN_INBOUND
		);

		$this->assertContains( 'inbound_receipt', $allowed_refs );
		$this->assertContains( 'purchase_order', $allowed_refs );
		$this->assertContains( 'supplier_delivery', $allowed_refs );
	}

	public function testAllocateToEventPrepHasCorrectReferences(): void {
		$allowed_refs = AIMS_Inventory_Movement_Events::allowed_references_for_movement(
			AIMS_Inventory_Movement_Events::ALLOCATE_TO_EVENT_PREPACK
		);

		$this->assertContains( 'event_prepack_pickup', $allowed_refs );
		$this->assertContains( 'vendor_event_checkin', $allowed_refs );
	}

	public function testShowConsumptionHasCorrectReferences(): void {
		$allowed_refs = AIMS_Inventory_Movement_Events::allowed_references_for_movement(
			AIMS_Inventory_Movement_Events::SHOW_CONSUMPTION
		);

		$this->assertContains( 'square_sale_line', $allowed_refs );
		$this->assertContains( 'square_order', $allowed_refs );
		$this->assertContains( 'pos_transaction', $allowed_refs );
	}

	public function testReturnFromEventHasCorrectReferences(): void {
		$allowed_refs = AIMS_Inventory_Movement_Events::allowed_references_for_movement(
			AIMS_Inventory_Movement_Events::RETURN_FROM_EVENT
		);

		$this->assertContains( 'event_return', $allowed_refs );
		$this->assertContains( 'vendor_event_return', $allowed_refs );
	}

	public function testWarehouseTransferIncludesCustodyReferences(): void {
		$allowed_refs = AIMS_Inventory_Movement_Events::allowed_references_for_movement(
			AIMS_Inventory_Movement_Events::WAREHOUSE_TRANSFER
		);

		$this->assertContains( 'custody_transfer', $allowed_refs );
		$this->assertContains( 'custody_receipt', $allowed_refs );
		$this->assertContains( 'custody_return_dispatch', $allowed_refs );
		$this->assertContains( 'custody_return_receipt', $allowed_refs );
	}

}
