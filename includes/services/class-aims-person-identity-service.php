<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Person_Identity_Service {
	public const SUBTYPE_VENDOR  = 'vendor';
	public const SUBTYPE_STITCH  = 'stitch';
	public const SUBTYPE_WAREHOUSE = 'warehouse';
	public const SUBTYPE_MANAGER = 'manager';

	public function is_aims_person( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : null;
		if ( ! is_object( $user ) ) {
			return false;
		}

		$roles = $this->extract_roles( $user );
		if ( empty( $roles ) ) {
			return false;
		}

		return ! empty( array_intersect( $roles, AIMS_Capabilities::get_aims_role_slugs() ) );
	}

	public function get_person_subtypes( int $user_id ): array {
		if ( ! $this->is_aims_person( $user_id ) ) {
			return array();
		}

		$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : null;
		if ( ! is_object( $user ) ) {
			return array();
		}

		$roles = $this->extract_roles( $user );
		$subtypes = array();

		foreach ( $roles as $role_slug ) {
			$subtypes = array_merge( $subtypes, AIMS_Capabilities::get_person_subtypes_for_role( $role_slug ) );
		}

		return array_values( array_unique( $subtypes ) );
	}

	public function has_person_subtype( int $user_id, string $subtype ): bool {
		$subtype = sanitize_key( $subtype );
		if ( '' === $subtype ) {
			return false;
		}

		return in_array( $subtype, $this->get_person_subtypes( $user_id ), true );
	}

	private function extract_roles( $user ): array {
		if ( ! is_object( $user ) ) {
			return array();
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();

		return array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
	}
}
