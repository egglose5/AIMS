<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Hot_Db_Health_Service {
	private const DEFAULT_CAPACITY_TARGET = 250000;

	/** @var object */
	private $wpdb;

	/** @var array<string, int> */
	private $thresholds;

	public function __construct( $database = null, array $thresholds = array() ) {
		global $wpdb;

		$this->wpdb       = $database ?: $wpdb;
		$this->thresholds = array_merge(
			array(
				'green'  => 100000,
				'yellow' => 250000,
				'target' => self::DEFAULT_CAPACITY_TARGET,
			),
			$thresholds
		);
	}

	public function get_dashboard_snapshot(): array {
		$counts = array(
			'square_sales'             => $this->count_table_rows( 'aims_square_sales' ),
			'bucket_inventory_moves'   => $this->count_table_rows( 'aims_bucket_inventory_movements' ),
			'fulfillment_allocations'  => $this->count_table_rows( 'aims_sale_fulfillment_allocations' ),
			'inventory_movements'      => $this->count_table_rows( 'aims_inventory_movements' ),
		);

		$total_hot_rows = array_sum( $counts );
		$target         = max( 1, (int) $this->thresholds['target'] );
		$usage_percent  = min( 100, (int) round( ( $total_hot_rows / $target ) * 100 ) );
		$square_lines   = max( 0, (int) $counts['square_sales'] );
		$order_guess    = (int) floor( $square_lines / 4 );
		$band           = $this->resolve_band( $total_hot_rows );

		return array(
			'band'                     => $band,
			'band_label'               => ucfirst( $band ),
			'band_color'               => $this->resolve_band_color( $band ),
			'total_hot_rows'           => $total_hot_rows,
			'usage_percent'            => $usage_percent,
			'capacity_target'          => $target,
			'thresholds'               => $this->thresholds,
			'counts'                   => $counts,
			'estimated_order_equivalent' => $order_guess,
			'message'                  => $this->build_message( $band, $total_hot_rows, $target ),
		);
	}

	private function count_table_rows( string $suffix ): int {
		$table = $this->wpdb->prefix . $suffix;
		$query = "SELECT COUNT(*) FROM {$table}";
		$count = $this->wpdb->get_var( $query );

		return max( 0, (int) $count );
	}

	private function resolve_band( int $total_hot_rows ): string {
		if ( $total_hot_rows >= (int) $this->thresholds['yellow'] ) {
			return 'red';
		}

		if ( $total_hot_rows >= (int) $this->thresholds['green'] ) {
			return 'yellow';
		}

		return 'green';
	}

	private function resolve_band_color( string $band ): string {
		switch ( $band ) {
			case 'red':
				return '#c62828';
			case 'yellow':
				return '#f9a825';
			default:
				return '#2e7d32';
		}
	}

	private function build_message( string $band, int $total_hot_rows, int $target ): string {
		switch ( $band ) {
			case 'red':
				return sprintf(
					'The rotor is carrying too much momentum for this implementation. Plan export, archive, or ERP migration work before %s hot rows starts turning into operator pain.',
					number_format( $total_hot_rows )
				);
			case 'yellow':
				return sprintf(
					'This stack is entering its caution band. AIMS is still doing its job, but line growth is starting to matter, so plan cleanup or migration before %s hot rows.',
					number_format( $target )
				);
			default:
				return 'Hot data is in the comfort zone. AIMS should stay light enough to track physical location and money-in or money-out truth without pretending to be a full ERP.';
		}
	}
}
