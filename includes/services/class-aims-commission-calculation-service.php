<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Commission_Calculation_Service {
	public function calculate_commission( array $sale, array $assignment = array(), array $context = array() ): array {
		$sale_context       = $this->normalize_sale_context( $sale );
		$assignment_context = $this->normalize_assignment_context( $assignment );
		$commission_rate    = $this->resolve_commission_rate( $assignment_context, $context );
		$commissionable_net = $sale_context['net_amount'];
		$commission_amount   = round( $commissionable_net * ( $commission_rate / 100 ), 2 );

		return array(
			'eligible'              => $commission_rate > 0 && $commissionable_net > 0,
			'authoritative_source'   => 'square_net_amount',
			'sale_id'               => $sale_context['sale_id'],
			'event_id'              => $sale_context['event_id'],
			'vendor_id'             => $sale_context['vendor_id'],
			'commission_rate'       => $commission_rate,
			'commissionable_amount' => round( $commissionable_net, 2 ),
			'commission_amount'     => $commission_amount,
			'gross_amount'          => $sale_context['gross_amount'],
			'discount_amount'       => $sale_context['discount_amount'],
			'tip_amount'            => $sale_context['tip_amount'],
			'net_amount'            => $sale_context['net_amount'],
			'excluded_tip_amount'   => $sale_context['tip_amount'],
			'excluded_amount_total' => $sale_context['tip_amount'],
			'commission_basis'      => 'net_sales_only',
			'commission_currency'   => $sale_context['currency'],
			'commission_status'     => $commission_amount > 0 ? 'accrued' : 'not_accrued',
			'assignment_status'     => $assignment_context['assignment_status'],
			'assignment_window'     => $assignment_context['assignment_window'],
			'notes'                 => $this->build_notes( $sale_context, $assignment_context, $context ),
		);
	}

	public function summarize_sale_context( array $sale ): array {
		return $this->normalize_sale_context( $sale );
	}

	public function summarize_assignment_context( array $assignment ): array {
		return $this->normalize_assignment_context( $assignment );
	}

	public function resolve_commissionable_amount( array $sale ): float {
		$sale_context = $this->normalize_sale_context( $sale );

		return round( $sale_context['net_amount'], 2 );
	}

	private function normalize_sale_context( array $sale ): array {
		return array(
			'sale_id'              => (int) ( $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
			'event_id'             => (int) ( $sale['event_id'] ?? 0 ),
			'vendor_id'            => (int) ( $sale['vendor_id'] ?? 0 ),
			'square_order_id'      => (string) ( $sale['square_order_id'] ?? '' ),
			'square_line_item_uid'  => (string) ( $sale['square_line_item_uid'] ?? '' ),
			'gross_amount'         => $this->normalize_money( $sale['gross_amount'] ?? 0 ),
			'discount_amount'      => $this->normalize_money( $sale['discount_amount'] ?? 0 ),
			'tip_amount'           => $this->normalize_money( $sale['tip_amount'] ?? 0 ),
			'net_amount'           => $this->normalize_money( $sale['net_amount'] ?? 0 ),
			'currency'             => $this->normalize_currency( $sale['currency'] ?? $sale['money_currency'] ?? 'USD' ),
		);
	}

	private function normalize_assignment_context( array $assignment ): array {
		$window = array(
			'starts_at' => (string) ( $assignment['starts_at'] ?? $assignment['assignment_starts_at'] ?? '' ),
			'ends_at'   => (string) ( $assignment['ends_at'] ?? $assignment['assignment_ends_at'] ?? '' ),
		);

		return array(
			'assignment_id'     => (int) ( $assignment['id'] ?? $assignment['assignment_id'] ?? 0 ),
			'event_id'          => (int) ( $assignment['event_id'] ?? 0 ),
			'vendor_id'         => (int) ( $assignment['vendor_id'] ?? 0 ),
			'commission_rate'   => $this->normalize_rate( $assignment['commission_rate'] ?? 0 ),
			'assignment_status' => sanitize_key( $assignment['assignment_status'] ?? $assignment['status'] ?? 'assigned' ),
			'assignment_window' => $window,
		);
	}

	private function resolve_commission_rate( array $assignment_context, array $context ): float {
		if ( isset( $context['commission_rate'] ) ) {
			return $this->normalize_rate( $context['commission_rate'] );
		}

		if ( isset( $context['assignment'] ) && is_array( $context['assignment'] ) && isset( $context['assignment']['commission_rate'] ) ) {
			return $this->normalize_rate( $context['assignment']['commission_rate'] );
		}

		return $assignment_context['commission_rate'];
	}

	private function build_notes( array $sale_context, array $assignment_context, array $context ): string {
		$parts = array(
			'Square net sales are authoritative.',
			'Tips excluded from commission basis.',
		);

		if ( ! empty( $sale_context['square_order_id'] ) ) {
			$parts[] = 'order=' . $sale_context['square_order_id'];
		}

		if ( ! empty( $assignment_context['assignment_id'] ) ) {
			$parts[] = 'assignment=' . $assignment_context['assignment_id'];
		}

		if ( ! empty( $context['commission_scope'] ) ) {
			$parts[] = 'scope=' . sanitize_key( (string) $context['commission_scope'] );
		}

		return implode( '; ', $parts );
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
