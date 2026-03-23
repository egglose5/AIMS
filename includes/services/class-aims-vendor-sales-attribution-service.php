<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Sales_Attribution_Service {
	private $attributions;
	private $commission;
	private $exceptions;

	public function __construct(
		AIMS_Vendor_Sales_Attribution_Repository $attributions = null,
		AIMS_Commission_Calculation_Service $commission = null,
		AIMS_Square_Exception_Service $exceptions = null
	) {
		$this->attributions = $attributions;
		$this->commission    = $commission ? $commission : new AIMS_Commission_Calculation_Service();
		$this->exceptions    = $exceptions;
	}

	public function attribute_sale( array $sale, array $assignment = array(), array $context = array() ): array {
		$attribution_record = $this->build_attribution_record( $sale, $assignment, $context );

		if ( null !== $this->attributions && method_exists( $this->attributions, 'save' ) ) {
			$attribution_record['attribution_id'] = (int) $this->attributions->save( $attribution_record );
		} else {
			$attribution_record['attribution_id'] = 0;
		}

		if ( 'needs_review' === $attribution_record['attribution_status'] && null !== $this->exceptions ) {
			$this->exceptions->flag_unmatched_sale( $sale, $context );
		}

		if ( 'ambiguous' === $attribution_record['attribution_status'] && null !== $this->exceptions ) {
			$this->exceptions->flag_ambiguous_sale( $sale, $context );
		}

		return $attribution_record;
	}

	public function attribute_sales( array $sales, array $assignment = array(), array $context = array() ): array {
		$results = array();

		foreach ( $sales as $sale ) {
			$results[] = $this->attribute_sale( $sale, $assignment, $context );
		}

		return $results;
	}

	public function build_attribution_record( array $sale, array $assignment = array(), array $context = array() ): array {
		$sale_context       = $this->normalize_sale_context( $sale );
		$assignment_context = $this->normalize_assignment_context( $assignment );
		$commission_result   = $this->commission->calculate_commission( $sale_context, $assignment_context, $context );
		$attribution_status  = $this->resolve_attribution_status( $sale_context, $assignment_context, $context );

		return array(
			'normalized_sale_id'      => (int) ( $sale['normalized_sale_id'] ?? $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
			'vendor_id'               => (int) ( $assignment_context['vendor_id'] ?? $sale_context['vendor_id'] ?? 0 ),
			'event_id'                => (int) ( $assignment_context['event_id'] ?? $sale_context['event_id'] ?? 0 ),
			'runtime_assignment_id'   => (int) ( $assignment_context['assignment_id'] ?? 0 ),
			'attribution_status'      => $attribution_status,
			'gross_sales'             => $sale_context['gross_amount'],
			'tax_amount'              => $this->normalize_money( $sale['tax_amount'] ?? 0 ),
			'discount_amount'         => $sale_context['discount_amount'],
			'tip_amount'              => $sale_context['tip_amount'],
			'refund_amount'           => $this->normalize_money( $sale['refund_amount'] ?? 0 ),
			'net_sales_authoritative' => $sale_context['net_amount'],
			'commissionable_sales'    => $commission_result['commissionable_amount'],
			'commission_amount'       => $commission_result['commission_amount'],
			'payout_amount'           => $this->resolve_payout_amount( $commission_result, $context ),
			'commission_rate'         => $commission_result['commission_rate'],
			'calculated_at'           => current_time( 'mysql' ),
			'source_sync_run_id'      => (int) ( $context['sync_run_id'] ?? 0 ),
			'attribution_notes'       => $commission_result['notes'],
			'created_at'              => current_time( 'mysql' ),
			'updated_at'              => current_time( 'mysql' ),
		);
	}

	public function resolve_attribution_status( array $sale_context, array $assignment_context, array $context = array() ): string {
		if ( ! empty( $context['attribution_status'] ) ) {
			return sanitize_key( (string) $context['attribution_status'] );
		}

		if ( empty( $assignment_context['event_id'] ) || empty( $assignment_context['vendor_id'] ) ) {
			return 'needs_review';
		}

		if ( ! empty( $context['assignment_status'] ) && 'ambiguous' === sanitize_key( (string) $context['assignment_status'] ) ) {
			return 'ambiguous';
		}

		return 'attributed';
	}

	public function summarize_sale_context( array $sale ): array {
		return $this->normalize_sale_context( $sale );
	}

	public function summarize_assignment_context( array $assignment ): array {
		return $this->normalize_assignment_context( $assignment );
	}

	private function normalize_sale_context( array $sale ): array {
		return array(
			'sale_id'             => (int) ( $sale['id'] ?? $sale['normalized_sale_id'] ?? $sale['square_sale_id'] ?? 0 ),
			'event_id'            => (int) ( $sale['event_id'] ?? 0 ),
			'vendor_id'           => (int) ( $sale['vendor_id'] ?? 0 ),
			'square_order_id'     => (string) ( $sale['square_order_id'] ?? '' ),
			'square_line_item_uid' => (string) ( $sale['square_line_item_uid'] ?? '' ),
			'gross_amount'        => $this->normalize_money( $sale['gross_amount'] ?? $sale['gross_sales'] ?? 0 ),
			'discount_amount'     => $this->normalize_money( $sale['discount_amount'] ?? 0 ),
			'tip_amount'          => $this->normalize_money( $sale['tip_amount'] ?? 0 ),
			'net_amount'          => $this->normalize_money( $sale['net_amount'] ?? $sale['net_sales'] ?? $sale['net_sales_authoritative'] ?? 0 ),
			'currency'            => $this->normalize_currency( $sale['currency'] ?? 'USD' ),
		);
	}

	private function normalize_assignment_context( array $assignment ): array {
		return array(
			'assignment_id'       => (int) ( $assignment['id'] ?? $assignment['runtime_assignment_id'] ?? 0 ),
			'event_id'            => (int) ( $assignment['event_id'] ?? 0 ),
			'vendor_id'           => (int) ( $assignment['vendor_id'] ?? 0 ),
			'commission_rate'     => $this->normalize_rate( $assignment['commission_rate'] ?? 0 ),
			'assignment_status'    => sanitize_key( $assignment['assignment_status'] ?? $assignment['status'] ?? 'assigned' ),
			'assignment_window'   => array(
				'starts_at' => (string) ( $assignment['starts_at'] ?? '' ),
				'ends_at'   => (string) ( $assignment['ends_at'] ?? '' ),
			),
		);
	}

	private function resolve_payout_amount( array $commission_result, array $context ): float {
		if ( isset( $context['payout_amount'] ) ) {
			return $this->normalize_money( $context['payout_amount'] );
		}

		return $this->normalize_money( $commission_result['commission_amount'] ?? 0 );
	}

	private function normalize_money( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		return round( (float) $value, 2 );
	}

	private function normalize_rate( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		return round( (float) $value, 4 );
	}

	private function normalize_currency( $value ): string {
		$value = strtoupper( sanitize_text_field( (string) $value ) );

		return '' !== $value ? $value : 'USD';
	}
}
