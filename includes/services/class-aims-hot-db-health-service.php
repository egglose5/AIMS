<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Hot_Db_Health_Service {
	private const DEFAULT_CAPACITY_TARGET = 250000;
	private const DEFAULT_WARN_THRESHOLD  = 80000;
	private const DEFAULT_CRITICAL_WARN_THRESHOLD = 90000;
	private const DEFAULT_ARCHIVE_THRESHOLD = 100000;

	/** @var object */
	private $wpdb;

	/** @var array<string, int> */
	private $thresholds;

	public function __construct( $database = null, array $thresholds = array() ) {
		global $wpdb;

		$this->wpdb       = $database ?: $wpdb;
		$this->thresholds = array_merge(
			array(
				'warn'    => self::DEFAULT_WARN_THRESHOLD,
				'critical'=> self::DEFAULT_CRITICAL_WARN_THRESHOLD,
				'archive' => self::DEFAULT_ARCHIVE_THRESHOLD,
				'green'   => 100000,
				'yellow'  => 250000,
				'target'  => self::DEFAULT_CAPACITY_TARGET,
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

		$total_hot_rows      = array_sum( $counts );
		$target              = max( 1, (int) $this->thresholds['target'] );
		$usage_percent       = min( 100, (int) round( ( $total_hot_rows / $target ) * 100 ) );
		$square_lines        = max( 0, (int) $counts['square_sales'] );
		$order_guess         = (int) floor( $square_lines / 4 );
		$band                = $this->resolve_band( $total_hot_rows );
		$warning_level       = $this->resolve_warning_level( $total_hot_rows );
		$should_auto_archive = 'archive' === $warning_level || 'red' === $band;

		return array(
			'band'                       => $band,
			'band_label'                 => ucfirst( $band ),
			'band_color'                 => $this->resolve_band_color( $band ),
			'warning_level'              => $warning_level,
			'should_warn'                => 'none' !== $warning_level,
			'should_auto_archive'        => $should_auto_archive,
			'archive_threshold'          => (int) ( $this->thresholds['archive'] ?? self::DEFAULT_ARCHIVE_THRESHOLD ),
			'warning_threshold'          => (int) ( $this->thresholds['warn'] ?? self::DEFAULT_WARN_THRESHOLD ),
			'total_hot_rows'             => $total_hot_rows,
			'usage_percent'              => $usage_percent,
			'capacity_target'            => $target,
			'thresholds'                 => $this->thresholds,
			'counts'                     => $counts,
			'estimated_order_equivalent' => $order_guess,
			'recommended_action'         => $should_auto_archive ? 'archive_now' : ( 'none' !== $warning_level ? 'warn_and_prepare_archive' : 'monitor' ),
			'message'                    => $this->build_message( $band, $warning_level, $total_hot_rows, $target ),
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

	private function resolve_warning_level( int $total_hot_rows ): string {
		if ( $total_hot_rows >= (int) ( $this->thresholds['archive'] ?? self::DEFAULT_ARCHIVE_THRESHOLD ) ) {
			return 'archive';
		}

		if ( $total_hot_rows >= (int) ( $this->thresholds['critical'] ?? self::DEFAULT_CRITICAL_WARN_THRESHOLD ) ) {
			return 'warning';
		}

		if ( $total_hot_rows >= (int) ( $this->thresholds['warn'] ?? self::DEFAULT_WARN_THRESHOLD ) ) {
			return 'info';
		}

		return 'none';
	}

	private function build_message( string $band, string $warning_level, int $total_hot_rows, int $target ): string {
		if ( 'red' === $band ) {
			return sprintf(
				'The rotor is carrying too much momentum for this implementation. Automatic archive rotation should already be running, and you should plan export, archive, or ERP migration work before %s hot rows starts turning into operator pain.',
				number_format( $total_hot_rows )
			);
		}

		if ( 'archive' === $warning_level ) {
			return sprintf(
				'This stack is entering its caution band and has crossed the automatic archive threshold. AIMS is still doing its job, but automatic archive rotation should run now before %s hot rows.',
				number_format( $target )
			);
		}

		if ( 'warning' === $warning_level || 'info' === $warning_level ) {
			return sprintf(
				'Hot data is approaching the automatic archive threshold. AIMS is still in its comfort zone, but plan cleanup or let the archive rotator pull cold history before %s hot rows.',
				number_format( (int) ( $this->thresholds['archive'] ?? self::DEFAULT_ARCHIVE_THRESHOLD ) )
			);
		}

		if ( 'yellow' === $band ) {
			return sprintf(
				'This stack is entering its caution band. AIMS is still doing its job, but line growth is starting to matter, so plan cleanup or migration before %s hot rows.',
				number_format( $target )
			);
		}

		return 'Hot data is in the comfort zone. AIMS should stay light enough to track physical location and money-in or money-out truth without pretending to be a full ERP.';
	}
}
