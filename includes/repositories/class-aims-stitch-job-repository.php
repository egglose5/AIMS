<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Job_Repository {
	public const STATUS_QUEUED          = 'queued';
	public const STATUS_RECEIVED        = 'received';
	public const STATUS_IN_PROGRESS     = 'in_progress';
	public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
	public const STATUS_CLOSED          = 'closed';

	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_stitch_jobs';
	}

	public function get_status_options(): array {
		return array(
			self::STATUS_QUEUED           => 'Queued',
			self::STATUS_RECEIVED         => 'Received',
			self::STATUS_IN_PROGRESS      => 'In progress',
			self::STATUS_READY_FOR_PICKUP => 'Ready for pickup',
			self::STATUS_CLOSED           => 'Closed',
		);
	}

	public function get_queue_rows( array $filters = array() ): array {
		global $wpdb;

		$limit = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 25;
		$status_filter = isset( $filters['status'] ) ? sanitize_key( $filters['status'] ) : '';

		$sql = 'SELECT * FROM ' . $this->get_table_name();
		$params = array();

		if ( '' !== $status_filter && isset( $this->get_status_options()[ $status_filter ] ) ) {
			$sql .= ' WHERE status = %s';
			$params[] = $status_filter;
		}

		$sql .= ' ORDER BY CASE status
				WHEN %s THEN 1
				WHEN %s THEN 2
				WHEN %s THEN 3
				WHEN %s THEN 4
				WHEN %s THEN 5
				ELSE 6
			END, CASE priority
				WHEN %s THEN 1
				WHEN %s THEN 2
				WHEN %s THEN 3
				ELSE 4
			END, due_at IS NULL, due_at ASC, id ASC LIMIT %d';

		$params = array_merge(
			$params,
			array(
				self::STATUS_QUEUED,
				self::STATUS_RECEIVED,
				self::STATUS_IN_PROGRESS,
				self::STATUS_READY_FOR_PICKUP,
				self::STATUS_CLOSED,
				'urgent',
				'high',
				'normal',
				$limit,
			)
		);

		$query = $wpdb->prepare( $sql, $params );
		$rows  = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function get_summary_counts(): array {
		$summary = array(
			'total'            => 0,
			'queued'           => 0,
			'received'         => 0,
			'in_progress'      => 0,
			'ready_for_pickup' => 0,
			'closed'           => 0,
			'open'             => 0,
		);

		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT status, COUNT(*) AS status_count FROM ' . $this->get_table_name() . ' GROUP BY status',
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$status = ! empty( $row['status'] ) ? (string) $row['status'] : self::STATUS_QUEUED;
			$count = (int) ( $row['status_count'] ?? 0 );
			$summary['total'] += $count;

			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ] += $count;
			}

			if ( self::STATUS_CLOSED !== $status ) {
				$summary['open'] += $count;
			}
		}

		return $summary;
	}

	public function find_by_id( int $job_id ): ?array {
		global $wpdb;

		if ( $job_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$job_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function update_status( int $job_id, string $status, array $data = array() ): bool {
		global $wpdb;

		$job = $this->find_by_id( $job_id );
		if ( empty( $job ) ) {
			return false;
		}

		$status = $this->normalize_status( $status );

		$update = array(
			'status'      => $status,
			'updated_at'  => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s' );

		if ( array_key_exists( 'assigned_user_id', $data ) ) {
			$update['assigned_user_id'] = (int) $data['assigned_user_id'];
			$formats[] = '%d';
		}

		if ( array_key_exists( 'priority', $data ) ) {
			$update['priority'] = $this->normalize_priority( (string) $data['priority'] );
			$formats[] = '%s';
		}

		if ( array_key_exists( 'due_at', $data ) ) {
			if ( '' === $data['due_at'] || null === $data['due_at'] ) {
				$update['due_at'] = null;
			} else {
				$update['due_at'] = sanitize_text_field( (string) $data['due_at'] );
			}
			$formats[] = '%s';
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$update['notes'] = sanitize_textarea_field( (string) $data['notes'] );
			$formats[] = '%s';
		}

		$updated = $wpdb->update(
			$this->get_table_name(),
			$update,
			array( 'id' => $job_id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	public function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( ! isset( $this->get_status_options()[ $status ] ) ) {
			return self::STATUS_QUEUED;
		}

		return $status;
	}

	public function normalize_priority( string $priority ): string {
		$priority = sanitize_key( $priority );
		$allowed = array( 'urgent', 'high', 'normal', 'low' );

		if ( ! in_array( $priority, $allowed, true ) ) {
			return 'normal';
		}

		return $priority;
	}
}
