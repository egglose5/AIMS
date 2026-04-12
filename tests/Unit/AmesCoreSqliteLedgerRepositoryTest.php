<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Headless\Storage\SqliteLedgerRepository;

final class AmesCoreSqliteLedgerRepositoryTest extends \AIMS\Tests\TestCase {
	public function testPrimaryModeFallsBackToShadowForFalseStringApprovalFlag(): void {
		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository(
			$sqlitePath,
			array(
				'binary_stream_mode' => 'primary',
				'binary_primary_approved' => 'false',
			)
		);

		$summary = $repo->binaryShadowSummary();
		$this->assertSame( 'shadow', $summary['stream_mode'] ?? '' );
		$this->assertFalse( $summary['primary_approved'] ?? true );
	}

	public function testPrimaryModeAllowsTruthyStringApprovalFlag(): void {
		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository(
			$sqlitePath,
			array(
				'binary_stream_mode' => 'primary',
				'binary_primary_approved' => 'yes',
			)
		);

		$summary = $repo->binaryShadowSummary();
		$this->assertSame( 'primary', $summary['stream_mode'] ?? '' );
		$this->assertTrue( $summary['primary_approved'] ?? false );
	}

	public function testPrimaryModeFallsBackToShadowWhenNotApproved(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository(
			$sqlitePath,
			array(
				'binary_stream_mode' => 'primary',
			)
		);
		$repo->initialize();

		$summary = $repo->binaryShadowSummary();
		$this->assertSame( 'shadow', $summary['stream_mode'] ?? '' );
	}

	public function testPrimaryModeIsKeptWhenApproved(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository(
			$sqlitePath,
			array(
				'binary_stream_mode' => 'primary',
				'binary_primary_approved' => true,
			)
		);
		$repo->initialize();

		$summary = $repo->binaryShadowSummary();
		$this->assertSame( 'primary', $summary['stream_mode'] ?? '' );
	}

	public function testRecordMoveWritesBinarySaleShadowAndPointerIndex(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository( $sqlitePath );
		$repo->initialize();

		$result = $repo->recordMove(
			array(
				'sku'               => 'PATCH-RED',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'user_id'           => 55,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:00:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1599,
				'tax_amount_cents'  => 123,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-1001',
			)
		);

		$this->assertSame( 'written', $result['binary_shadow']['status'] );
		$this->assertSame( 64, $result['binary_shadow']['packet_bytes'] );
		$this->assertFileExists( $result['binary_shadow']['segment_path'] );
		$this->assertSame( 64, filesize( $result['binary_shadow']['segment_path'] ) );

		$pointers = $repo->findBinaryPointers( 'square_sale', 'sale-1001' );

		$this->assertCount( 1, $pointers );
		$this->assertSame( 42, $pointers[0]['event_id'] );
		$this->assertSame( 1599, $pointers[0]['price_cents'] );
		$this->assertSame( 0, $pointers[0]['byte_offset'] );
	}

	public function testRecordMoveReusesBinaryReferenceDictionaryForRepeatedSaleReference(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository( $sqlitePath );
		$repo->initialize();

		$first = $repo->recordMove(
			array(
				'sku'               => 'PATCH-RED',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:05:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1599,
				'tax_amount_cents'  => 123,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-1002',
			)
		);

		$second = $repo->recordMove(
			array(
				'sku'               => 'PATCH-BLK',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:06:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1899,
				'tax_amount_cents'  => 146,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-1002',
			)
		);

		$this->assertSame( $first['binary_shadow']['reference_pointer_id'], $second['binary_shadow']['reference_pointer_id'] );

		$summary = $repo->binaryShadowSummary();
		$this->assertSame( 1, $summary['dictionary_count'] );
		$this->assertSame( 2, $summary['pointer_count'] );

		$pointers = $repo->findBinaryPointers( 'square_sale', 'sale-1002' );
		$this->assertCount( 2, $pointers );
		$this->assertSame( 64, $pointers[1]['byte_offset'] );
	}

	public function testInvalidBinaryPacketGoesToExceptionLaneWithoutBlockingMovement(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository( $sqlitePath );
		$repo->initialize();

		$result = $repo->recordMove(
			array(
				'sku'               => str_repeat( 'X', 33 ),
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:07:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1599,
				'tax_amount_cents'  => 123,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-bad-sku',
			)
		);

		$this->assertSame( 'rejected', $result['binary_shadow']['status'] );
		$this->assertSame( 'sku_exceeds_32_bytes', $result['binary_shadow']['reason'] );

		$summary = $repo->binaryShadowSummary();
		$this->assertSame( 1, $summary['exception_count'] );
		$this->assertSame( 0, $summary['pointer_count'] );
		$this->assertCount( 1, $repo->movementHistory( 'spring-show' ) );
	}

	public function testReconcileBinaryShadowSummarizesSaleCountsAndPacketTotals(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository( $sqlitePath );
		$repo->initialize();

		$repo->recordMove(
			array(
				'sku'               => 'PATCH-RED',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:08:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1599,
				'tax_amount_cents'  => 123,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-3001',
			)
		);

		$repo->recordMove(
			array(
				'sku'               => 'PATCH-BLK',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:09:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1899,
				'tax_amount_cents'  => 146,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-3002',
			)
		);

		$report = $repo->reconcileBinaryShadow( 'spring-show' );

		$this->assertSame( 2, $report['sale_movement_count'] );
		$this->assertSame( 2, $report['pointer_count'] );
		$this->assertSame( 2, $report['packet_count'] );
		$this->assertTrue( $report['count_match'] );
		$this->assertSame( 3498, $report['total_price_cents'] );
		$this->assertSame( 269, $report['total_tax_cents'] );
		$this->assertSame( 0, $report['drift_count'] );
		$this->assertSame( 3498, $report['event_totals'][42]['price_cents'] );
	}

	public function testRecordMoveCanBufferBinaryShadowUntilConfiguredThreshold(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository(
			$sqlitePath,
			array(
				'binary_stream_mode' => 'shadow',
				'binary_flush_packet_limit' => 2,
			)
		);
		$repo->initialize();

		$first = $repo->recordMove(
			array(
				'sku'               => 'PATCH-RED',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:10:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1599,
				'tax_amount_cents'  => 123,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-buffered-1',
			)
		);

		$this->assertSame( 'buffered', $first['binary_shadow']['status'] );
		$this->assertSame( 1, $repo->binaryShadowSummary()['pending_packet_count'] );

		$second = $repo->recordMove(
			array(
				'sku'               => 'PATCH-BLK',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:11:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1899,
				'tax_amount_cents'  => 146,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-buffered-2',
			)
		);

		$this->assertSame( 'written', $second['binary_shadow']['status'] );
		$this->assertSame( 0, $repo->binaryShadowSummary()['pending_packet_count'] );
		$this->assertSame( 2, $repo->binaryShadowSummary()['pointer_count'] );
	}

	public function testQueryBinaryShadowCanFilterByReferenceAndShow(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'The pdo_sqlite extension is required for this test.' );
		}

		$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-ledger-' . uniqid( '', true ) . '.sqlite';
		$repo       = new SqliteLedgerRepository( $sqlitePath );
		$repo->initialize();

		$repo->recordMove(
			array(
				'sku'               => 'PATCH-RED',
				'from_location'     => 'event-floor',
				'show_id'           => 'spring-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:12:00Z',
				'event_id'          => 42,
				'amount_paid_cents' => 1599,
				'tax_amount_cents'  => 123,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-query-1',
			)
		);

		$repo->recordMove(
			array(
				'sku'               => 'PATCH-BLK',
				'from_location'     => 'event-floor',
				'show_id'           => 'other-show',
				'quantity'          => 1,
				'movement_type'     => 'square_sale',
				'occurred_at'       => '2026-04-11T10:13:00Z',
				'event_id'          => 99,
				'amount_paid_cents' => 1899,
				'tax_amount_cents'  => 146,
				'reference_type'    => 'square_sale',
				'reference_id'      => 'sale-query-2',
			)
		);

		$matches = $repo->queryBinaryShadow(
			array(
				'show_id'       => 'spring-show',
				'reference_type'=> 'square_sale',
				'reference_id'  => 'sale-query-1',
			)
		);

		$this->assertCount( 1, $matches );
		$this->assertSame( 'spring-show', $matches[0]['show_id'] );
		$this->assertSame( 'sale-query-1', $matches[0]['reference_id'] );
		$this->assertSame( 'PATCH-RED', $matches[0]['sku'] );
		$this->assertSame( 1599, $matches[0]['price_cents'] );
	}
}
