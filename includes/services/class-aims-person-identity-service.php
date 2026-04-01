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
		return ! empty( $this->resolve_person_subtypes( $user_id ) );
	}

	public function get_person_subtypes( int $user_id ): array {
		return $this->resolve_person_subtypes( $user_id );
	}

	public function has_person_subtype( int $user_id, string $subtype ): bool {
		$subtype = sanitize_key( $subtype );
		if ( '' === $subtype ) {
			return false;
		}

		return in_array( $subtype, $this->get_person_subtypes( $user_id ), true );
	}

	private function resolve_person_subtypes( int $user_id ): array {
		if ( $user_id <= 0 ) {
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

		foreach ( AIMS_Capabilities::get_person_subtype_capability_map() as $subtype => $caps ) {
			foreach ( (array) $caps as $cap ) {
				if ( function_exists( 'user_can' ) && user_can( $user_id, (string) $cap ) ) {
					$subtypes[] = sanitize_key( (string) $subtype );
					break;
				}
			}
		}

		return array_values( array_unique( $subtypes ) );
	}

	private function extract_roles( $user ): array {
		if ( ! is_object( $user ) ) {
			return array();
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();

		return array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
	}
}
