<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Integration_Account_Service {
	public const USER_META_LOCAL_KEY = 'aims_local_app_key';

	private $person_identity;
	private $responsibility_assignments;

	public function __construct( AIMS_Person_Identity_Service $person_identity = null, AIMS_Responsibility_Assignment_Repository $responsibility_assignments = null ) {
		$this->person_identity             = $person_identity ?: new AIMS_Person_Identity_Service();
		$this->responsibility_assignments  = $responsibility_assignments ?: new AIMS_Responsibility_Assignment_Repository();
	}

	public function get_roles_snapshot(): array {
		return array(
			'generated_at'      => current_time( 'mysql' ),
			'templates'         => $this->normalize_role_definitions( AIMS_Capabilities::get_role_templates() ),
			'runtime_roles'     => $this->normalize_role_definitions( AIMS_Capabilities::get_runtime_role_definitions() ),
			'supported_surfaces'=> AIMS_Capabilities::get_supported_surfaces(),
			'capability_groups' => AIMS_Capabilities::get_capability_groups(),
		);
	}

	public function get_account_snapshot( array $lookup ): array {
		$user = $this->resolve_user( $lookup );
		if ( ! is_object( $user ) ) {
			return array();
		}

		$user_id = (int) ( $user->ID ?? 0 );
		$roles   = $this->extract_roles( $user );

		return array(
			'user_id'                    => $user_id,
			'username'                   => sanitize_text_field( (string) ( $user->user_login ?? '' ) ),
			'email'                      => sanitize_email( (string) ( $user->user_email ?? '' ) ),
			'display_name'               => $this->resolve_display_name( $user ),
			'local_key'                  => $this->ensure_local_key( $user ),
			'local_key_meta'             => $this->build_local_key_meta_object( $user, $roles )->all(),
			'roles'                      => $roles,
			'role_details'               => $this->resolve_role_details( $roles ),
			'person_subtypes'            => $this->person_identity->get_person_subtypes( $user_id ),
			'aims_capabilities'          => $this->resolve_aims_capabilities( $user_id ),
			'responsibility_assignments' => $this->normalize_assignments( $this->responsibility_assignments->get_active_assignments_for_user( $user_id ) ),
		);
	}

	public function get_role_account_directory( array $role_slugs ): array {
		$requested_roles = $this->normalize_requested_roles( $role_slugs );
		if ( empty( $requested_roles ) ) {
			return array(
				'generated_at'    => current_time( 'mysql' ),
				'requested_roles' => array(),
				'accounts'        => array(),
			);
		}

		$accounts = array();

		foreach ( (array) ( function_exists( 'get_users' ) ? get_users() : array() ) as $user ) {
			if ( ! is_object( $user ) ) {
				continue;
			}

			$roles = array_values( array_intersect( $requested_roles, $this->extract_roles( $user ) ) );
			if ( empty( $roles ) ) {
				continue;
			}

			$user_id = (int) ( $user->ID ?? 0 );

			$accounts[] = array(
				'user_id'        => $user_id,
				'username'       => sanitize_text_field( (string) ( $user->user_login ?? '' ) ),
				'display_name'   => $this->resolve_display_name( $user ),
				'email'          => sanitize_email( (string) ( $user->user_email ?? '' ) ),
				'local_key'      => $this->ensure_local_key( $user ),
				'local_key_meta' => $this->build_local_key_meta_object( $user, $roles )->all(),
				'roles'          => $roles,
				'person_subtypes'=> $this->person_identity->get_person_subtypes( $user_id ),
			);
		}

		usort(
			$accounts,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['local_key'] ?? '' ), (string) ( $right['local_key'] ?? '' ) );
			}
		);

		return array(
			'generated_at'    => current_time( 'mysql' ),
			'requested_roles' => $requested_roles,
			'accounts'        => $accounts,
		);
	}

	private function resolve_user( array $lookup ) {
		$user_id  = absint( $lookup['user_id'] ?? 0 );
		$username = sanitize_text_field( (string) ( $lookup['username'] ?? '' ) );
		$email    = sanitize_email( (string) ( $lookup['email'] ?? '' ) );

		if ( $user_id > 0 && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'id', $user_id );
			if ( is_object( $user ) ) {
				return $user;
			}
		}

		foreach ( (array) ( function_exists( 'get_users' ) ? get_users() : array() ) as $user ) {
			if ( ! is_object( $user ) ) {
				continue;
			}

			if ( '' !== $username ) {
				$candidates = array(
					strtolower( sanitize_text_field( (string) ( $user->user_login ?? '' ) ) ),
					strtolower( sanitize_text_field( (string) ( $user->display_name ?? '' ) ) ),
				);

				if ( in_array( strtolower( $username ), $candidates, true ) ) {
					return $user;
				}
			}

			if ( '' !== $email && strtolower( sanitize_email( (string) ( $user->user_email ?? '' ) ) ) === strtolower( $email ) ) {
				return $user;
			}
		}

		return null;
	}

	private function resolve_display_name( $user ): string {
		foreach ( array( 'display_name', 'user_login', 'user_email' ) as $field ) {
			$value = sanitize_text_field( (string) ( $user->{$field} ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function ensure_local_key( $user ): string {
		$user_id = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : 0;
		if ( $user_id <= 0 ) {
			return '';
		}

		$stored = function_exists( 'get_user_meta' )
			? sanitize_text_field( (string) get_user_meta( $user_id, self::USER_META_LOCAL_KEY, true ) )
			: '';
		if ( '' !== $stored ) {
			return $stored;
		}

		$username = sanitize_key( (string) ( $user->user_login ?? '' ) );
		if ( '' === $username ) {
			$username = 'user';
		}

		$local_key = 'aims-' . substr( $username, 0, 20 ) . '-' . $user_id;

		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( $user_id, self::USER_META_LOCAL_KEY, $local_key );
		}

		return $local_key;
	}

	private function build_local_key_meta_object( $user, array $roles = array() ): AIMS_Admin_Meta_Object {
		$user_id     = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : 0;
		$username    = sanitize_text_field( (string) ( is_object( $user ) ? ( $user->user_login ?? '' ) : '' ) );
		$display     = $this->resolve_display_name( $user );
		$local_key   = $this->ensure_local_key( $user );
		$segments    = array_values( array_filter( explode( '-', $local_key ), 'strlen' ) );
		$primary_role = isset( $roles[0] ) ? sanitize_key( (string) $roles[0] ) : '';

		return new AIMS_Admin_Meta_Object(
			array(
				'key'          => 'local_app_key',
				'title'        => 'Local App Key',
				'description'  => 'Theme-friendly local integration key for AIMS-linked software surfaces.',
				'category'     => 'credentials',
				'value'        => $local_key,
				'display_value'=> $local_key,
				'copy_value'   => $local_key,
				'is_sensitive' => false,
				'user_id'      => $user_id,
				'username'     => $username,
				'display_name' => $display,
				'role_slug'    => $primary_role,
				'segments'     => $this->build_local_key_segments( $segments ),
				'badges'       => array_values( array_filter( array( $primary_role, $username ) ) ),
			)
		);
	}

	private function build_local_key_segments( array $segments ): array {
		$labels = array( 'prefix', 'account', 'id' );
		$mapped = array();

		foreach ( array_values( $segments ) as $index => $segment ) {
			$mapped[] = array(
				'index' => $index,
				'label' => $labels[ $index ] ?? 'segment_' . $index,
				'value' => sanitize_text_field( (string) $segment ),
			);
		}

		return $mapped;
	}

	private function normalize_requested_roles( array $role_slugs ): array {
		$normalized = array();

		foreach ( $role_slugs as $role_slug ) {
			$role_slug = sanitize_key( (string) $role_slug );
			if ( '' === $role_slug ) {
				continue;
			}

			if ( is_array( AIMS_Capabilities::get_role_definition( $role_slug ) ) ) {
				$normalized[] = $role_slug;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	private function extract_roles( $user ): array {
		if ( ! is_object( $user ) || ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_key', $user->roles ) ) );
	}

	private function resolve_role_details( array $roles ): array {
		$details = array();

		foreach ( $roles as $role_slug ) {
			$definition = AIMS_Capabilities::get_role_definition( $role_slug );
			if ( is_array( $definition ) ) {
				$detail                 = $this->normalize_role_definition( $definition );
				$detail['is_aims_role'] = true;
				$details[]              = $detail;
				continue;
			}

			$role = function_exists( 'get_role' ) ? get_role( $role_slug ) : null;
			$details[] = array(
				'role_slug'           => $role_slug,
				'role_name'           => sanitize_text_field( (string) ( is_object( $role ) ? ( $role->name ?? $role_slug ) : $role_slug ) ),
				'template_key'        => '',
				'description'         => '',
				'person_subtypes'     => array(),
				'capabilities'        => is_object( $role ) && isset( $role->capabilities ) && is_array( $role->capabilities )
					? array_keys( array_filter( $role->capabilities ) )
					: array(),
				'is_builtin_template' => false,
				'is_aims_role'        => false,
			);
		}

		return $details;
	}

	private function resolve_aims_capabilities( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$granted = array();
		foreach ( AIMS_Capabilities::get_caps() as $cap ) {
			$cap = sanitize_key( (string) $cap );
			if ( '' !== $cap && function_exists( 'user_can' ) && user_can( $user_id, $cap ) ) {
				$granted[] = $cap;
			}
		}

		return array_values( array_unique( $granted ) );
	}

	private function normalize_assignments( array $assignments ): array {
		$normalized = array();

		foreach ( $assignments as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			$normalized[] = array(
				'id'                 => (int) ( $assignment['id'] ?? 0 ),
				'responsibility_key' => sanitize_key( (string) ( $assignment['responsibility_key'] ?? '' ) ),
				'scope_type'         => sanitize_key( (string) ( $assignment['scope_type'] ?? AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL ) ),
				'scope_ref_id'       => (int) ( $assignment['scope_ref_id'] ?? 0 ),
				'is_active'          => ! empty( $assignment['is_active'] ),
				'granted_at'         => sanitize_text_field( (string) ( $assignment['granted_at'] ?? '' ) ),
				'revoked_at'         => sanitize_text_field( (string) ( $assignment['revoked_at'] ?? '' ) ),
			);
		}

		return $normalized;
	}

	private function normalize_role_definitions( array $definitions ): array {
		$normalized = array();

		foreach ( $definitions as $definition ) {
			if ( is_array( $definition ) ) {
				$normalized[] = $this->normalize_role_definition( $definition );
			}
		}

		return $normalized;
	}

	private function normalize_role_definition( array $definition ): array {
		return array(
			'role_slug'           => sanitize_key( (string) ( $definition['role_slug'] ?? '' ) ),
			'role_name'           => sanitize_text_field( (string) ( $definition['role_name'] ?? '' ) ),
			'template_key'        => sanitize_key( (string) ( $definition['template_key'] ?? '' ) ),
			'description'         => sanitize_text_field( (string) ( $definition['description'] ?? '' ) ),
			'person_subtypes'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $definition['person_subtypes'] ?? array() ) ) ) ),
			'capabilities'        => array_keys( array_filter( (array) ( $definition['caps'] ?? array() ) ) ),
			'is_builtin_template' => ! empty( $definition['is_builtin_template'] ),
		);
	}
}
