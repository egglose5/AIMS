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
		$event             = $this->events->get_financial_context( $event_id );
		$sales_rows        = $this->sales->get_for_event( $event_id );
		$assignments       = $this->assignments->get_eligible_for_event( $event_id );
		$expense_total     = $this->expenses->get_total_for_event( $event_id );
		$commission_policy = array(
			'commission_cap_rate'     => max( 0.0, (float) ( $event['commission_cap_rate'] ?? 30 ) ),
			'commission_split_policy' => (string) ( $event['commission_split_policy'] ?? 'proportional' ),
		);
		$commission_cap    = (float) $commission_policy['commission_cap_rate'];
		$split_policy      = (string) $commission_policy['commission_split_policy'];
		$gross_sales_total = 0.0;
		$discount_total    = 0.0;
		$tip_total         = 0.0;
		$net_sales_total   = 0.0;
		$vendor_payout     = 0.0;
		$product_cost_total = 0.0;
		$vendor_payout_allocations = array();

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

		$commission_pool = round( max( 0.0, $net_sales_total ) * ( $commission_cap / 100 ), 2 );

		if ( ! empty( $assignments ) ) {
			$weights = $this->build_commission_weights( $assignments, $split_policy );
			$weight_total = array_sum( $weights );

			if ( $weight_total <= 0 ) {
				$weights = array_fill( 0, count( $assignments ), 1.0 );
				$weight_total = (float) count( $assignments );
			}

			$running_total = 0.0;

			foreach ( array_values( $assignments ) as $index => $assignment ) {
				$weight = isset( $weights[ $index ] ) ? (float) $weights[ $index ] : 0.0;
				$share  = $weight_total > 0 ? ( $commission_pool * ( $weight / $weight_total ) ) : 0.0;
				$share  = round( $share, 2 );
				$running_total += $share;

				$vendor_payout_allocations[] = array(
					'assignment_id'   => (int) $assignment['id'],
					'vendor_id'       => (int) $assignment['vendor_id'],
					'assignment_status'=> ! empty( $assignment['assignment_status'] ) ? (string) $assignment['assignment_status'] : '',
					'commission_rate' => (float) $assignment['commission_rate'],
					'weight'          => $weight,
					'payout'          => $share,
					'split_policy'    => $split_policy,
					'cap_rate'        => $commission_cap,
				);
			}

			if ( ! empty( $vendor_payout_allocations ) ) {
				$delta = round( $commission_pool - $running_total, 2 );
				$last_index = count( $vendor_payout_allocations ) - 1;
				$vendor_payout_allocations[ $last_index ]['payout'] = round( (float) $vendor_payout_allocations[ $last_index ]['payout'] + $delta, 2 );
				$vendor_payout = round( array_sum( array_column( $vendor_payout_allocations, 'payout' ) ), 2 );
			}
		} else {
			$vendor_payout = 0.0;
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
			'gross_sales_total'        => round( $gross_sales_total, 2 ),
			'discount_total'           => round( $discount_total, 2 ),
			'tip_total'                => round( $tip_total, 2 ),
			'net_sales_total'          => round( $net_sales_total, 2 ),
			'commission_pool_total'    => round( $commission_pool, 2 ),
			'vendor_payout_total'      => round( $vendor_payout, 2 ),
			'expense_total'            => round( $expense_total + $product_cost_total, 2 ),
			'profit_total'             => round( $profit_total, 2 ),
			'product_cost_total'       => round( $product_cost_total, 2 ),
			'commission_policy'        => $commission_policy,
			'payout_allocations'       => $vendor_payout_allocations,
			'vendor_payout_allocations'=> $vendor_payout_allocations,
			'eligible_assignment_count'=> count( $assignments ),
			'eligible_vendor_count'    => count( array_unique( array_map( static function ( $assignment ) {
				return (int) $assignment['vendor_id'];
			}, $assignments ) ) ),
		);
	}

	public function recalculate_for_event_assignment( array $assignment ): ?array {
		$event_id = ! empty( $assignment['event_id'] ) ? (int) $assignment['event_id'] : 0;

		if ( $event_id <= 0 ) {
			return null;
		}

		return $this->recalculate_event( $event_id );
	}

	private function build_commission_weights( array $assignments, string $split_policy ): array {
		$split_policy = sanitize_key( $split_policy );
		$weights = array();

		foreach ( $assignments as $assignment ) {
			if ( 'equal' === $split_policy ) {
				$weights[] = 1.0;
				continue;
			}

			$weight = (float) ( $assignment['commission_rate'] ?? 0 );
			$weights[] = max( 0.0, $weight );
		}

		return $weights;
	}
}
