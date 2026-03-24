<?php

declare( strict_types=1 );

namespace AIMS\Tests\Support;

final class FakeWpdb {
	public string $prefix;
	public int $insert_id = 0;
	public string $last_query = '';
	public array $last_prepare_args = array();
	public array $inserted = array();
	public array $updated = array();
	public array $query_log = array();
	private array $results_queue = array();
	private array $row_queue = array();

	public function __construct( string $prefix = 'wp_' ) {
		$this->prefix = $prefix;
	}

	public function reset(): void {
		$this->insert_id        = 0;
		$this->last_query        = '';
		$this->last_prepare_args = array();
		$this->inserted          = array();
		$this->updated           = array();
		$this->query_log         = array();
		$this->results_queue     = array();
		$this->row_queue         = array();
	}

	public function queue_results( array $results ): void {
		$this->results_queue[] = $results;
	}

	public function queue_row( ?array $row ): void {
		$this->row_queue[] = $row;
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$this->last_query        = $query;
		$this->last_prepare_args = $args;

		if ( empty( $args ) ) {
			return $query;
		}

		$formatted = array();
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) ) {
				$formatted[] = $arg;
				continue;
			}

			if ( is_float( $arg ) ) {
				$formatted[] = $arg;
				continue;
			}

			$formatted[] = (string) $arg;
		}

		return vsprintf( $query, $formatted );
	}

	public function get_results( string $query, $output = null ): array {
		$this->last_query = $query;
		$this->query_log[] = array( 'type' => 'results', 'query' => $query, 'output' => $output );

		return array_shift( $this->results_queue ) ?: array();
	}

	public function get_row( string $query, $output = null ) {
		$this->last_query = $query;
		$this->query_log[] = array( 'type' => 'row', 'query' => $query, 'output' => $output );

		return array_shift( $this->row_queue );
	}

	public function insert( string $table, array $data ): bool {
		$this->insert_id++;
		$this->inserted[] = array(
			'table' => $table,
			'data'  => $data,
		);
		return true;
	}

	public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ) {
		$this->updated[] = array(
			'table'  => $table,
			'data'   => $data,
			'where'  => $where,
			'format' => $format,
		);
		return true;
	}

	public function query( string $query ) {
		$this->last_query = $query;
		$this->query_log[] = array( 'type' => 'query', 'query' => $query );
		return 1;
	}
}
