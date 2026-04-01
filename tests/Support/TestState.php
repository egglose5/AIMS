<?php

declare( strict_types=1 );

namespace AIMS\Tests\Support;

final class TestState {
	private static array $state = array();

	public static function reset(): void {
		self::$state = array(
			'current_user_id' => 0,
			'current_user'    => null,
			'users'           => array(),
			'user_meta'       => array(),
			'products'        => array(),
			'user_caps'       => array(),
			'hook_calls'      => array(),
			'options'         => array(),
			'throw_on_redirect' => false,
			'current_time'    => '2026-01-01 00:00:00',
		);
	}

	public static function get_option( string $option, $default = false ) {
		if ( array_key_exists( $option, self::$state['options'] ) ) {
			return self::$state['options'][ $option ];
		}

		return $default;
	}

	public static function update_option( string $option, $value ): bool {
		self::$state['options'][ $option ] = $value;

		return true;
	}

	public static function delete_option( string $option ): bool {
		if ( array_key_exists( $option, self::$state['options'] ) ) {
			unset( self::$state['options'][ $option ] );
		}

		return true;
	}

	public static function set_throw_on_redirect( bool $value ): void {
		self::$state['throw_on_redirect'] = $value;
	}

	public static function should_throw_on_redirect(): bool {
		return (bool) ( self::$state['throw_on_redirect'] ?? false );
	}

	public static function current_time(): string {
		return (string) ( self::$state['current_time'] ?? '2026-01-01 00:00:00' );
	}

	public static function set_current_time( string $value ): void {
		self::$state['current_time'] = $value;
	}

	public static function set_current_user_id( int $user_id ): void {
		self::$state['current_user_id'] = $user_id;
	}

	public static function current_user_id(): int {
		return (int) ( self::$state['current_user_id'] ?? 0 );
	}

	public static function set_current_user_object( $user ): void {
		self::$state['current_user'] = is_object( $user ) ? $user : null;
	}

	public static function current_user_object() {
		if ( is_object( self::$state['current_user'] ?? null ) ) {
			return self::$state['current_user'];
		}

		$user = self::current_user_id() > 0 ? self::get_user_by( 'id', self::current_user_id() ) : null;
		if ( is_object( $user ) ) {
			return $user;
		}

		return (object) array(
			'ID'           => 0,
			'display_name' => '',
			'user_login'   => '',
			'user_email'   => '',
		);
	}

	public static function set_user( int $user_id, $user ): void {
		self::$state['users'][ $user_id ] = is_object( $user ) ? $user : (object) $user;
	}

	public static function get_user_by( string $field, $value ) {
		if ( 'id' === strtolower( $field ) ) {
			$user_id = (int) $value;
			return self::$state['users'][ $user_id ] ?? null;
		}

		foreach ( self::$state['users'] as $user ) {
			if ( is_object( $user ) && isset( $user->{$field} ) && (string) $user->{$field} === (string) $value ) {
				return $user;
			}
		}

		return null;
	}

	public static function get_users( array $args = array() ): array {
		$users = array_values( self::$state['users'] ?? array() );

		if ( ! empty( $args['role'] ) ) {
			$role = sanitize_key( (string) $args['role'] );
			$users = array_values(
				array_filter(
					$users,
					static function ( $user ) use ( $role ): bool {
						$roles = isset( $user->roles ) && is_array( $user->roles ) ? array_map( 'sanitize_key', $user->roles ) : array();
						return in_array( $role, $roles, true );
					}
				)
			);
		}

		if ( ! empty( $args['role__in'] ) && is_array( $args['role__in'] ) ) {
			$roles_filter = array_values( array_filter( array_map( 'sanitize_key', $args['role__in'] ) ) );
			$users = array_values(
				array_filter(
					$users,
					static function ( $user ) use ( $roles_filter ): bool {
						$roles = isset( $user->roles ) && is_array( $user->roles ) ? array_map( 'sanitize_key', $user->roles ) : array();
						return ! empty( array_intersect( $roles_filter, $roles ) );
					}
				)
			);
		}

		if ( isset( $args['fields'] ) && 'ID' === $args['fields'] ) {
			return array_map(
				static function ( $user ): int {
					return (int) ( $user->ID ?? 0 );
				},
				$users
			);
		}

		if ( isset( $args['fields'] ) && is_array( $args['fields'] ) && array( 'ID' ) === array_values( $args['fields'] ) ) {
			return array_map(
				static function ( $user ) {
					return (object) array( 'ID' => (int) ( $user->ID ?? 0 ) );
				},
				$users
			);
		}

		return $users;
	}

	public static function set_user_meta( int $user_id, string $key, $value ): void {
		if ( ! isset( self::$state['user_meta'][ $user_id ] ) ) {
			self::$state['user_meta'][ $user_id ] = array();
		}

		self::$state['user_meta'][ $user_id ][ $key ] = $value;
	}

	public static function set_user_capabilities( int $user_id, array $caps ): void {
		self::$state['user_caps'][ $user_id ] = array_fill_keys( array_map( 'sanitize_key', $caps ), true );
	}

	public static function current_user_can( string $cap ): bool {
		return self::user_can( self::current_user_id(), $cap );
	}

	public static function user_can( int $user_id, string $cap ): bool {
		$cap = sanitize_key( $cap );

		$user = self::get_user_by( 'id', $user_id );
		if ( is_object( $user ) ) {
			foreach ( array( 'allcaps', 'caps' ) as $property ) {
				if ( isset( $user->{$property} ) && is_array( $user->{$property} ) ) {
					$user_caps = array_change_key_case( $user->{$property}, CASE_LOWER );
					if ( ! empty( $user_caps[ $cap ] ) ) {
						return true;
					}
				}
			}
		}

		return ! empty( self::$state['user_caps'][ $user_id ][ $cap ] );
	}

	public static function record_hook_call( string $hook, array $args ): void {
		self::$state['hook_calls'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}

	public static function get_hook_calls( ?string $hook = null ): array {
		if ( null === $hook ) {
			return self::$state['hook_calls'];
		}

		return array_values(
			array_filter(
				self::$state['hook_calls'],
				static function ( array $call ) use ( $hook ): bool {
					return (string) ( $call['hook'] ?? '' ) === $hook;
				}
			)
		);
	}

	public static function get_user_meta( int $user_id, string $key, bool $single = true ) {
		$value = self::$state['user_meta'][ $user_id ][ $key ] ?? '';
		return $single ? $value : array( $value );
	}

	public static function set_product( int $product_id, $product ): void {
		self::$state['products'][ $product_id ] = is_object( $product ) ? $product : (object) $product;
	}

	public static function get_product( int $product_id ) {
		return self::$state['products'][ $product_id ] ?? null;
	}
}
