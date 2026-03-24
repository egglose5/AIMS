<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Reconciliation_Types;

final class EventReconciliationTypesTest extends \AIMS\Tests\TestCase {
	public function testNormalizeSnapshotTypeFallsBackToPlanned(): void {
		$this->assertSame(
			AIMS_Event_Reconciliation_Types::SNAPSHOT_PLANNED,
			AIMS_Event_Reconciliation_Types::normalize_snapshot_type( 'not_real' )
		);
	}

	public function testNormalizeStatusFallsBackToPending(): void {
		$this->assertSame(
			AIMS_Event_Reconciliation_Types::STATUS_PENDING,
			AIMS_Event_Reconciliation_Types::normalize_status( 'not_real' )
		);
	}

	public function testNormalizeDiscrepancyTypeFallsBackToInventory(): void {
		$this->assertSame(
			AIMS_Event_Reconciliation_Types::DISCREPANCY_INVENTORY,
			AIMS_Event_Reconciliation_Types::normalize_discrepancy_type( 'not_real' )
		);
	}

	public function testNormalizeSeverityFallsBackToInfo(): void {
		$this->assertSame(
			AIMS_Event_Reconciliation_Types::SEVERITY_INFO,
			AIMS_Event_Reconciliation_Types::normalize_severity( 'not_real' )
		);
	}
}
