<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Financial_Service {
	private $events;
	private $sales;
	private $expenses;
	private $assignments;
	private $costs;

	public function __construct(
		AIMS_Event_Repository $events,
		AIMS_Square_Sale_Repository $sales,
		AIMS_Event_Expense_Repository $expenses,
		AIMS_Vendor_Event_Assignment_Repository $assignments,
		AIMS_Product_Cost_Service $costs
	) {
		$this->events      = $events;
		$this->sales       = $sales;
		$this->expenses    = $expenses;
		$this->assignments = $assignments;
		$this->costs       = $costs;
	}

	public function recalculate_event( int $event_id ): array {
		$sales_rows        = $this->sales->get_for_event( $event_id );
		$assignments       = $this->assignments->get_for_event( $event_id );
		$expense_total     = $this->expenses->get_total_for_event( $event_id );
		$gross_sales_total = 0.0;
		$discount_total    = 0.0;
		$tip_total         = 0.0;
		$net_sales_total   = 0.0;
		$vendor_payout     = 0.0;
		$product_cost_total = 0.0;

		foreach ( $sales_rows as $sale ) {
			$gross_sales_total += (float) $sale['gross_amount'];
			$discount_total    += (float) $sale['discount_amount'];
			$tip_total         += (float) $sale['tip_amount'];
			$net_sales_total   += (float) $sale['net_amount'];
			$product_cost_total += $this->costs->resolve_unit_cost(
				(int) $sale['woo_product_id'],
				(int) $sale['vendor_id']
			) * (float) $sale['quantity'];
		}

		foreach ( $assignments as $assignment ) {
			$vendor_sales_total = $this->sales->get_net_total_for_event_vendor( $event_id, (int) $assignment['vendor_id'] );
			$vendor_payout     += $vendor_sales_total * ( (float) $assignment['commission_rate'] / 100 );
		}

		$profit_total = $net_sales_total - $expense_total - $vendor_payout - $product_cost_total;

		$this->events->update_financials(
			$event_id,
			array(
				'gross_sales_total'   => round( $gross_sales_total, 2 ),
				'discount_total'      => round( $discount_total, 2 ),
				'tip_total'           => round( $tip_total, 2 ),
				'net_sales_total'     => round( $net_sales_total, 2 ),
				'vendor_payout_total' => round( $vendor_payout, 2 ),
				'expense_total'       => round( $expense_total + $product_cost_total, 2 ),
				'profit_total'        => round( $profit_total, 2 ),
			)
		);

		return array(
			'gross_sales_total'   => round( $gross_sales_total, 2 ),
			'discount_total'      => round( $discount_total, 2 ),
			'tip_total'           => round( $tip_total, 2 ),
			'net_sales_total'     => round( $net_sales_total, 2 ),
			'vendor_payout_total' => round( $vendor_payout, 2 ),
			'expense_total'       => round( $expense_total + $product_cost_total, 2 ),
			'profit_total'        => round( $profit_total, 2 ),
			'product_cost_total'  => round( $product_cost_total, 2 ),
		);
	}

	public function recalculate_for_event_assignment( array $assignment ): ?array {
		$event_id = ! empty( $assignment['event_id'] ) ? (int) $assignment['event_id'] : 0;

		if ( $event_id <= 0 ) {
			return null;
		}

		return $this->recalculate_event( $event_id );
	}
}
