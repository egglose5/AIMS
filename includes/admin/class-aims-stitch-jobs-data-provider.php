<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Jobs_Data_Provider {
	public const PAGE_SLUG      = 'aims-stitch';
	public const WORKSPACE_SLUG = 'aims-stitch-workspace';

	private $stitch_source;
	private $responsibility_auth;

	public function __construct( $stitch_source = null, AIMS_Responsibility_Authorization_Service $responsibility_auth = null ) {
		$this->stitch_source      = $stitch_source;
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function get_page_model(): array {
		$jobs = $this->get_jobs();

		return array(
			'can_manage'         => $this->can_manage_stitch_jobs(),
			'jobs'               => $jobs,
			'summary'            => $this->build_summary( $jobs ),
			'stitcher_directory' => $this->build_stitcher_directory( $jobs ),
			'empty_message'      => $this->get_empty_message(),
			'workspace_url'      => $this->get_workspace_url_base(),
		);
	}

	public function get_jobs(): array {
		$jobs = $this->resolve_jobs_payload();

		usort(
			$jobs,
			static function ( array $left, array $right ): int {
				$left_status_rank  = AIMS_Stitch_Jobs_Data_Provider::status_rank( (string) ( $left['status'] ?? '' ) );
				$right_status_rank = AIMS_Stitch_Jobs_Data_Provider::status_rank( (string) ( $right['status'] ?? '' ) );

				if ( $left_status_rank !== $right_status_rank ) {
					return $left_status_rank <=> $right_status_rank;
				}

				$left_created  = (string) ( $left['created_at'] ?? '' );
				$right_created = (string) ( $right['created_at'] ?? '' );

				if ( '' !== $left_created || '' !== $right_created ) {
					return strcmp( $right_created, $left_created );
				}

				return (int) ( $right['job_id'] ?? 0 ) <=> (int) ( $left['job_id'] ?? 0 );
			}
		);

		return array_map( array( $this, 'build_job_row' ), $jobs );
	}

	public function get_job( int $job_id ): ?array {
		foreach ( $this->get_jobs() as $job ) {
			if ( (int) ( $job['job_id'] ?? 0 ) === $job_id ) {
				return $job;
			}
		}

		$payload = $this->call_source( array( 'get_stitch_job', 'get_job' ), array( $job_id ) );
		return is_array( $payload ) ? $this->build_job_row( $payload ) : null;
	}

	public function get_workspace_url( int $job_id ): string {
		return add_query_arg(
			array(
				'page'          => self::WORKSPACE_SLUG,
				'stitch_job_id' => $job_id,
			),
			admin_url( 'admin.php' )
		);
	}

	public function get_workspace_url_base(): string {
		return admin_url( 'admin.php?page=' . self::WORKSPACE_SLUG );
	}

	public function get_empty_message(): string {
		if ( ! $this->can_manage_stitch_jobs() ) {
			return 'You do not have access to producer stitching.';
		}

		if ( ! is_object( $this->resolve_source() ) ) {
			return 'Stitch job data is not connected yet.';
		}

		return 'No stitch jobs are currently available.';
	}

	private function resolve_jobs_payload(): array {
		$jobs = $this->call_source( array( 'list_stitch_jobs', 'get_stitch_jobs', 'all_stitch_jobs', 'get_jobs' ), array() );

		if ( ! is_array( $jobs ) ) {
			$jobs = function_exists( 'apply_filters' ) ? apply_filters( 'aims_stitch_jobs', array(), $this ) : array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $job ) {
						return is_array( $job ) ? $job : null;
					},
					is_array( $jobs ) ? $jobs : array()
				)
			)
		);
	}

	private function build_summary( array $jobs ): array {
		$summary = array(
			'total_jobs'     => count( $jobs ),
			'open_jobs'      => 0,
			'in_progress'    => 0,
			'completed_jobs' => 0,
			'unassigned'     => 0,
			'total_lines'    => 0,
			'total_quantity' => 0.0,
		);

		foreach ( $jobs as $job ) {
			$status = sanitize_key( (string) ( $job['status'] ?? '' ) );
			if ( 'open' === $status ) {
				++$summary['open_jobs'];
			} elseif ( 'in_progress' === $status ) {
				++$summary['in_progress'];
			} elseif ( 'completed' === $status || 'received' === $status ) {
				++$summary['completed_jobs'];
			}

			if ( '' === trim( (string) ( $job['stitcher_name'] ?? '' ) ) ) {
				++$summary['unassigned'];
			}

			$summary['total_lines']    += (int) ( $job['line_count'] ?? 0 );
			$summary['total_quantity'] += (float) ( $job['total_quantity'] ?? 0 );
		}

		return $summary;
	}

	private function build_stitcher_directory( array $jobs ): array {
		$directory = array();

		foreach ( $jobs as $job ) {
			$stitcher_name = trim( (string) ( $job['stitcher_name'] ?? '' ) );
			if ( '' === $stitcher_name ) {
				$stitcher_name = 'Unassigned';
			}

			if ( ! isset( $directory[ $stitcher_name ] ) ) {
				$directory[ $stitcher_name ] = array(
					'stitcher_name'  => $stitcher_name,
					'job_count'      => 0,
					'open_jobs'      => 0,
					'in_progress'    => 0,
					'completed'      => 0,
					'total_lines'    => 0,
					'total_quantity' => 0.0,
				);
			}

			++$directory[ $stitcher_name ]['job_count'];
			$directory[ $stitcher_name ]['total_lines'] += (int) ( $job['line_count'] ?? 0 );
			$directory[ $stitcher_name ]['total_quantity'] += (float) ( $job['total_quantity'] ?? 0 );

			$status = sanitize_key( (string) ( $job['status'] ?? '' ) );
			if ( 'open' === $status ) {
				++$directory[ $stitcher_name ]['open_jobs'];
			} elseif ( 'in_progress' === $status ) {
				++$directory[ $stitcher_name ]['in_progress'];
			} elseif ( 'completed' === $status || 'received' === $status ) {
				++$directory[ $stitcher_name ]['completed'];
			}
		}

		ksort( $directory, SORT_NATURAL | SORT_FLAG_CASE );

		return array_values( $directory );
	}

	private function build_job_row( array $job ): array {
		$lines            = is_array( $job['lines'] ?? null ) ? (array) $job['lines'] : array();
		$progress_summary  = is_array( $job['progress_summary'] ?? null ) ? (array) $job['progress_summary'] : array();
		$line_count       = (int) ( $job['line_count'] ?? count( $lines ) );
		$completed_count  = (int) ( $progress_summary['completed_line_count'] ?? $job['completed_line_count'] ?? 0 );
		$progress_percent = (float) ( $progress_summary['progress_percent'] ?? $job['progress_percent'] ?? 0 );

		if ( 0 === $progress_percent && $line_count > 0 ) {
			$progress_percent = round( ( $completed_count / max( 1, $line_count ) ) * 100, 1 );
		}

		return array(
			'job_id'               => (int) ( $job['job_id'] ?? $job['id'] ?? 0 ),
			'job_code'             => sanitize_text_field( (string) ( $job['job_code'] ?? $job['stitch_job_code'] ?? '' ) ),
			'job_name'             => sanitize_text_field( (string) ( $job['job_name'] ?? $job['stitch_job_name'] ?? $job['order_name'] ?? '' ) ),
			'status'               => sanitize_key( (string) ( $job['status'] ?? 'open' ) ),
			'stitcher_name'        => sanitize_text_field( (string) ( $job['stitcher_name'] ?? $job['assigned_stitcher_name'] ?? '' ) ),
			'line_count'           => $line_count,
			'total_quantity'       => (float) ( $job['total_quantity'] ?? $job['quantity_total'] ?? 0 ),
			'completed_line_count'  => $completed_count,
			'progress_percent'     => $progress_percent,
			'notes'                => sanitize_textarea_field( (string) ( $job['notes'] ?? '' ) ),
			'created_at'           => sanitize_text_field( (string) ( $job['created_at'] ?? '' ) ),
			'updated_at'           => sanitize_text_field( (string) ( $job['updated_at'] ?? '' ) ),
			'workspace_url'        => $this->get_workspace_url( (int) ( $job['job_id'] ?? $job['id'] ?? 0 ) ),
			'assignment_context'   => is_array( $job['assignment_context'] ?? null ) ? (array) $job['assignment_context'] : array(),
			'progress_summary'     => $progress_summary,
			'lines'                => $lines,
		);
	}

	private function can_manage_stitch_jobs(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return false;
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_STITCH )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_PRODUCTION )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE );
	}

	private function call_source( array $method_names, array $args ) {
		$source = $this->resolve_source();
		if ( ! is_object( $source ) ) {
			return null;
		}

		foreach ( $method_names as $method ) {
			if ( method_exists( $source, $method ) ) {
				return $source->{$method}( ...$args );
			}
		}

		return null;
	}

	private function resolve_source() {
		if ( is_object( $this->stitch_source ) ) {
			return $this->stitch_source;
		}

		if ( class_exists( 'AIMS_Stitch_Service' ) ) {
			return new AIMS_Stitch_Service();
		}

		return null;
	}

	public static function status_rank( string $status ): int {
		$status = sanitize_key( $status );

		if ( 'in_progress' === $status ) {
			return 0;
		}

		if ( 'open' === $status ) {
			return 1;
		}

		if ( 'completed' === $status || 'received' === $status ) {
			return 2;
		}

		return 3;
	}
}
