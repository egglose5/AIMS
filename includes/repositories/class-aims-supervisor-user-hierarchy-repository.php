<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Supervisor_User_Hierarchy_Repository {
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aims_supervisor_user_relationships';
	}

	public function all(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT * FROM ' . $this->get_table_name() . ' ORDER BY supervisor_user_id ASC, subordinate_user_id ASC, id ASC',
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function find( int $relationship_id ): ?array {
		global $wpdb;

		if ( $relationship_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE id = %d LIMIT 1',
				$relationship_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function save( array $data, int $relationship_id = 0 ): int {
		global $wpdb;

		$record = array(
			'supervisor_user_id' => (int) ( $data['supervisor_user_id'] ?? 0 ),
			'subordinate_user_id' => (int) ( $data['subordinate_user_id'] ?? 0 ),
			'hierarchy_level'    => max( 1, (int) ( $data['hierarchy_level'] ?? 1 ) ),
			'status'             => sanitize_key( (string) ( $data['status'] ?? 'active' ) ),
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( $record['supervisor_user_id'] <= 0 || $record['subordinate_user_id'] <= 0 ) {
			return 0;
		}

		if ( $relationship_id > 0 ) {
			$wpdb->update(
				$this->get_table_name(),
				$record,
				array( 'id' => $relationship_id ),
				array( '%d', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);

			return $relationship_id;
		}

		$record['created_at'] = current_time( 'mysql' );

		$wpdb->insert(
			$this->get_table_name(),
			$record
		);

		return (int) $wpdb->insert_id;
	}

	public function delete( int $relationship_id ): bool {
		global $wpdb;

		if ( $relationship_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $this->get_table_name() . ' WHERE id = %d',
				$relationship_id
			)
		);
	}

	public function get_direct_subordinates( int $supervisor_user_id ): array {
		global $wpdb;

		if ( $supervisor_user_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT subordinate_user_id FROM ' . $this->get_table_name() . ' WHERE supervisor_user_id = %d AND status = %s ORDER BY subordinate_user_id ASC',
				$supervisor_user_id,
				'active'
			),
			ARRAY_A
		);

		$subordinates = array();
		foreach ( (array) $rows as $row ) {
			$subordinate_id = (int) ( $row['subordinate_user_id'] ?? 0 );
			if ( $subordinate_id > 0 ) {
				$subordinates[] = $subordinate_id;
			}
		}

		return array_values( array_unique( $subordinates ) );
	}

	public function get_subordinates_for_supervisor( int $supervisor_user_id, int $max_depth = 5 ): array {
		$max_depth = max( 1, min( 20, $max_depth ) );

		if ( $supervisor_user_id <= 0 ) {
			return array();
		}

		$all_subordinates = array();
		$visited          = array( $supervisor_user_id => true );
		$current_level    = array( $supervisor_user_id );
		$depth            = 0;

		while ( ! empty( $current_level ) && $depth < $max_depth ) {
			$next_level = array();
			foreach ( $current_level as $current_supervisor_id ) {
				foreach ( $this->get_direct_subordinates( $current_supervisor_id ) as $subordinate_id ) {
					if ( isset( $visited[ $subordinate_id ] ) ) {
						continue;
					}

					$visited[ $subordinate_id ] = true;
					$all_subordinates[]          = $subordinate_id;
					$next_level[]                = $subordinate_id;
				}
			}

			$current_level = $next_level;
			++$depth;
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $all_subordinates ) ) ) );
	}

	public function is_subordinate_of( int $user_id, int $supervisor_user_id, int $max_depth = 5 ): bool {
		if ( $user_id <= 0 || $supervisor_user_id <= 0 || $user_id === $supervisor_user_id ) {
			return false;
		}

		$subordinates = $this->get_subordinates_for_supervisor( $supervisor_user_id, $max_depth );

		return in_array( $user_id, $subordinates, true );
	}

	public function get_supervisor_for_user( int $user_id ): ?array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE subordinate_user_id = %d AND status = %s ORDER BY hierarchy_level ASC, id ASC LIMIT 1',
				$user_id,
				'active'
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function has_active_relationship_for_user( int $user_id ): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id FROM ' . $this->get_table_name() . ' WHERE status = %s AND (supervisor_user_id = %d OR subordinate_user_id = %d) LIMIT 1',
				'active',
				$user_id,
				$user_id
			),
			ARRAY_A
		);

		return ! empty( $rows );
	}
}
