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
	private $attributions;

	public function __construct(
		AIMS_Event_Repository $events,
		AIMS_Square_Sale_Repository $sales,
		AIMS_Event_Expense_Repository $expenses,
		AIMS_Vendor_Event_Assignment_Repository $assignments,
		AIMS_Product_Cost_Service $costs,
		AIMS_Vendor_Sales_Attribution_Repository $attributions = null
	) {
		$this->events      = $events;
		$this->sales       = $sales;
		$this->expenses    = $expenses;
		$this->assignments = $assignments;
		$this->costs       = $costs;
		$this->attributions = $attributions;
	}

	public function recalculate_event( int $event_id ): array {
		$sales_rows              = $this->sales->get_for_event( $event_id );
		$assignments             = $this->assignments->get_for_event( $event_id );
		$attribution_rows        = $this->get_attribution_rows( $event_id );
		$expense_total           = $this->expenses->get_total_for_event( $event_id );
		$gross_sales_total       = 0.0;
		$discount_total          = 0.0;
		$tip_total               = 0.0;
		$net_sales_total         = 0.0;
		$vendor_payout           = 0.0;
		$product_cost_total      = 0.0;
		$attribution_by_vendor   = $this->index_attribution_rows_by_vendor( $attribution_rows );

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

		foreach ( $attribution_by_vendor as $vendor_id => $totals ) {
			$vendor_payout += (float) $totals['payout_total'];
		}

		foreach ( $assignments as $assignment ) {
			$vendor_id          = (int) $assignment['vendor_id'];
			$commission_rate    = (float) $assignment['commission_rate'] / 100;
			$vendor_sales_total = $this->sales->get_net_total_for_event_vendor( $event_id, $vendor_id );
			$attributed_net     = isset( $attribution_by_vendor[ $vendor_id ] ) ? (float) $attribution_by_vendor[ $vendor_id ]['net_total'] : 0.0;
			$uncovered_net      = max( 0.0, $vendor_sales_total - $attributed_net );

			if ( $uncovered_net > 0 ) {
				$vendor_payout += $uncovered_net * $commission_rate;
			}
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

	private function get_attribution_rows( int $event_id ): array {
		if ( null === $this->attributions ) {
			return array();
		}

		foreach ( array( 'get_for_event', 'get_by_event_id' ) as $method ) {
			if ( method_exists( $this->attributions, $method ) ) {
				$rows = $this->attributions->{$method}( $event_id );

				return is_array( $rows ) ? $rows : array();
			}
		}

		return array();
	}

	private function index_attribution_rows_by_vendor( array $rows ): array {
		$grouped = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$vendor_id = (int) ( $row['vendor_id'] ?? 0 );
			if ( $vendor_id <= 0 ) {
				continue;
			}

			if ( ! isset( $grouped[ $vendor_id ] ) ) {
				$grouped[ $vendor_id ] = array(
					'net_total'    => 0.0,
					'payout_total' => 0.0,
				);
			}

			$grouped[ $vendor_id ]['net_total']    += (float) ( $row['net_sales_authoritative'] ?? 0 );
			$grouped[ $vendor_id ]['payout_total'] += (float) ( $row['payout_amount'] ?? $row['commission_amount'] ?? 0 );
		}

		return $grouped;
	}
}
