<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Capabilities {
	public const CAP_MANAGE            = 'manage_aims';
	public const CAP_MANAGE_VENDORS    = 'manage_aims_vendors';
	public const CAP_MANAGE_EVENTS     = 'manage_aims_events';
	public const CAP_MANAGE_STITCH     = 'manage_aims_stitch';
	public const CAP_MANAGE_BUCKETS    = 'manage_aims_buckets';
	public const CAP_MANAGE_BUCKET_ACCESS = 'manage_aims_bucket_access';
	public const CAP_VIEW_BUCKETS      = 'view_aims_buckets';
	public const CAP_ACCESS_ADMIN      = 'access_aims_admin';
	public const CAP_ACCESS_SHELL      = 'access_aims_shell';
	public const CAP_PORTAL_VENDORS    = 'access_aims_vendor_portal';
	public const CAP_PORTAL_EVENTS     = 'access_aims_event_portal';
	public const CAP_PORTAL_STITCH     = 'access_aims_stitch_portal';
	public const CAP_PORTAL_BUCKETS    = 'access_aims_bucket_portal';
	public const CAP_RUN_SYNC          = 'run_aims_sync';
	public const CAP_VIEW_REPORTS      = 'view_aims_reports';
	public const ROLE_VENDOR_USER      = 'aims_vendor_user';
	public const ROLE_BUCKET_SUPERVISOR = 'aims_bucket_supervisor';
	public const ROLE_STITCHER         = 'aims_stitcher';

	public function register(): void {
		self::register_roles_and_caps();
	}

	public static function cleanup(): void {
		self::remove_roles_and_caps();
	}

	public static function register_roles_and_caps(): void {
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );

		$caps = self::get_caps();

		foreach ( array_filter( array( $administrator, $shop_manager ) ) as $role ) {
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}

		foreach ( self::get_shell_role_definitions() as $role_slug => $definition ) {
			add_role(
				$role_slug,
				(string) ( $definition['label'] ?? $role_slug ),
				array(
					'read' => true,
				)
			);

			self::sync_role_capabilities( $role_slug, (array) ( $definition['caps'] ?? array() ) );
		}
	}

	public static function remove_roles_and_caps(): void {
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );

		$caps = self::get_caps();

		foreach ( array_filter( array( $administrator, $shop_manager ) ) as $role ) {
			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		$vendor_users = get_users(
			array(
				'role'   => self::ROLE_VENDOR_USER,
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $vendor_users as $vendor_user ) {
			$user = new WP_User( $vendor_user->ID );
			$user->remove_role( self::ROLE_VENDOR_USER );
		}

		$bucket_supervisors = get_users(
			array(
				'role'   => self::ROLE_BUCKET_SUPERVISOR,
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $bucket_supervisors as $bucket_supervisor ) {
			$user = new WP_User( $bucket_supervisor->ID );
			$user->remove_role( self::ROLE_BUCKET_SUPERVISOR );
		}

		$stitchers = get_users(
			array(
				'role'   => self::ROLE_STITCHER,
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $stitchers as $stitcher ) {
			$user = new WP_User( $stitcher->ID );
			$user->remove_role( self::ROLE_STITCHER );
		}

		remove_role( self::ROLE_VENDOR_USER );
		remove_role( self::ROLE_BUCKET_SUPERVISOR );
		remove_role( self::ROLE_STITCHER );
	}

	public static function get_shell_role_definitions(): array {
		return array(
			self::ROLE_VENDOR_USER => array(
				'label' => 'AIMS Vendor User',
				'caps'  => array(
					self::CAP_ACCESS_SHELL,
					self::CAP_PORTAL_VENDORS,
					self::CAP_PORTAL_EVENTS,
				),
			),
			self::ROLE_BUCKET_SUPERVISOR => array(
				'label' => 'AIMS Bucket Supervisor',
				'caps'  => array(
					self::CAP_ACCESS_SHELL,
					self::CAP_PORTAL_BUCKETS,
				),
			),
			self::ROLE_STITCHER => array(
				'label' => 'AIMS Stitcher',
				'caps'  => array(
					self::CAP_ACCESS_SHELL,
					self::CAP_PORTAL_STITCH,
				),
			),
		);
	}

	public static function sync_role_capabilities( string $role_slug, array $allowed_caps ): void {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return;
		}

		$allowed_caps = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						$allowed_caps
					)
				)
			)
		);

		$current_caps = array_keys(
			array_filter(
				(array) $role->capabilities
			)
		);

		foreach ( $allowed_caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}

		foreach ( $current_caps as $cap ) {
			if ( in_array( $cap, $allowed_caps, true ) || 'read' === $cap ) {
				continue;
			}

			$role->remove_cap( $cap );
		}
	}

	public static function get_caps(): array {
		return array(
			self::CAP_MANAGE,
			self::CAP_MANAGE_VENDORS,
			self::CAP_MANAGE_EVENTS,
			self::CAP_MANAGE_STITCH,
			self::CAP_MANAGE_BUCKETS,
			self::CAP_MANAGE_BUCKET_ACCESS,
			self::CAP_VIEW_BUCKETS,
			self::CAP_ACCESS_ADMIN,
			self::CAP_ACCESS_SHELL,
			self::CAP_PORTAL_VENDORS,
			self::CAP_PORTAL_EVENTS,
			self::CAP_PORTAL_STITCH,
			self::CAP_PORTAL_BUCKETS,
			self::CAP_RUN_SYNC,
			self::CAP_VIEW_REPORTS,
		);
	}

	public static function get_shell_roles(): array {
		return array_keys( self::get_shell_role_definitions() );
	}

	public static function current_user_is_shell_user(): bool {
		$current_user = wp_get_current_user();
		if ( ! $current_user instanceof WP_User || 0 === $current_user->ID ) {
			return false;
		}

		return (bool) array_intersect( self::get_shell_roles(), (array) $current_user->roles );
	}
}
