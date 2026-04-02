<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class HeadlessApiClientTest extends \AIMS\Tests\TestCase {
	public function testBucketLookupUsesHeadlessGetRouteWithTokenHeader(): void {
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'ok'      => true,
						'buckets' => array(),
					)
				),
			)
		);

		$client   = new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' );
		$response = $client->get_buckets( array( 'show_id' => 'pax-east', 'square_location_id' => 'LOC-7' ) );
		$requests = TestState::get_remote_requests();

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'GET', $requests[0]['method'] );
		$this->assertStringContainsString( '/buckets', $requests[0]['url'] );
		$this->assertStringContainsString( 'show_id=pax-east', $requests[0]['url'] );
		$this->assertStringContainsString( 'square_location_id=LOC-7', $requests[0]['url'] );
		$this->assertSame( 'secret-token', $requests[0]['args']['headers']['X-Ames-Token'] );
	}

	public function testReceiveInventoryUsesHeadlessPostRouteWithJsonBody(): void {
		TestState::set_remote_response(
			array(
				'code' => 201,
				'body' => wp_json_encode(
					array(
						'ok'      => true,
						'receipt' => array( 'lot_uuid' => 'lot-1' ),
					)
				),
			)
		);

		$client = new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' );
		$client->receive_fifo(
			array(
				'bucket_code' => 'BIN-01',
				'sku'         => 'SKU-1',
				'quantity'    => 2,
				'unit_cost'   => 5.25,
			)
		);

		$requests = TestState::get_remote_requests();

		$this->assertCount( 1, $requests );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertStringEndsWith( '/fifo/receive', $requests[0]['url'] );
		$this->assertSame( 'application/json', $requests[0]['args']['headers']['Content-Type'] );

		$payload = json_decode( (string) $requests[0]['args']['body'], true );
		$this->assertSame( 'BIN-01', $payload['bucket_code'] );
		$this->assertSame( 'SKU-1', $payload['sku'] );
		$this->assertSame( 2, $payload['quantity'] );
	}

	public function testPickFifoUsesDedicatedRemoteRoute(): void {
		TestState::set_remote_response(
			array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'ok'   => true,
						'pick' => array( 'allocations' => array() ),
					)
				),
			)
		);

		$client = new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' );
		$client->pick_fifo(
			array(
				'sku'               => 'SKU-2',
				'quantity'          => 1,
				'square_location_id'=> 'LOC-9',
				'amount_paid'       => 19.99,
				'tax_amount'        => 1.20,
			)
		);

		$requests = TestState::get_remote_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertStringEndsWith( '/fifo/pick', $requests[0]['url'] );
		$payload = json_decode( (string) $requests[0]['args']['body'], true );
		$this->assertSame( 'LOC-9', $payload['square_location_id'] );
	}
}
