<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class AuditLogServiceTest extends \AIMS\Tests\TestCase {
	public function testRecordActionWritesJsonlEntryToUploadsBackedDirectory(): void {
		$upload_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-audit-upload-' . uniqid( '', true );
		TestState::set_upload_dir(
			array(
				'basedir' => $upload_root,
			)
		);
		TestState::set_current_user_id( 17 );
		TestState::set_current_time( '2026-04-02 10:15:00' );

		$service = new \AIMS_Audit_Log_Service();
		$result  = $service->record_action( \AIMS_Capabilities::CAP_MANAGE, 'movement_send', 'SKU-1' );

		$expected_path = $upload_root . DIRECTORY_SEPARATOR . 'aims' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . '2026-04-02.jsonl';

		$this->assertTrue( $result );
		$this->assertFileExists( $expected_path );

		$rows = $service->get_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 17, $rows[0]['user_id'] );
		$this->assertSame( \AIMS_Capabilities::CAP_MANAGE, $rows[0]['capability_key'] );
		$this->assertSame( 'movement_send', $rows[0]['action_key'] );
		$this->assertSame( 'SKU-1', $rows[0]['reference_id'] );
		$this->assertSame( 'success', $rows[0]['status'] );
	}

	public function testGetRowsCanFilterStructuredAuditEntries(): void {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-audit-filter-' . uniqid( '', true );
		$service   = new \AIMS_Audit_Log_Service( $directory );

		TestState::set_current_user_id( 21 );
		TestState::set_current_time( '2026-04-02 12:00:00' );
		$service->record_action( \AIMS_Capabilities::CAP_MANAGE, 'bucket_register', 'BIN-01', array( 'status' => 'success' ) );

		TestState::set_current_user_id( 33 );
		TestState::set_current_time( '2026-04-02 12:05:00' );
		$service->record_action( \AIMS_Capabilities::CAP_MANAGE_INVENTORY, 'fifo_pick', 'REQ-9', array( 'status' => 'failed' ) );

		$rows = $service->get_rows(
			array(
				'user_id' => 33,
				'status'  => 'failed',
				'search'  => 'req-9',
			),
			10
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 33, $rows[0]['user_id'] );
		$this->assertSame( 'fifo_pick', $rows[0]['action_key'] );
		$this->assertSame( 'REQ-9', $rows[0]['reference_id'] );
		$this->assertSame( 'failed', $rows[0]['status'] );
	}
}
