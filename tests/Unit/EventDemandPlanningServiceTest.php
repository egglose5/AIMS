<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Demand_Planning_Service;

final class EventDemandPlanningServiceTest extends \AIMS\Tests\TestCase {
	public function testSummarizeRequestsGroupsByEventAndSku(): void {
		$service = new AIMS_Event_Demand_Planning_Service();

		$summary = $service->summarize_requests(
			array(
				array(
					'event_id'           => 10,
					'event_name'         => 'Spring Show',
					'woo_product_id'     => 77,
					'product_sku'        => ' sku-1 ',
					'product_name'       => 'Demo Product',
					'quantity_requested' => 2,
					'request_status'     => 'auto_accepted',
					'item_status'        => 'planning_signal',
					'request_source'     => 'public_event_demand_form',
				),
				array(
					'event_id'           => 10,
					'event_name'         => 'Spring Show',
					'woo_product_id'     => 77,
					'product_sku'        => 'SKU-1',
					'product_name'       => 'Demo Product',
					'quantity_requested' => 3,
					'request_status'     => 'approved',
					'item_status'        => 'planning_signal',
					'request_source'     => 'public_event_demand_form',
				),
				array(
					'event_id'           => 20,
					'event_name'         => 'Autumn Show',
					'woo_product_id'     => 88,
					'product_sku'        => 'SKU-2',
					'product_name'       => 'Other Product',
					'quantity_requested' => 4,
					'request_status'     => 'auto_accepted',
					'item_status'        => 'planning_signal',
					'request_source'     => 'public_event_demand_form',
				),
			),
			10
		);

		$this->assertCount( 1, $summary );
		$this->assertSame( 10, $summary[0]['event_id'] );
		$this->assertSame( 'SKU-1', $summary[0]['product_sku'] );
		$this->assertSame( 5.0, $summary[0]['quantity_requested'] );
		$this->assertSame( 2, $summary[0]['item_count'] );
		$this->assertSame( 2, $summary[0]['approved_count'] );
		$this->assertSame( 5.0, $summary[0]['approved_quantity'] );
	}

	public function testSummarizeEventDemandMapsRepositorySummaryRows(): void {
		$repo = new class() {
			public function get_demand_summary_for_event( int $event_id ): array {
				return array(
					array(
						'event_id'               => $event_id,
						'woo_product_id'         => 77,
						'product_sku'            => 'SKU-1',
						'product_name'           => 'Demo Product',
						'total_quantity_requested' => '4.0000',
					),
				);
			}
		};

		$service = new AIMS_Event_Demand_Planning_Service( $repo );
		$summary = $service->summarize_event_demand( 10 );

		$this->assertCount( 1, $summary );
		$this->assertSame( 'SKU-1', $summary[0]['product_sku'] );
		$this->assertSame( 4.0, $summary[0]['quantity_requested'] );
		$this->assertSame( 4.0, $summary[0]['approved_quantity'] );
		$this->assertSame( array( 'public_event_demand_form' ), $summary[0]['sources'] );
	}
}
