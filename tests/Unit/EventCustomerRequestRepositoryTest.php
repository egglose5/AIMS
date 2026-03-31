<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Customer_Request_Repository;

final class EventCustomerRequestRepositoryTest extends \AIMS\Tests\TestCase {
	public function testSaveNormalizesRequestStatusToPlanned(): void {
		$repo = new AIMS_Event_Customer_Request_Repository();

		$id = $repo->save(
			array(
				'event_id'       => 10,
				'wp_user_id'     => 42,
				'vendor_id'      => 5,
				'customer_id'    => 99,
				'customer_name'  => 'Jane Vendor',
				'customer_email' => 'JANE@EXAMPLE.COM',
				'customer_phone' => '317-555-0100',
				'status'         => 'approved',
				'request_status' => 'approved',
				'requested_at'   => '2026-03-01 10:00:00',
				'notes'          => '<strong>hello</strong>',
			)
		);

		$this->assertSame( 1, $id );
		$this->assertCount( 1, $this->wpdb()->inserted );
		$insert = $this->wpdb()->inserted[0];

		$this->assertSame( 'wp_aims_event_customer_requests', $insert['table'] );
		$this->assertSame( 'planned', $insert['data']['status'] );
		$this->assertSame( 'planned', $insert['data']['request_status'] );
		$this->assertSame( 42, $insert['data']['wp_user_id'] );
		$this->assertSame( 'Jane Vendor', $insert['data']['customer_name'] );
		$this->assertSame( 'jane@example.com', $insert['data']['customer_email'] );
	}
}
