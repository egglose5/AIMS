<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Reconciliation_Service {
	private $events;
	private $event_bucket_assignments;
	private $bucket_positions;
	private $bucket_movements;
	private $sales;
	private $expenses;
	private $reconciliations;
	private $discrepancies;

	public function __construct(
		AIMS_Event_Repository $events = null,
		AIMS_Event_Bucket_Assignment_Repository $event_bucket_assignments = null,
		AIMS_Bucket_Inventory_Position_Repository $bucket_positions = null,
		AIMS_Bucket_Inventory_Movement_Repository $bucket_movements = null,
		AIMS_Square_Sale_Repository $sales = null,
		AIMS_Event_Expense_Repository $expenses = null,
		AIMS_Event_Reconciliation_Repository $reconciliations = null,
		AIMS_Event_Reconciliation_Discrepancy_Repository $discrepancies = null
	) {
		$this->events                    = $events ?: new AIMS_Event_Repository();
		$this->event_bucket_assignments  = $event_bucket_assignments ?: new AIMS_Event_Bucket_Assignment_Repository();
		$this->bucket_positions          = $bucket_positions ?: new AIMS_Bucket_Inventory_Position_Repository();
		$this->bucket_movements          = $bucket_movements ?: new AIMS_Bucket_Inventory_Movement_Repository();
		$this->sales                     = $sales ?: new AIMS_Square_Sale_Repository();
		$this->expenses                  = $expenses ?: new AIMS_Event_Expense_Repository();
		$this->reconciliations           = $reconciliations ?: new AIMS_Event_Reconciliation_Repository();
		$this->discrepancies             = $discrepancies ?: new AIMS_Event_Reconciliation_Discrepancy_Repository();
	}

	public function capture_planned_snapshot( int $event_id, array $data = array() ): array {
		$event = $this->events->find( $event_id );
		if ( ! is_array( $event ) ) {
			return array(
				'success' => false,
				'message' => 'Event not found.',
			);
		}

		$planned_inventory_qty = array_key_exists( 'planned_inventory_qty', $data )
			? (float) $data['planned_inventory_qty']
			: $this->calculate_planned_inventory_qty( $event_id );

		$expected_sales_total = array_key_exists( 'expected_sales_total', $data )
			? (float) $data['expected_sales_total']
			: (float) ( $event['gross_sales_total'] ?? 0 );

		$expected_expense_total = array_key_exists( 'expected_expense_total', $data )
			? (float) $data['expected_expense_total']
			: (float) ( $event['expense_total'] ?? 0 );

		$reconciliation_id = $this->reconciliations->save(
			array(
				'event_id'               => $event_id,
				'reconciliation_type'    => AIMS_Event_Reconciliation_Types::SNAPSHOT_PLANNED,
				'snapshot_date'          => $data['snapshot_date'] ?? current_time( 'mysql' ),
				'planned_inventory_qty'  => $planned_inventory_qty,
				'actual_inventory_qty'   => 0,
				'expected_sales_total'   => $expected_sales_total,
				'actual_sales_total'     => 0,
				'expected_expense_total' => $expected_expense_total,
				'actual_expense_total'   => 0,
				'discrepancy_status'     => AIMS_Event_Reconciliation_Types::STATUS_PENDING,
				'discrepancy_count'      => 0,
				'notes'                  => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			)
		);

		return array(
			'success'           => $reconciliation_id > 0,
			'reconciliation_id' => $reconciliation_id,
			'planned_inventory' => round( $planned_inventory_qty, 4 ),
			'expected_sales'    => round( $expected_sales_total, 2 ),
			'expected_expenses' => round( $expected_expense_total, 2 ),
		);
	}

	public function capture_actual_snapshot( int $event_id, array $data = array() ): array {
		$event = $this->events->find( $event_id );
		if ( ! is_array( $event ) ) {
			return array(
				'success' => false,
				'message' => 'Event not found.',
			);
		}

		$actual_inventory_qty = array_key_exists( 'actual_inventory_qty', $data )
			? (float) $data['actual_inventory_qty']
			: $this->calculate_actual_inventory_qty( $event_id );

		$sales_totals = $this->calculate_sales_totals( $event_id );

		$actual_sales_total = array_key_exists( 'actual_sales_total', $data )
			? (float) $data['actual_sales_total']
			: (float) $sales_totals['gross_amount'];

		$actual_expense_total = array_key_exists( 'actual_expense_total', $data )
			? (float) $data['actual_expense_total']
			: (float) $this->expenses->get_total_for_event( $event_id );

		$reconciliation_id = $this->reconciliations->save(
			array(
				'event_id'               => $event_id,
				'reconciliation_type'    => AIMS_Event_Reconciliation_Types::SNAPSHOT_ACTUAL,
				'snapshot_date'          => $data['snapshot_date'] ?? current_time( 'mysql' ),
				'planned_inventory_qty'  => 0,
				'actual_inventory_qty'   => $actual_inventory_qty,
				'expected_sales_total'   => 0,
				'actual_sales_total'     => $actual_sales_total,
				'expected_expense_total' => 0,
				'actual_expense_total'   => $actual_expense_total,
				'discrepancy_status'     => AIMS_Event_Reconciliation_Types::STATUS_PENDING,
				'discrepancy_count'      => 0,
				'notes'                  => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			)
		);

		return array(
			'success'           => $reconciliation_id > 0,
			'reconciliation_id' => $reconciliation_id,
			'actual_inventory'  => round( $actual_inventory_qty, 4 ),
			'actual_sales'      => round( $actual_sales_total, 2 ),
			'actual_expenses'   => round( $actual_expense_total, 2 ),
		);
	}

	public function compute_differences( int $event_id, array $options = array() ): array {
		$planned = $this->resolve_snapshot(
			$event_id,
			AIMS_Event_Reconciliation_Types::SNAPSHOT_PLANNED,
			$options['planned_reconciliation_id'] ?? 0
		);
		$actual = $this->resolve_snapshot(
			$event_id,
			AIMS_Event_Reconciliation_Types::SNAPSHOT_ACTUAL,
			$options['actual_reconciliation_id'] ?? 0
		);

		if ( empty( $planned ) ) {
			$captured = $this->capture_planned_snapshot( $event_id );
			$planned  = ! empty( $captured['reconciliation_id'] ) ? $this->reconciliations->find( (int) $captured['reconciliation_id'] ) : null;
		}

		if ( empty( $actual ) ) {
			$captured = $this->capture_actual_snapshot( $event_id );
			$actual   = ! empty( $captured['reconciliation_id'] ) ? $this->reconciliations->find( (int) $captured['reconciliation_id'] ) : null;
		}

		if ( empty( $planned ) || empty( $actual ) ) {
			return array(
				'success' => false,
				'message' => 'Unable to load planned and actual snapshots for reconciliation.',
			);
		}

		$inventory_expected = (float) ( $planned['planned_inventory_qty'] ?? 0 );
		$inventory_actual   = (float) ( $actual['actual_inventory_qty'] ?? 0 );
		$sales_expected     = (float) ( $planned['expected_sales_total'] ?? 0 );
		$sales_actual       = (float) ( $actual['actual_sales_total'] ?? 0 );
		$expense_expected   = (float) ( $planned['expected_expense_total'] ?? 0 );
		$expense_actual     = (float) ( $actual['actual_expense_total'] ?? 0 );

		$discrepancies = array();
		$discrepancies[] = $this->build_discrepancy_row(
			AIMS_Event_Reconciliation_Types::DISCREPANCY_INVENTORY,
			'event_inventory',
			(string) $event_id,
			$inventory_expected,
			$inventory_actual
		);
		$discrepancies[] = $this->build_discrepancy_row(
			AIMS_Event_Reconciliation_Types::DISCREPANCY_FINANCIAL,
			'event_sales',
			(string) $event_id,
			$sales_expected,
			$sales_actual
		);
		$discrepancies[] = $this->build_discrepancy_row(
			AIMS_Event_Reconciliation_Types::DISCREPANCY_FINANCIAL,
			'event_expenses',
			(string) $event_id,
			$expense_expected,
			$expense_actual
		);

		$material = array_values(
			array_filter(
				$discrepancies,
				static function ( array $row ): bool {
					return abs( (float) $row['variance_amount'] ) > 0.0001;
				}
			)
		);

		$comparative_id = $this->reconciliations->save(
			array(
				'event_id'               => $event_id,
				'reconciliation_type'    => AIMS_Event_Reconciliation_Types::SNAPSHOT_COMPARATIVE,
				'snapshot_date'          => current_time( 'mysql' ),
				'planned_inventory_qty'  => $inventory_expected,
				'actual_inventory_qty'   => $inventory_actual,
				'expected_sales_total'   => $sales_expected,
				'actual_sales_total'     => $sales_actual,
				'expected_expense_total' => $expense_expected,
				'actual_expense_total'   => $expense_actual,
				'discrepancy_status'     => empty( $material ) ? AIMS_Event_Reconciliation_Types::STATUS_RECONCILED : AIMS_Event_Reconciliation_Types::STATUS_PENDING,
				'discrepancy_count'      => count( $material ),
				'notes'                  => 'Computed from latest planned and actual snapshots.',
			)
		);

		foreach ( $material as $row ) {
			$row['event_id']          = $event_id;
			$row['reconciliation_id'] = $comparative_id;
			$this->discrepancies->save( $row );
		}

		return array(
			'success'             => $comparative_id > 0,
			'reconciliation_id'   => $comparative_id,
			'discrepancy_count'   => count( $material ),
			'discrepancy_status'  => empty( $material ) ? AIMS_Event_Reconciliation_Types::STATUS_RECONCILED : AIMS_Event_Reconciliation_Types::STATUS_PENDING,
			'discrepancies'       => $material,
			'planned_snapshot_id' => (int) ( $planned['id'] ?? 0 ),
			'actual_snapshot_id'  => (int) ( $actual['id'] ?? 0 ),
		);
	}

	public function get_reconciliation_status( int $event_id ): array {
		$latest_planned     = $this->reconciliations->get_latest_for_event_type( $event_id, AIMS_Event_Reconciliation_Types::SNAPSHOT_PLANNED );
		$latest_actual      = $this->reconciliations->get_latest_for_event_type( $event_id, AIMS_Event_Reconciliation_Types::SNAPSHOT_ACTUAL );
		$latest_comparative = $this->reconciliations->get_latest_for_event_type( $event_id, AIMS_Event_Reconciliation_Types::SNAPSHOT_COMPARATIVE );
		$pending            = $this->discrepancies->get_pending_for_event( $event_id );

		return array(
			'event_id'            => $event_id,
			'latest_planned'      => $latest_planned,
			'latest_actual'       => $latest_actual,
			'latest_comparative'  => $latest_comparative,
			'pending_count'       => count( $pending ),
			'pending_discrepancy' => $pending,
		);
	}

	public function mark_reconciliation_complete( int $reconciliation_id, int $user_id = 0, string $notes = '' ): bool {
		$reconciliation = $this->reconciliations->find( $reconciliation_id );
		if ( ! is_array( $reconciliation ) ) {
			return false;
		}

		$event_id = (int) ( $reconciliation['event_id'] ?? 0 );
		if ( $event_id <= 0 ) {
			return false;
		}

		$pending = $this->discrepancies->get_pending_for_event( $event_id );
		if ( ! empty( $pending ) ) {
			return false;
		}

		return $this->reconciliations->mark_reconciled(
			$reconciliation_id,
			$user_id > 0 ? $user_id : (int) get_current_user_id(),
			$notes
		);
	}

	private function resolve_snapshot( int $event_id, string $snapshot_type, int $snapshot_id = 0 ): ?array {
		if ( $snapshot_id > 0 ) {
			$snapshot = $this->reconciliations->find( $snapshot_id );
			if ( is_array( $snapshot ) ) {
				return $snapshot;
			}
		}

		return $this->reconciliations->get_latest_for_event_type( $event_id, $snapshot_type );
	}

	private function calculate_planned_inventory_qty( int $event_id ): float {
		$assignments = $this->event_bucket_assignments->get_active_for_event( $event_id );
		$total       = 0.0;

		foreach ( $assignments as $assignment ) {
			$bucket_id = (int) ( $assignment['physical_bucket_id'] ?? 0 );
			if ( $bucket_id <= 0 ) {
				continue;
			}

			$positions = $this->bucket_positions->get_bucket_contents_summary( $bucket_id );
			foreach ( $positions as $position ) {
				$total += (float) ( $position['quantity'] ?? 0 );
			}
		}

		return $total;
	}

	private function calculate_actual_inventory_qty( int $event_id ): float {
		$movements = $this->bucket_movements->get_for_event( $event_id );
		$total     = 0.0;

		foreach ( $movements as $movement ) {
			$total += abs( (float) ( $movement['quantity_delta'] ?? 0 ) );
		}

		return $total;
	}

	private function calculate_sales_totals( int $event_id ): array {
		$sales = $this->sales->get_for_event( $event_id );
		$gross = 0.0;
		$net   = 0.0;

		foreach ( $sales as $sale ) {
			$gross += (float) ( $sale['gross_amount'] ?? 0 );
			$net   += (float) ( $sale['net_amount'] ?? 0 );
		}

		return array(
			'gross_amount' => $gross,
			'net_amount'   => $net,
		);
	}

	private function build_discrepancy_row( string $type, string $reference_type, string $reference_id, float $expected, float $actual ): array {
		$variance = $actual - $expected;
		$percent  = $expected !== 0.0 ? ( $variance / $expected ) * 100 : 0.0;

		return array(
			'discrepancy_type' => $type,
			'reference_type'   => $reference_type,
			'reference_id'     => $reference_id,
			'expected_value'   => $expected,
			'actual_value'     => $actual,
			'variance_amount'  => $variance,
			'variance_percent' => $percent,
			'severity'         => $this->classify_severity( $percent, $variance ),
		);
	}

	private function classify_severity( float $variance_percent, float $variance_amount ): string {
		if ( abs( $variance_percent ) >= 25.0 || abs( $variance_amount ) >= 100.0 ) {
			return AIMS_Event_Reconciliation_Types::SEVERITY_CRITICAL;
		}

		if ( abs( $variance_percent ) >= 10.0 || abs( $variance_amount ) >= 25.0 ) {
			return AIMS_Event_Reconciliation_Types::SEVERITY_WARNING;
		}

		return AIMS_Event_Reconciliation_Types::SEVERITY_INFO;
	}
}
