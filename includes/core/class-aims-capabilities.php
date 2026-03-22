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
	public const CAP_RUN_SYNC          = 'run_aims_sync';
	public const CAP_VIEW_REPORTS      = 'view_aims_reports';

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

		add_role(
			'aims_vendor_user',
			'AIMS Vendor User',
			array(
				'read'                  => true,
				self::CAP_MANAGE_VENDORS => true,
				self::CAP_ACCESS_ADMIN   => true,
			)
		);

		add_role(
			'aims_bucket_supervisor',
			'AIMS Bucket Supervisor',
			array(
				'read'                 => true,
				self::CAP_VIEW_BUCKETS => true,
				self::CAP_ACCESS_ADMIN => true,
			)
		);

		add_role(
			'aims_stitcher',
			'AIMS Stitcher',
			array(
				'read'                 => true,
				self::CAP_MANAGE_STITCH => true,
				self::CAP_ACCESS_ADMIN  => true,
			)
		);
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
				'role'   => 'aims_vendor_user',
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $vendor_users as $vendor_user ) {
			$user = new WP_User( $vendor_user->ID );
			$user->remove_role( 'aims_vendor_user' );
		}

		$bucket_supervisors = get_users(
			array(
				'role'   => 'aims_bucket_supervisor',
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $bucket_supervisors as $bucket_supervisor ) {
			$user = new WP_User( $bucket_supervisor->ID );
			$user->remove_role( 'aims_bucket_supervisor' );
		}

		$stitchers = get_users(
			array(
				'role'   => 'aims_stitcher',
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $stitchers as $stitcher ) {
			$user = new WP_User( $stitcher->ID );
			$user->remove_role( 'aims_stitcher' );
		}

		remove_role( 'aims_vendor_user' );
		remove_role( 'aims_bucket_supervisor' );
		remove_role( 'aims_stitcher' );
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
			self::CAP_RUN_SYNC,
			self::CAP_VIEW_REPORTS,
		);
	}
}
