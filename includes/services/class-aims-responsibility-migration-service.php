<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Responsibility_Migration_Service {
	public const OPTION_SEED_VERSION = 'aims_responsibility_seed_version';
	public const SEED_VERSION = '1';
	private const TEMPLATE_KEY = 'legacy_seed_v1';

	private $assignment_repository;

	public function __construct( AIMS_Responsibility_Assignment_Repository $assignment_repository = null ) {
		$this->assignment_repository = $assignment_repository ?: new AIMS_Responsibility_Assignment_Repository();
	}

	public function maybe_seed_from_legacy(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( self::SEED_VERSION === (string) get_option( self::OPTION_SEED_VERSION, '' ) ) {
			return;
		}

		if ( ! $this->seed_from_legacy() ) {
			return;
		}

		update_option( self::OPTION_SEED_VERSION, self::SEED_VERSION, false );
		update_option( AIMS_Responsibility_Authorization_Service::OPTION_ENABLE, '1', false );
	}

	public function seed_from_legacy(): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$table_name = $this->assignment_repository->get_table_name();
		$vendor_access_rows = $wpdb->get_results( 'SELECT user_id, vendor_id, access_role FROM ' . $wpdb->prefix . 'aims_vendor_user_access', ARRAY_A );
		$hierarchy_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT supervisor_user_id, subordinate_user_id FROM ' . $wpdb->prefix . 'aims_supervisor_user_relationships WHERE status = %s',
				'active'
			),
			ARRAY_A
		);

		$vendor_access_rows = is_array( $vendor_access_rows ) ? $vendor_access_rows : array();
		$hierarchy_rows     = is_array( $hierarchy_rows ) ? $hierarchy_rows : array();

		$rows_to_insert = $this->build_seed_rows( $vendor_access_rows, $hierarchy_rows );

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table_name . ' WHERE template_key = %s',
				self::TEMPLATE_KEY
			)
		);

		if ( empty( $rows_to_insert ) ) {
			return true;
		}

		foreach ( $rows_to_insert as $row ) {
			$wpdb->insert(
				$table_name,
				$row
			);
		}

		return true;
	}

	private function build_seed_rows( array $vendor_access_rows, array $hierarchy_rows ): array {
		$now = current_time( 'mysql' );
		$rows = array();
		$dedupe = array();
		$subordinate_vendor_map = array();

		foreach ( $vendor_access_rows as $legacy_row ) {
			$user_id    = (int) ( $legacy_row['user_id'] ?? 0 );
			$vendor_id  = (int) ( $legacy_row['vendor_id'] ?? 0 );
			$access_role = sanitize_key( (string) ( $legacy_row['access_role'] ?? 'viewer' ) );

			if ( $user_id <= 0 || $vendor_id <= 0 ) {
				continue;
			}

			$subordinate_vendor_map[ $user_id ][] = array(
				'vendor_id'   => $vendor_id,
				'access_role' => $access_role,
			);

			$this->append_vendor_responsibility_rows(
				$rows,
				$dedupe,
				$user_id,
				$vendor_id,
				$access_role,
				'legacy direct vendor access',
				$now
			);
		}

		foreach ( $hierarchy_rows as $hierarchy_row ) {
			$supervisor_id  = (int) ( $hierarchy_row['supervisor_user_id'] ?? 0 );
			$subordinate_id = (int) ( $hierarchy_row['subordinate_user_id'] ?? 0 );

			if ( $supervisor_id <= 0 || $subordinate_id <= 0 || ! isset( $subordinate_vendor_map[ $subordinate_id ] ) ) {
				continue;
			}

			foreach ( $subordinate_vendor_map[ $subordinate_id ] as $vendor_row ) {
				$this->append_vendor_responsibility_rows(
					$rows,
					$dedupe,
					$supervisor_id,
					(int) ( $vendor_row['vendor_id'] ?? 0 ),
					(string) ( $vendor_row['access_role'] ?? 'viewer' ),
					'legacy supervisor inheritance',
					$now
				);
			}
		}

		return $rows;
	}

	private function append_vendor_responsibility_rows( array &$rows, array &$dedupe, int $user_id, int $vendor_id, string $access_role, string $source, string $now ): void {
		if ( $user_id <= 0 || $vendor_id <= 0 ) {
			return;
		}

		$access_role = sanitize_key( $access_role );
		$this->append_responsibility_row(
			$rows,
			$dedupe,
			$user_id,
			AIMS_Responsibility_Authorization_Service::RESP_EVENT_PLANNING_ACCESS,
			AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR,
			$vendor_id,
			$source,
			$now
		);

		if ( in_array( $access_role, array( 'editor', 'admin' ), true ) ) {
			$this->append_responsibility_row(
				$rows,
				$dedupe,
				$user_id,
				AIMS_Responsibility_Authorization_Service::RESP_EVENT_PLANNING_MUTATE,
				AIMS_Responsibility_Assignment_Repository::SCOPE_VENDOR,
				$vendor_id,
				$source,
				$now
			);
		}
	}

	private function append_responsibility_row( array &$rows, array &$dedupe, int $user_id, string $responsibility_key, string $scope_type, int $scope_ref_id, string $source, string $now ): void {
		$dedupe_key = implode(
			':',
			array(
				$user_id,
				sanitize_key( $responsibility_key ),
				sanitize_key( $scope_type ),
				$scope_ref_id,
			)
		);

		if ( isset( $dedupe[ $dedupe_key ] ) ) {
			return;
		}

		$dedupe[ $dedupe_key ] = true;
		$rows[] = array(
			'user_id'            => $user_id,
			'responsibility_key' => sanitize_key( $responsibility_key ),
			'scope_type'         => sanitize_key( $scope_type ),
			'scope_ref_id'       => $scope_ref_id,
			'template_key'       => self::TEMPLATE_KEY,
			'override_mode'      => 'allow',
			'is_active'          => 1,
			'granted_by'         => 0,
			'granted_at'         => $now,
			'revoked_by'         => 0,
			'revoked_at'         => null,
			'notes'              => $source,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
	}
}