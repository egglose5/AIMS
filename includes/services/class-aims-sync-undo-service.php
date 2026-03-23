<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Sync_Undo_Service {
	private $runs;
	private $actions;
	private $effects;

	public function __construct(
		AIMS_Sync_Run_Repository $runs = null,
		AIMS_Sync_Action_Repository $actions = null,
		AIMS_Sync_Effect_Repository $effects = null
	) {
		$this->runs    = $runs;
		$this->actions = $actions;
		$this->effects  = $effects;
	}

	public function plan_undo( array $run, array $actions, array $context = array() ): array {
		$normalized_run     = $this->normalize_run_context( $run );
		$normalized_actions = array_map( array( $this, 'normalize_action_context' ), $actions );
		$grouped_actions    = $this->group_actions_by_entity( $normalized_actions );
		$descriptions       = $this->describe_revertible_actions( $normalized_actions );

		return array(
			'run'                => $normalized_run,
			'context'            => $context,
			'total_actions'      => count( $normalized_actions ),
			'revertible_actions' => $descriptions['revertible_actions'],
			'blocked_actions'    => $descriptions['blocked_actions'],
			'grouped_by_entity'  => $grouped_actions,
			'entity_summary'     => $this->summarize_entities( $grouped_actions ),
			'can_undo'           => ! empty( $descriptions['revertible_actions'] ) && empty( $descriptions['blocked_actions'] ),
			'undo_notes'         => $this->build_undo_notes( $normalized_run, $descriptions ),
		);
	}

	public function group_actions_by_entity( array $actions ): array {
		$grouped = array();

		foreach ( $actions as $action ) {
			$entity_type = $action['entity_type'] ?: 'unknown';
			$entity_id   = (string) $action['entity_id'];
			$key         = $entity_type . ':' . $entity_id;

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'entity_type' => $entity_type,
					'entity_id'   => $action['entity_id'],
					'actions'     => array(),
				);
			}

			$grouped[ $key ]['actions'][] = $action;
		}

		return array_values( $grouped );
	}

	public function describe_revertible_actions( array $actions ): array {
		$revertible = array();
		$blocked    = array();

		foreach ( $actions as $action ) {
			$description = array(
				'action_id'         => $action['action_id'],
				'run_id'            => $action['run_id'],
				'external_record_id' => $action['external_record_id'],
				'action_type'       => $action['action_type'],
				'entity_type'       => $action['entity_type'],
				'entity_id'         => $action['entity_id'],
				'quantity_delta'    => $action['quantity_delta'],
				'status'            => $action['status'],
				'revertible'        => $this->is_revertible_action( $action ),
				'reason'            => $this->revert_reason( $action ),
			);

			if ( $description['revertible'] ) {
				$revertible[] = $description;
				continue;
			}

			$blocked[] = $description;
		}

		return array(
			'revertible_actions' => $revertible,
			'blocked_actions'    => $blocked,
		);
	}

	private function normalize_run_context( array $run ): array {
		return array(
			'run_id'            => (int) ( $run['id'] ?? $run['run_id'] ?? 0 ),
			'source_system'     => sanitize_key( $run['source_system'] ?? '' ),
			'started_at'        => (string) ( $run['started_at'] ?? '' ),
			'completed_at'      => (string) ( $run['completed_at'] ?? '' ),
			'sync_watermark'    => (string) ( $run['sync_watermark'] ?? '' ),
			'success'           => ! empty( $run['success'] ),
			'processed_records' => (int) ( $run['processed_records'] ?? 0 ),
			'skipped_records'   => (int) ( $run['skipped_records'] ?? 0 ),
			'error_count'       => (int) ( $run['error_count'] ?? 0 ),
			'message'           => (string) ( $run['message'] ?? '' ),
		);
	}

	private function normalize_action_context( array $action ): array {
		return array(
			'action_id'         => (int) ( $action['id'] ?? $action['action_id'] ?? 0 ),
			'run_id'            => (int) ( $action['run_id'] ?? 0 ),
			'external_record_id' => sanitize_text_field( $action['external_record_id'] ?? '' ),
			'action_type'       => sanitize_key( $action['action_type'] ?? '' ),
			'entity_type'       => sanitize_key( $action['entity_type'] ?? '' ),
			'entity_id'         => (int) ( $action['entity_id'] ?? 0 ),
			'status'            => sanitize_key( $action['status'] ?? '' ),
			'quantity_delta'    => $this->normalize_quantity_delta( $action['quantity_delta'] ?? 0 ),
			'message'           => (string) ( $action['message'] ?? '' ),
			'occurred_at'       => (string) ( $action['occurred_at'] ?? '' ),
		);
	}

	private function summarize_entities( array $grouped_actions ): array {
		$summary = array();

		foreach ( $grouped_actions as $group ) {
			$key = $group['entity_type'] . ':' . $group['entity_id'];
			$summary[ $key ] = array(
				'entity_type'  => $group['entity_type'],
				'entity_id'    => $group['entity_id'],
				'action_count' => count( $group['actions'] ),
				'revertible'   => $this->group_is_revertible( $group['actions'] ),
			);
		}

		return array_values( $summary );
	}

	private function build_undo_notes( array $run, array $descriptions ): string {
		$parts = array();

		if ( ! empty( $run['run_id'] ) ) {
			$parts[] = 'run=' . $run['run_id'];
		}

		$parts[] = 'revertible=' . count( $descriptions['revertible_actions'] );
		$parts[] = 'blocked=' . count( $descriptions['blocked_actions'] );
		$parts[] = 'replay_safe=required';

		return implode( '; ', $parts );
	}

	private function is_revertible_action( array $action ): bool {
		if ( 'success' !== $action['status'] ) {
			return false;
		}

		$revertible_types = array(
			'insert',
			'create',
			'update',
			'apply',
			'assign',
			'import',
			'attribution',
			'effect',
		);

		return in_array( $action['action_type'], $revertible_types, true );
	}

	private function group_is_revertible( array $actions ): bool {
		foreach ( $actions as $action ) {
			if ( ! $this->is_revertible_action( $action ) ) {
				return false;
			}
		}

		return ! empty( $actions );
	}

	private function revert_reason( array $action ): string {
		if ( 'success' !== $action['status'] ) {
			return 'action did not complete successfully';
		}

		if ( ! $this->is_revertible_action( $action ) ) {
			return 'action type is not marked as revertible';
		}

		return 'revertible once repository-backed compensating writes are wired in';
	}

	private function normalize_quantity_delta( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9\.\-]/', '', $value );
		}

		return round( (float) $value, 4 );
	}
}
