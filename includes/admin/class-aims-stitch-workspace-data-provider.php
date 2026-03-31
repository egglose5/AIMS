<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Workspace_Data_Provider {
	private $stitch_source;
	private $responsibility_auth;

	public function __construct( $stitch_source = null, AIMS_Responsibility_Authorization_Service $responsibility_auth = null ) {
		$this->stitch_source      = $stitch_source;
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function get_page_model( array $query = array() ): array {
		$jobs = $this->get_jobs();
		$requested_job_id = isset( $query['stitch_job_id'] ) ? max( 0, (int) $query['stitch_job_id'] ) : 0;
		$selected_job = $requested_job_id > 0 ? $this->get_job( $requested_job_id ) : null;

		if ( null === $selected_job && ! empty( $jobs ) ) {
			$selected_job = $jobs[0];
		}

		$selected_job_id   = (int) ( $selected_job['job_id'] ?? 0 );
		$lines             = $selected_job_id > 0 ? $this->get_job_lines( $selected_job_id ) : array();
		$assignment_context = $selected_job_id > 0 ? $this->get_assignment_context( $selected_job_id ) : array();
		$progress_summary   = $selected_job_id > 0 ? $this->get_progress_summary( $selected_job_id ) : array();

		return array(
			'can_manage'          => $this->can_manage_stitch_jobs(),
			'jobs'                => $jobs,
			'selected_job_id'     => $selected_job_id,
			'selected_job'        => is_array( $selected_job ) ? $selected_job : array(),
			'lines'               => $this->normalize_lines( $lines ),
			'assignment_context'   => $assignment_context,
			'progress_summary'    => $progress_summary,
			'workspace_summary'   => $this->build_workspace_summary( is_array( $selected_job ) ? $selected_job : array(), $lines, $progress_summary ),
			'selection_message'   => $this->get_selection_message( $jobs, $selected_job_id ),
			'empty_message'       => $this->get_empty_message(),
			'safe_actions_enabled'=> $this->supports_job_actions(),
			'workspace_url_base'  => admin_url( 'admin.php?page=' . AIMS_Stitch_Jobs_Data_Provider::PAGE_SLUG ),
		);
	}

	public function get_jobs(): array {
		$provider = new AIMS_Stitch_Jobs_Data_Provider( $this->stitch_source, $this->responsibility_auth );
		return $provider->get_jobs();
	}

	public function get_job( int $job_id ): ?array {
		$provider = new AIMS_Stitch_Jobs_Data_Provider( $this->stitch_source, $this->responsibility_auth );
		return $provider->get_job( $job_id );
	}

	private function get_job_lines( int $job_id ): array {
		$lines = $this->call_source( array( 'get_stitch_job_lines', 'get_job_lines' ), array( $job_id ) );

		if ( ! is_array( $lines ) ) {
			$lines = function_exists( 'apply_filters' ) ? apply_filters( 'aims_stitch_job_lines', array(), $job_id, $this ) : array();
		}

		return is_array( $lines ) ? $lines : array();
	}

	private function get_assignment_context( int $job_id ): array {
		$context = $this->call_source( array( 'get_stitch_job_assignment_context', 'get_assignment_context' ), array( $job_id ) );

		if ( ! is_array( $context ) ) {
			$context = function_exists( 'apply_filters' ) ? apply_filters( 'aims_stitch_job_assignment_context', array(), $job_id, $this ) : array();
		}

		return is_array( $context ) ? $context : array();
	}

	private function get_progress_summary( int $job_id ): array {
		$summary = $this->call_source( array( 'get_stitch_job_progress_summary', 'get_progress_summary' ), array( $job_id ) );

		if ( ! is_array( $summary ) ) {
			$summary = function_exists( 'apply_filters' ) ? apply_filters( 'aims_stitch_job_progress_summary', array(), $job_id, $this ) : array();
		}

		return is_array( $summary ) ? $summary : array();
	}

	private function get_selection_message( array $jobs, int $selected_job_id ): string {
		if ( $selected_job_id > 0 ) {
			return '';
		}

		if ( empty( $jobs ) ) {
			return $this->get_empty_message();
		}

		return 'Select a stitch job to review its lines, stitcher context, and progress.';
	}

	private function get_empty_message(): string {
		if ( ! $this->can_manage_stitch_jobs() ) {
			return 'You do not have access to producer stitching.';
		}

		if ( ! is_object( $this->resolve_source() ) ) {
			return 'Stitch job data is not connected yet.';
		}

		return 'No stitch jobs are currently available.';
	}

	private function normalize_lines( array $lines ): array {
		$normalized = array();

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$quantity  = (float) ( $line['quantity'] ?? $line['line_quantity'] ?? 0 );
			$completed = (float) ( $line['completed_quantity'] ?? $line['received_quantity'] ?? 0 );
			$remaining = max( 0, $quantity - $completed );

			$normalized[] = array(
				'line_id'         => (int) ( $line['line_id'] ?? $line['id'] ?? 0 ),
				'job_id'          => (int) ( $line['job_id'] ?? 0 ),
				'product_name'    => sanitize_text_field( (string) ( $line['product_name'] ?? $line['display_name'] ?? '' ) ),
				'product_sku'     => sanitize_text_field( (string) ( $line['product_sku'] ?? '' ) ),
				'quantity'        => $quantity,
				'completed'       => $completed,
				'remaining'       => $remaining,
				'status'          => sanitize_key( (string) ( $line['status'] ?? 'open' ) ),
				'stitcher_name'   => sanitize_text_field( (string) ( $line['stitcher_name'] ?? '' ) ),
				'notes'           => sanitize_textarea_field( (string) ( $line['notes'] ?? '' ) ),
				'assignment_state' => sanitize_key( (string) ( $line['assignment_state'] ?? '' ) ),
			);
		}

		return $normalized;
	}

	private function build_workspace_summary( array $selected_job, array $lines, array $progress_summary ): array {
		$total_quantity = 0.0;
		$completed_quantity = 0.0;
		$remaining_quantity = 0.0;

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$total_quantity += (float) ( $line['quantity'] ?? 0 );
			$completed_quantity += (float) ( $line['completed'] ?? 0 );
			$remaining_quantity += (float) ( $line['remaining'] ?? 0 );
		}

		$selected_total = (float) ( $selected_job['total_quantity'] ?? $total_quantity );

		$progress_percent = isset( $progress_summary['progress_percent'] )
			? (float) $progress_summary['progress_percent']
			: ( $selected_total > 0 ? round( ( $completed_quantity / max( 1, $selected_total ) ) * 100, 1 ) : 0 );

		return array(
			'line_count'         => count( $lines ),
			'total_quantity'     => $selected_total,
			'completed_quantity' => $completed_quantity,
			'remaining_quantity' => $remaining_quantity,
			'progress_percent'   => $progress_percent,
			'open_lines'         => count( array_filter( $lines, static function ( array $line ): bool {
				return 'completed' !== (string) ( $line['status'] ?? '' );
			} ) ),
			'stitcher_name'      => (string) ( $selected_job['stitcher_name'] ?? '' ),
			'job_status'         => (string) ( $selected_job['status'] ?? '' ),
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

	private function supports_job_actions(): bool {
		$source = $this->resolve_source();
		if ( ! is_object( $source ) ) {
			return false;
		}

		foreach ( array( 'save_job_note', 'update_job_note', 'mark_job_reviewed', 'mark_job_as_reviewed' ) as $method ) {
			if ( method_exists( $source, $method ) ) {
				return true;
			}
		}

		return false;
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
}
