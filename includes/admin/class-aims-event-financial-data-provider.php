<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Financial_Data_Provider {
	private $events;
	private $financials;
	private $assignments;

	public function __construct(
		AIMS_Event_Repository $events = null,
		AIMS_Event_Financial_Service $financials = null,
		AIMS_Vendor_Event_Assignment_Repository $assignments = null
	) {
		$this->events      = $events ?: new AIMS_Event_Repository();
		$this->financials  = $financials ?: new AIMS_Event_Financial_Service(
			$this->events,
			new AIMS_Square_Sale_Repository(),
			new AIMS_Event_Expense_Repository(),
			$assignments ?: new AIMS_Vendor_Event_Assignment_Repository(),
			new AIMS_Product_Cost_Service()
		);
		$this->assignments = $assignments ?: new AIMS_Vendor_Event_Assignment_Repository();
	}

	public function get_event_financial_context( int $event_id ): array {
		$context = $this->events->get_financial_context( $event_id );

		return array_merge(
			$context,
			array(
				'eligible_vendor_count'   => $this->assignments->count_authorized_for_event( $event_id ),
				'requested_vendor_count'  => $this->assignments->count_requested_for_event( $event_id ),
				'assignment_model'        => $this->assignments->get_assignment_model_for_event( $event_id ),
			)
		);
	}

	public function get_event_financial_controls( int $event_id ): array {
		$context = $this->get_event_financial_context( $event_id );

		return array(
			'event_id'                => (int) ( $context['event_id'] ?? $event_id ),
			'commission_cap_rate'     => (float) ( $context['commission_cap_rate'] ?? 30 ),
			'commission_split_policy' => (string) ( $context['commission_split_policy'] ?? 'proportional' ),
			'eligible_vendor_count'   => (int) ( $context['eligible_vendor_count'] ?? 0 ),
			'requested_vendor_count'  => (int) ( $context['requested_vendor_count'] ?? 0 ),
			'split_options'           => array( 'proportional', 'equal' ),
			'policy_state'            => ! empty( $context['assignment_model']['policy'] ) ? (string) $context['assignment_model']['policy'] : 'request_first',
			'capacity_status'         => ! empty( $context['assignment_model']['capacity_status'] ) ? (string) $context['assignment_model']['capacity_status'] : 'open_for_request',
		);
	}

	public function update_commission_policy( int $event_id, array $policy ): bool {
		return $this->events->update_commission_policy( $event_id, $policy );
	}

	public function get_payout_allocation_preview( int $event_id ): array {
		$preview = $this->financials->recalculate_event( $event_id );
		$context  = $this->get_event_financial_controls( $event_id );

		return array(
			'event_id'                 => $event_id,
			'commission_cap_rate'      => (float) ( $preview['commission_policy']['commission_cap_rate'] ?? $context['commission_cap_rate'] ?? 30 ),
			'commission_split_policy'  => (string) ( $preview['commission_policy']['commission_split_policy'] ?? $context['commission_split_policy'] ?? 'proportional' ),
			'eligible_vendor_count'    => (int) ( $context['eligible_vendor_count'] ?? 0 ),
			'requested_vendor_count'   => (int) ( $context['requested_vendor_count'] ?? 0 ),
			'eligible_assignment_count' => (int) ( $preview['eligible_assignment_count'] ?? 0 ),
			'commission_pool_total'    => (float) ( $preview['commission_pool_total'] ?? 0 ),
			'vendor_payout_total'      => (float) ( $preview['vendor_payout_total'] ?? 0 ),
			'profit_total'             => (float) ( $preview['profit_total'] ?? 0 ),
			'payout_allocations'       => ! empty( $preview['payout_allocations'] ) ? $preview['payout_allocations'] : array(),
			'commission_policy'        => ! empty( $preview['commission_policy'] ) ? $preview['commission_policy'] : array(),
			'generated_at'             => current_time( 'mysql' ),
		);
	}
}
