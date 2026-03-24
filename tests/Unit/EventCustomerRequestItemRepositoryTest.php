<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Customer_Request_Item_Repository;

final class EventCustomerRequestItemRepositoryTest extends \AIMS\Tests\TestCase {
	public function testGetDemandSummaryForEventUsesQuantityRequestedAggregation(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'event_id'               => 10,
					'product_sku'            => 'SKU-1',
					'woo_product_id'         => 77,
					'total_quantity_requested' => '5.0000',
					'item_count'             => 2,
				),
			)
		);

		$repo = new AIMS_Event_Customer_Request_Item_Repository();
		$rows = $repo->get_demand_summary_for_event( 10 );

		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'SUM(quantity_requested) AS total_quantity_requested', $this->wpdb()->last_query );
		$this->assertStringContainsString( 'GROUP BY event_id, product_sku', $this->wpdb()->last_query );
		$this->assertSame( 'SKU-1', $rows[0]['product_sku'] );
		$this->assertSame( '5.0000', $rows[0]['total_quantity_requested'] );
	}
}
