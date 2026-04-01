<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Capabilities {
	public const ROLE_VENDOR_USER     = 'aims_vendor_user';
	public const ROLE_STITCH_USER     = 'aims_stitch_user';
	public const ROLE_WAREHOUSE_USER  = 'aims_warehouse_user';
	public const ROLE_SUPERVISOR_USER = 'aims_supervisor_user';
	public const ROLE_MANAGER_USER    = 'aims_manager_user';

	public const CAP_MANAGE         = 'manage_aims';
	public const CAP_MANAGE_VENDORS = 'manage_aims_vendors';
	public const CAP_MANAGE_EVENTS  = 'manage_aims_events';
	public const CAP_MANAGE_EVENT_PUBLICATION = 'manage_aims_event_publication';
	public const CAP_MANAGE_EVENT_PLANNING = 'manage_aims_event_planning';
	public const CAP_MANAGE_STITCH  = 'manage_aims_stitch';
	public const CAP_MANAGE_STITCH_ORDERS = 'manage_aims_stitch_orders';
	public const CAP_VIEW_EVENTS_SHELL = 'view_aims_events_shell';
	public const CAP_VIEW_INVENTORY_SHELL = 'view_aims_inventory_shell';
	public const CAP_MANAGE_INVENTORY = 'manage_aims_inventory';
	public const CAP_MANAGE_STORAGE_LOCATIONS = 'manage_aims_storage_locations';
	public const CAP_MANAGE_PHYSICAL_BUCKETS = 'manage_aims_physical_buckets';
	public const CAP_MANAGE_EVENT_BUCKETS = 'manage_aims_event_buckets';
	public const CAP_VIEW_BUCKET_LEDGER = 'view_aims_bucket_ledger';
	public const CAP_MANAGE_RECONCILIATION = 'manage_aims_reconciliation';
	public const CAP_MANAGE_PICK_PACK = 'manage_aims_pick_pack';
	public const CAP_RUN_SYNC       = 'run_aims_sync';
	public const CAP_VIEW_REPORTS   = 'view_aims_reports';
	public const CAP_MANAGE_VENDOR_ACCESS     = 'manage_aims_vendor_access';
	public const CAP_MANAGE_PRODUCTION        = 'manage_aims_production';
	public const CAP_MANAGE_FULFILLMENT       = 'manage_aims_fulfillment';
	public const CAP_MANAGE_SQUARE_SYNC       = 'manage_aims_square_sync';
	public const CAP_MANAGE_PAYOUTS           = 'manage_aims_payouts';
	public const CAP_MANAGE_REPORTS           = 'manage_aims_reports';
	public const CAP_MANAGE_RBAC              = 'manage_aims_rbac';
	public const CAP_REVIEW_SQUARE_EXCEPTIONS = 'review_aims_square_exceptions';
	public const CAP_REVIEW_VENDOR_SYNC       = 'review_aims_vendor_sync';
	public const CAP_RUN_REPLAY               = 'run_aims_replay';
	public const CAP_RUN_UNDO                 = 'run_aims_undo';
	public const CAP_VIEW_VENDOR_PORTAL       = 'view_aims_vendor_portal';
	public const CAP_VIEW_STITCH_PORTAL       = 'view_aims_stitch_portal';
	public const CAP_VIEW_SUPERVISOR_PORTAL   = 'view_aims_supervisor_portal';
	public const CAP_VIEW_DASHBOARD           = 'view_aims_dashboard';

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

		self::sync_role_caps(
			self::ROLE_VENDOR_USER,
			'AIMS Vendor User',
			array(
				'read' => true,
				self::CAP_VIEW_VENDOR_PORTAL => true,
			)
		);

		self::sync_role_caps(
			self::ROLE_STITCH_USER,
			'AIMS Stitch User',
			array(
				'read' => true,
				self::CAP_VIEW_STITCH_PORTAL => true,
			)
		);

		self::sync_role_caps(
			self::ROLE_WAREHOUSE_USER,
			'AIMS Main Warehouse Operator',
			array(
				'read' => true,
				self::CAP_VIEW_DASHBOARD => true,
				self::CAP_VIEW_REPORTS => true,
				self::CAP_VIEW_EVENTS_SHELL => true,
				self::CAP_VIEW_INVENTORY_SHELL => true,
				self::CAP_MANAGE_INVENTORY => true,
				self::CAP_MANAGE_STORAGE_LOCATIONS => true,
				self::CAP_MANAGE_PHYSICAL_BUCKETS => true,
				self::CAP_MANAGE_EVENT_BUCKETS => true,
				self::CAP_VIEW_BUCKET_LEDGER => true,
				self::CAP_MANAGE_PICK_PACK => true,
				self::CAP_MANAGE_FULFILLMENT => true,
			)
		);

		self::sync_role_caps(
			self::ROLE_SUPERVISOR_USER,
			'AIMS Supervisor User',
			array(
				'read' => true,
				self::CAP_VIEW_SUPERVISOR_PORTAL => true,
				self::CAP_VIEW_EVENTS_SHELL => true,
				self::CAP_MANAGE_EVENT_PLANNING => true,
				self::CAP_VIEW_INVENTORY_SHELL => true,
			)
		);

		self::sync_role_caps(
			self::ROLE_MANAGER_USER,
			'AIMS Manager User',
			array(
				'read' => true,
				self::CAP_VIEW_DASHBOARD => true,
				self::CAP_VIEW_REPORTS => true,
				self::CAP_VIEW_EVENTS_SHELL => true,
				self::CAP_MANAGE_EVENT_PLANNING => true,
				self::CAP_VIEW_INVENTORY_SHELL => true,
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

		$custom_roles = array_keys( self::get_portal_roles() );
		$custom_users = get_users(
			array(
				'role__in' => $custom_roles,
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $custom_users as $custom_user ) {
			$user = new WP_User( $custom_user->ID );

			foreach ( $custom_roles as $role_slug ) {
				$user->remove_role( $role_slug );
			}
		}

		foreach ( $custom_roles as $role_slug ) {
			remove_role( $role_slug );
		}
	}

	public static function get_caps(): array {
		return array_values(
			array_unique(
			array(
			self::CAP_MANAGE,
			self::CAP_MANAGE_VENDORS,
			self::CAP_MANAGE_EVENTS,
			self::CAP_MANAGE_EVENT_PUBLICATION,
			self::CAP_MANAGE_EVENT_PLANNING,
			self::CAP_MANAGE_STITCH,
			self::CAP_MANAGE_STITCH_ORDERS,
			self::CAP_VIEW_EVENTS_SHELL,
			self::CAP_VIEW_INVENTORY_SHELL,
			self::CAP_MANAGE_INVENTORY,
			self::CAP_MANAGE_STORAGE_LOCATIONS,
			self::CAP_MANAGE_PHYSICAL_BUCKETS,
			self::CAP_MANAGE_EVENT_BUCKETS,
			self::CAP_VIEW_BUCKET_LEDGER,
			self::CAP_MANAGE_RECONCILIATION,
			self::CAP_MANAGE_PICK_PACK,
			self::CAP_RUN_SYNC,
			self::CAP_VIEW_REPORTS,
			self::CAP_MANAGE_VENDOR_ACCESS,
			self::CAP_MANAGE_PRODUCTION,
			self::CAP_MANAGE_FULFILLMENT,
			self::CAP_MANAGE_SQUARE_SYNC,
			self::CAP_MANAGE_PAYOUTS,
			self::CAP_MANAGE_REPORTS,
			self::CAP_MANAGE_RBAC,
			self::CAP_REVIEW_SQUARE_EXCEPTIONS,
			self::CAP_REVIEW_VENDOR_SYNC,
			self::CAP_RUN_REPLAY,
			self::CAP_RUN_UNDO,
			self::CAP_VIEW_VENDOR_PORTAL,
			self::CAP_VIEW_STITCH_PORTAL,
			self::CAP_VIEW_SUPERVISOR_PORTAL,
			self::CAP_VIEW_DASHBOARD,
				)
			)
		);
	}

	public static function get_portal_roles(): array {
		return array(
			self::ROLE_VENDOR_USER     => 'AIMS Vendor User',
			self::ROLE_STITCH_USER     => 'AIMS Stitch User',
			self::ROLE_WAREHOUSE_USER  => 'AIMS Main Warehouse Operator',
			self::ROLE_SUPERVISOR_USER => 'AIMS Supervisor User',
			self::ROLE_MANAGER_USER    => 'AIMS Manager User',
		);
	}

	public static function get_aims_role_slugs(): array {
		return array_keys( self::get_portal_roles() );
	}

	private static function sync_role_caps( string $role_slug, string $role_name, array $caps ): void {
		$role = get_role( $role_slug );

		if ( null === $role ) {
			add_role( $role_slug, $role_name, $caps );
			return;
		}

		foreach ( $caps as $cap => $enabled ) {
			if ( $enabled ) {
				$role->add_cap( $cap );
			}
		}
	}
}
