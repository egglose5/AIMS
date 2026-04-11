<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Runs_Data_Provider {
	private $responsibility_auth;
	private $effects;

	public function __construct( AIMS_Responsibility_Authorization_Service $responsibility_auth = null, AIMS_Sync_Effect_Repository $effects = null ) {
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
		$this->effects             = $effects ?: new AIMS_Sync_Effect_Repository();
	}

	public function get_summary(): array {
		$runs = ( new AIMS_Sync_Run_Repository() )->get_for_source( 'square', 50 );

		$summary = array(
			'total_runs'             => 0,
			'total_processed_records' => 0,
			'total_error_count'      => 0,
			'last_sync_completed_at' => '',
			'last_sync_status'       => 'never',
		);

		if ( empty( $runs ) ) {
			return $summary;
		}

		$summary['total_runs'] = count( $runs );

		foreach ( $runs as $run ) {
			$summary['total_processed_records'] += (int) ( $run['processed_records'] ?? 0 );
			$summary['total_error_count'] += (int) ( $run['error_count'] ?? 0 );
		}

		$latest = is_array( $runs[0] ?? null ) ? $runs[0] : array();
		$summary['last_sync_completed_at'] = (string) ( $latest['completed_at'] ?? '' );
		$summary['last_sync_status'] = ! empty( $latest['completed_at'] )
			? ( ! empty( $latest['success'] ) ? 'success' : 'failed' )
			: 'running';

		return $summary;
	}

	public function get_rows(): array {
		$runs = ( new AIMS_Sync_Run_Repository() )->get_for_source( 'square', 50 );

		if ( empty( $runs ) ) {
			return array();
		}

		$rows = array();
		$can_replay = $this->can_replay();
		$can_undo   = $this->can_undo();

		foreach ( $runs as $run ) {
			$run_id = (int) ( $run['id'] ?? 0 );
			$projection = $this->get_projection_summary_for_run( $run_id );

			$rows[] = array(
				'run_id'            => $run_id,
				'source_system'     => (string) ( $run['source_system'] ?? '' ),
				'sync_watermark'    => (string) ( $run['sync_watermark'] ?? '' ),
				'status'            => ! empty( $run['completed_at'] ) ? ( ! empty( $run['success'] ) ? 'success' : 'failed' ) : 'running',
				'processed_records' => (string) ( $run['processed_records'] ?? '0' ),
				'error_count'       => (string) ( $run['error_count'] ?? '0' ),
				'woo_projection_status'  => (string) ( $projection['status'] ?? 'none' ),
				'woo_projection_summary' => (string) ( $projection['summary'] ?? 'No projection records' ),
				'woo_projection_details' => (string) ( $projection['details'] ?? 'No projection detail available' ),
				'completed_at'      => (string) ( $run['completed_at'] ?? '' ),
				'can_replay'        => $can_replay,
				'can_undo'          => $can_undo,
			);
		}

		return $rows;
	}

	public function get_projection_effect_details( int $run_id, int $limit = 25 ): array {
		if ( $run_id <= 0 || ! is_object( $this->effects ) || ! method_exists( $this->effects, 'get_for_run' ) ) {
			return array(
				'run_id'      => $run_id,
				'total_rows'  => 0,
				'rows'        => array(),
			);
		}

		$effects = (array) $this->effects->get_for_run( $run_id );
		$rows    = $this->projection_rows_for_effects( $effects );

		return array(
			'run_id'     => $run_id,
			'total_rows' => count( $rows ),
			'rows'       => array_slice( $rows, 0, max( 1, $limit ) ),
		);
	}

	private function can_replay(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $user_id > 0 && is_object( $this->responsibility_auth ) && $this->responsibility_auth->can_run_square_sync_replay( $user_id );
	}

	private function can_undo(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $user_id > 0 && is_object( $this->responsibility_auth ) && $this->responsibility_auth->can_run_square_sync_undo( $user_id );
	}

	private function get_projection_summary_for_run( int $run_id ): array {
		if ( $run_id <= 0 || ! is_object( $this->effects ) || ! method_exists( $this->effects, 'get_for_run' ) ) {
			return array(
				'status'  => 'none',
				'summary' => 'No projection records',
				'details' => 'No projection detail available',
			);
		}

		$effects = (array) $this->effects->get_for_run( $run_id );
		$projection_rows = $this->projection_rows_for_effects( $effects );
		$counts  = array(
			'projected' => 0,
			'skipped'   => 0,
			'linked'    => 0,
			'ready'     => 0,
		);
		$reasons = array();
		$skipped_reasons = array();
		$order_ids = array();

		foreach ( $projection_rows as $projection_row ) {
			$status = sanitize_key( (string) ( $projection_row['status'] ?? '' ) );
			$reason = sanitize_key( (string) ( $projection_row['reason'] ?? '' ) );
			$order_id = sanitize_text_field( (string) ( $projection_row['square_order_id'] ?? '' ) );
			if ( '' !== $order_id ) {
				$order_ids[ $order_id ] = true;
			}

			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}

			if ( '' !== $reason ) {
				$reasons[ $reason ] = (int) ( $reasons[ $reason ] ?? 0 ) + 1;

				if ( 'skipped' === $status ) {
					$skipped_reasons[ $reason ] = (int) ( $skipped_reasons[ $reason ] ?? 0 ) + 1;
				}
			}
		}

		$total = array_sum( $counts );
		if ( $total <= 0 ) {
			return array(
				'status'  => 'none',
				'summary' => 'No projection records',
				'details' => 'No projection detail available',
			);
		}

		$status = 'mixed';
		if ( $counts['projected'] > 0 && 0 === $counts['skipped'] && 0 === $counts['linked'] && 0 === $counts['ready'] ) {
			$status = 'projected';
		} elseif ( $counts['skipped'] > 0 && 0 === $counts['projected'] && 0 === $counts['linked'] && 0 === $counts['ready'] ) {
			$status = 'skipped';
		}

		arsort( $reasons );
		arsort( $skipped_reasons );
		$top_reason = (string) key( ! empty( $skipped_reasons ) ? $skipped_reasons : $reasons );
		$summary = sprintf(
			'Projected %d | Skipped %d | Linked %d',
			$counts['projected'],
			$counts['skipped'],
			$counts['linked']
		);

		if ( '' !== $top_reason ) {
			$summary .= ' | Top reason: ' . str_replace( '_', ' ', $top_reason );
		}

		$details_parts = array();

		if ( ! empty( $reasons ) ) {
			arsort( $reasons );
			arsort( $skipped_reasons );
			$top_reason_parts = array();

			foreach ( $skipped_reasons as $reason => $count ) {
				$top_reason_parts[] = str_replace( '_', ' ', (string) $reason ) . ' (' . (int) $count . ')';
				if ( count( $top_reason_parts ) >= 3 ) {
					break;
				}
			}

			if ( count( $top_reason_parts ) < 3 ) {
				foreach ( $reasons as $reason => $count ) {
					if ( isset( $skipped_reasons[ $reason ] ) ) {
						continue;
					}

					$top_reason_parts[] = str_replace( '_', ' ', (string) $reason ) . ' (' . (int) $count . ')';

					if ( count( $top_reason_parts ) >= 3 ) {
						break;
					}
				}
			}

			if ( ! empty( $top_reason_parts ) ) {
				$details_parts[] = 'Reasons: ' . implode( ', ', $top_reason_parts );
			}
		}

		$sample_orders = array_slice( array_keys( $order_ids ), 0, 3 );
		if ( ! empty( $sample_orders ) ) {
			$details_parts[] = 'Orders: ' . implode( ', ', $sample_orders );
		}

		return array(
			'status'  => $status,
			'summary' => $summary,
			'details' => ! empty( $details_parts ) ? implode( ' | ', $details_parts ) : 'No projection detail available',
		);
	}

	private function decode_effect_metadata( array $effect ): array {
		$metadata = $effect['metadata_json'] ?? array();

		if ( is_string( $metadata ) ) {
			$decoded = json_decode( $metadata, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $metadata ) ? $metadata : array();
	}

	private function projection_rows_for_effects( array $effects ): array {
		$rows = array();

		foreach ( $effects as $effect ) {
			$metadata = $this->decode_effect_metadata( is_array( $effect ) ? $effect : array() );
			$projection_entries = (array) ( $metadata['projection'] ?? array() );
			$root_order_id = sanitize_text_field( (string) ( $metadata['square_order_id'] ?? '' ) );
			$root_sale_id  = (int) ( $metadata['sale_id'] ?? 0 );
			$root_line_uid = sanitize_text_field( (string) ( $metadata['line_item_uid'] ?? '' ) );

			foreach ( $projection_entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$rows[] = array(
					'effect_id'        => (int) ( $effect['id'] ?? 0 ),
					'effect_type'      => sanitize_key( (string) ( $effect['effect_type'] ?? '' ) ),
					'target_table'     => sanitize_text_field( (string) ( $effect['target_table'] ?? '' ) ),
					'target_id'        => (int) ( $effect['target_id'] ?? 0 ),
					'created_at'       => sanitize_text_field( (string) ( $effect['created_at'] ?? '' ) ),
					'status'           => sanitize_key( (string) ( $entry['status'] ?? '' ) ),
					'reason'           => sanitize_key( (string) ( $entry['reason'] ?? '' ) ),
					'projection_mode'  => sanitize_key( (string) ( $entry['projection_mode'] ?? 'draft' ) ),
					'woo_order_id'     => (int) ( $entry['woo_order_id'] ?? 0 ),
					'square_order_id'  => sanitize_text_field( (string) ( $entry['square_order_id'] ?? $root_order_id ) ),
					'sale_id'          => (int) ( $entry['square_sale_id'] ?? $root_sale_id ),
					'line_item_uid'    => sanitize_text_field( (string) ( $entry['line_item_uid'] ?? $root_line_uid ) ),
				);
			}
		}

		return $rows;
	}
}
