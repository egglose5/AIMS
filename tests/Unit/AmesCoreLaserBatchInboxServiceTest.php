<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Headless\Laser\LaserBatchInboxService;

final class AmesCoreLaserBatchInboxServiceTest extends \AIMS\Tests\TestCase {
	public function testAcceptBatchCreatesInboxJsonFileAndReturnsTargetMetadata(): void {
		$root    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-laser-' . uniqid( '', true );
		$service = new LaserBatchInboxService( $root );

		$result = $service->acceptBatch(
			array(
				'batch_id'      => 'laser-run-42',
				'stitch_job_id' => 991,
				'machine_id'    => 'laser-01',
				'items'         => array(
					array(
						'sku'      => 'PATCH-RED',
						'quantity' => 12,
					),
					array(
						'sku'      => 'PATCH-BLK',
						'quantity' => 8,
					),
				),
			),
			array(
				'received_from' => '172.18.0.10',
			)
		);

		$this->assertSame( 'laser-run-42', $result['batch_id'] );
		$this->assertSame( 'accepted', $result['status'] );
		$this->assertSame( 2, $result['line_count'] );
		$this->assertStringEndsWith( '.json', $result['target_path'] );
		$this->assertFileExists( $result['target_path'] );

		$decoded = json_decode( (string) file_get_contents( $result['target_path'] ), true );
		$this->assertSame( 'laser-run-42', $decoded['batch_id'] );
		$this->assertSame( 991, $decoded['payload']['stitch_job_id'] );
		$this->assertSame( '172.18.0.10', $decoded['received_from'] );
	}

	public function testAcceptBatchRejectsEmptyLaserPayload(): void {
		$service = new LaserBatchInboxService( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-laser-' . uniqid( '', true ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Laser batch payload must include items, operations, or commands.' );

		$service->acceptBatch(
			array(
				'batch_id' => 'empty-batch',
			)
		);
	}
}
