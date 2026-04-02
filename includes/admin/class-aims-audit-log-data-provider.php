<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Audit_Log_Data_Provider {
	private $audit_log_service;

	public function __construct( AIMS_Audit_Log_Service $audit_log_service = null ) {
		$this->audit_log_service = $audit_log_service ?: new AIMS_Audit_Log_Service();
	}

	public function get_page_model( array $filters = array() ): array {
		$limit   = max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) );
		$entries = $this->audit_log_service->get_rows( $filters, $limit );

		$rows = array();
		foreach ( $entries as $entry ) {
			$user      = function_exists( 'get_user_by' ) ? get_user_by( 'id', (int) ( $entry['user_id'] ?? 0 ) ) : null;
			$user_name = is_object( $user ) ? (string) ( $user->display_name ?? $user->user_login ?? '' ) : '';
			if ( '' === $user_name ) {
				$user_name = 'User #' . (int) ( $entry['user_id'] ?? 0 );
			}

			$rows[] = array(
				'ts'             => (string) ( $entry['ts'] ?? '' ),
				'user_id'        => (int) ( $entry['user_id'] ?? 0 ),
				'user_name'      => $user_name,
				'capability_key' => (string) ( $entry['capability_key'] ?? '' ),
				'action_key'     => (string) ( $entry['action_key'] ?? '' ),
				'reference_id'   => (string) ( $entry['reference_id'] ?? '' ),
				'status'         => (string) ( $entry['status'] ?? '' ),
				'surface'        => (string) ( $entry['surface'] ?? '' ),
			);
		}

		return array(
			'filters' => array(
				'user_id'    => (int) ( $filters['user_id'] ?? 0 ),
				'action_key' => (string) ( $filters['action_key'] ?? '' ),
				'status'     => (string) ( $filters['status'] ?? '' ),
				'search'     => (string) ( $filters['search'] ?? '' ),
				'limit'      => $limit,
			),
			'rows'    => $rows,
			'summary' => $this->build_summary( $rows ),
		);
	}

	private function build_summary( array $rows ): array {
		$summary = array(
			'total'     => count( $rows ),
			'successes' => 0,
			'failures'  => 0,
			'latest_ts' => '',
		);

		foreach ( $rows as $row ) {
			if ( 'success' === (string) ( $row['status'] ?? '' ) ) {
				++$summary['successes'];
			} else {
				++$summary['failures'];
			}

			if ( '' === $summary['latest_ts'] ) {
				$summary['latest_ts'] = (string) ( $row['ts'] ?? '' );
			}
		}

		return $summary;
	}
}
