<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class HeadlessExecutionMirrorServiceTest extends \AIMS\Tests\TestCase {
	public function testMirrorBuildsHeadlessMovePayloadFromEventExecutionContext(): void {
		TestState::set_product(
			901,
			new class() {
				public function get_sku(): string {
					return 'SKU-901';
				}
			}
		);

		TestState::set_remote_response(
			array(
				'code' => 201,
				'body' => wp_json_encode(
					array(
						'ok'   => true,
						'move' => array( 'movement_uuid' => 'mv-1' ),
					)
				),
			)
		);

		$service = new \AIMS_Headless_Execution_Mirror_Service(
			new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' )
		);

		$result = $service->mirror_event_execution_movement(
			array(
				'product_id'     => 901,
				'quantity_delta' => -2,
				'movement_type'  => 'event_load_out',
				'applied_by'     => 17,
			),
			array(
				'event_id' => 42,
				'show_id'  => '42',
				'bucket'   => array(
					'bucket_code'            => 'BIN-42',
					'current_location_code'  => 'WH-A',
					'home_location_code'     => 'WH-A',
				),
				'occurred_at' => '2026-04-02 10:00:00',
			)
		);

		$requests = TestState::get_remote_requests();

		$this->assertTrue( $result['attempted'] );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertStringEndsWith( '/move', $requests[0]['url'] );

		$payload = json_decode( (string) $requests[0]['args']['body'], true );
		$this->assertSame( 'SKU-901', $payload['sku'] );
		$this->assertSame( 'WH-A', $payload['from_location'] );
		$this->assertSame( 'event:42', $payload['to_location'] );
		$this->assertSame( 'bucket:BIN-42', $payload['from_endpoint'] );
		$this->assertSame( 'event:42', $payload['to_endpoint'] );
		$this->assertSame( 2, $payload['quantity'] );
	}

	public function testMirrorSkipsWhenSkuCannotBeResolved(): void {
		$service = new \AIMS_Headless_Execution_Mirror_Service(
			new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' )
		);

		$result = $service->mirror_event_execution_movement(
			array(
				'product_id'     => 999,
				'quantity_delta' => -1,
				'movement_type'  => 'event_load_out',
			),
			array(
				'event_id' => 42,
			)
		);

		$this->assertFalse( $result['attempted'] );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'missing_sku', $result['reason'] );
		$this->assertCount( 0, TestState::get_remote_requests() );
	}
}
