<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Surface_Authorization_Service {
	private $rules;

	public function __construct( AIMS_User_Surface_Capability_Repository $rules = null ) {
		$this->rules = $rules ?: new AIMS_User_Surface_Capability_Repository();
	}

	public function current_user_can_for_surface( string $capability, string $surface, string $scope_type = AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return $this->user_can_for_surface( $user_id, $capability, $surface, $scope_type, $scope_ref_id );
	}

	public function user_can_for_surface( int $user_id, string $capability, string $surface, string $scope_type = AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL, int $scope_ref_id = 0 ): bool {
		$user_id      = (int) $user_id;
		$capability   = sanitize_key( $capability );
		$surface      = sanitize_key( $surface );
		$scope_type   = sanitize_key( $scope_type );
		$scope_ref_id = (int) $scope_ref_id;

		if ( $user_id <= 0 || '' === $capability || '' === $surface ) {
			return false;
		}

		$matched_rules = $this->get_matching_rules( $user_id, $capability, $surface, $scope_type, $scope_ref_id );

		foreach ( $matched_rules as $rule ) {
			if ( AIMS_User_Surface_Capability_Repository::MODE_DENY === (string) ( $rule['access_mode'] ?? '' ) ) {
				return false;
			}
		}

		foreach ( $matched_rules as $rule ) {
			if ( AIMS_User_Surface_Capability_Repository::MODE_ALLOW === (string) ( $rule['access_mode'] ?? '' ) ) {
				return true;
			}
		}

		return function_exists( 'user_can' ) ? user_can( $user_id, $capability ) : false;
	}

	public function save_surface_rule( array $data ): int {
		return $this->rules->save_rule( $data );
	}

	public function get_supported_surfaces(): array {
		return AIMS_Capabilities::get_supported_surfaces();
	}

	private function get_matching_rules( int $user_id, string $capability, string $surface, string $scope_type, int $scope_ref_id ): array {
		$rules   = $this->rules->get_active_rules_for_user( $user_id );
		$matches = array();

		foreach ( $rules as $rule ) {
			$rule_capability = sanitize_key( (string) ( $rule['capability_key'] ?? '' ) );
			$rule_surface    = sanitize_key( (string) ( $rule['surface'] ?? '' ) );
			$rule_scope_type = sanitize_key( (string) ( $rule['scope_type'] ?? AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL ) );
			$rule_scope_id   = (int) ( $rule['scope_ref_id'] ?? 0 );

			if ( $rule_capability !== $capability || $rule_surface !== $surface ) {
				continue;
			}

			if ( AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL === $rule_scope_type ) {
				$matches[] = $rule;
				continue;
			}

			if ( $rule_scope_type !== $scope_type ) {
				continue;
			}

			if ( $rule_scope_id <= 0 || $scope_ref_id <= 0 || $rule_scope_id === $scope_ref_id ) {
				$matches[] = $rule;
			}
		}

		usort(
			$matches,
			static function ( array $left, array $right ): int {
				$left_scope  = sanitize_key( (string) ( $left['scope_type'] ?? AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL ) );
				$right_scope = sanitize_key( (string) ( $right['scope_type'] ?? AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL ) );

				if ( $left_scope === $right_scope ) {
					return 0;
				}

				if ( AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL === $left_scope ) {
					return 1;
				}

				if ( AIMS_Responsibility_Assignment_Repository::SCOPE_GLOBAL === $right_scope ) {
					return -1;
				}

				return 0;
			}
		);

		return $matches;
	}
}
