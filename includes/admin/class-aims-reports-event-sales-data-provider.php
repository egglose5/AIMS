<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Reports_Event_Sales_Data_Provider {
	public function get_event_options(): array {
		$events  = ( new AIMS_Event_Repository() )->all();
		$options = array();

		foreach ( $events as $event ) {
			$options[] = array(
				'id'   => (int) ( $event['id'] ?? 0 ),
				'name' => (string) ( $event['event_name'] ?? '' ),
			);
		}

		return $options;
	}

	public function get_summary_totals( int $event_id = 0 ): array {
		$rows   = $this->get_rows( $event_id );
		$totals = array(
			'event_count'        => count( $rows ),
			'sales_count'        => 0,
			'gross_total'        => 0.0,
			'net_total'          => 0.0,
			'discount_total'     => 0.0,
			'tip_total'          => 0.0,
			'attribution_count'  => 0,
			'commission_total'   => 0.0,
			'payout_total'       => 0.0,
			'expense_total'      => 0.0,
			'profit_total'       => 0.0,
		);

		foreach ( $rows as $row ) {
			$totals['sales_count']       += (int) ( $row['sales_count'] ?? 0 );
			$totals['gross_total']       += (float) ( $row['gross_total'] ?? 0 );
			$totals['net_total']         += (float) ( $row['net_total'] ?? 0 );
			$totals['discount_total']    += (float) ( $row['discount_total'] ?? 0 );
			$totals['tip_total']         += (float) ( $row['tip_total'] ?? 0 );
			$totals['attribution_count'] += (int) ( $row['attribution_count'] ?? 0 );
			$totals['commission_total']  += (float) ( $row['commission_total'] ?? 0 );
			$totals['payout_total']      += (float) ( $row['payout_total'] ?? 0 );
			$totals['expense_total']     += (float) ( $row['expense_total'] ?? 0 );
			$totals['profit_total']      += (float) ( $row['profit_total'] ?? 0 );
		}

		return $totals;
	}

	public function get_rows( int $event_id = 0 ): array {
		global $wpdb;

		$where = '';
		$args  = array();
		if ( $event_id > 0 ) {
			$where = 'WHERE e.id = %d';
			$args[] = $event_id;
		}

		$sql = "
			SELECT
				e.id AS event_id,
				e.event_name,
				e.event_code,
				e.status,
				COALESCE(sales.sales_count, 0) AS sales_count,
				COALESCE(sales.gross_total, 0) AS gross_total,
				COALESCE(sales.net_total, 0) AS net_total,
				COALESCE(sales.discount_total, 0) AS discount_total,
				COALESCE(sales.tip_total, 0) AS tip_total,
				COALESCE(attr.attribution_count, 0) AS attribution_count,
				COALESCE(attr.commission_total, 0) AS commission_total,
				COALESCE(attr.payout_total, 0) AS payout_total,
				COALESCE(e.expense_total, 0) AS expense_total,
				COALESCE(e.profit_total, 0) AS profit_total
			FROM {$wpdb->prefix}aims_events e
			LEFT JOIN (
				SELECT
					event_id,
					COUNT(*) AS sales_count,
					SUM(gross_amount) AS gross_total,
					SUM(net_amount) AS net_total,
					SUM(discount_amount) AS discount_total,
					SUM(tip_amount) AS tip_total
				FROM {$wpdb->prefix}aims_square_sales
				GROUP BY event_id
			) sales ON sales.event_id = e.id
			LEFT JOIN (
				SELECT
					event_id,
					COUNT(*) AS attribution_count,
					SUM(commission_amount) AS commission_total,
					SUM(payout_amount) AS payout_total
				FROM {$wpdb->prefix}aims_vendor_sales_attribution
				GROUP BY event_id
			) attr ON attr.event_id = e.id
			{$where}
			ORDER BY e.start_date DESC, e.id DESC
			LIMIT 200
		";

		$rows = empty( $args )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}

