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

	public const OPTION_CUSTOM_ROLE_REGISTRY = 'aims_custom_role_registry';

	public const SURFACE_WP_ADMIN   = 'wp_admin';
	public const SURFACE_MOBILE_APP = 'mobile_app';
	public const SURFACE_API_DIRECT = 'api_direct';

	public const CAP_MANAGE                         = 'manage_aims';
	public const CAP_MANAGE_VENDORS                 = 'manage_aims_vendors';
	public const CAP_MANAGE_EVENTS                  = 'manage_aims_events';
	public const CAP_MANAGE_EVENT_PUBLICATION       = 'manage_aims_event_publication';
	public const CAP_MANAGE_EVENT_PLANNING          = 'manage_aims_event_planning';
	public const CAP_MANAGE_STITCH                  = 'manage_aims_stitch';
	public const CAP_MANAGE_STITCH_ORDERS           = 'manage_aims_stitch_orders';
	public const CAP_VIEW_EVENTS_SHELL              = 'view_aims_events_shell';
	public const CAP_VIEW_INVENTORY_SHELL           = 'view_aims_inventory_shell';
	public const CAP_MANAGE_INVENTORY               = 'manage_aims_inventory';
	public const CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL = 'bypass_aims_inventory_transfer_protocol';
	public const CAP_MANAGE_STORAGE_LOCATIONS       = 'manage_aims_storage_locations';
	public const CAP_MANAGE_PHYSICAL_BUCKETS        = 'manage_aims_physical_buckets';
	public const CAP_MANAGE_EVENT_BUCKETS           = 'manage_aims_event_buckets';
	public const CAP_VIEW_BUCKET_LEDGER             = 'view_aims_bucket_ledger';
	public const CAP_MANAGE_RECONCILIATION          = 'manage_aims_reconciliation';
	public const CAP_MANAGE_PICK_PACK               = 'manage_aims_pick_pack';
	public const CAP_RUN_SYNC                       = 'run_aims_sync';
	public const CAP_VIEW_REPORTS                   = 'view_aims_reports';
	public const CAP_MANAGE_VENDOR_ACCESS           = 'manage_aims_vendor_access';
	public const CAP_MANAGE_PRODUCTION              = 'manage_aims_production';
	public const CAP_MANAGE_FULFILLMENT             = 'manage_aims_fulfillment';
	public const CAP_MANAGE_SQUARE_SYNC             = 'manage_aims_square_sync';
	public const CAP_MANAGE_PAYOUTS                 = 'manage_aims_payouts';
	public const CAP_MANAGE_REPORTS                 = 'manage_aims_reports';
	public const CAP_MANAGE_RBAC                    = 'manage_aims_rbac';
	public const CAP_REVIEW_SQUARE_EXCEPTIONS       = 'review_aims_square_exceptions';
	public const CAP_REVIEW_VENDOR_SYNC             = 'review_aims_vendor_sync';
	public const CAP_RUN_REPLAY                     = 'run_aims_replay';
	public const CAP_RUN_UNDO                       = 'run_aims_undo';
	public const CAP_VIEW_VENDOR_PORTAL             = 'view_aims_vendor_portal';
	public const CAP_VIEW_STITCH_PORTAL             = 'view_aims_stitch_portal';
	public const CAP_VIEW_SUPERVISOR_PORTAL         = 'view_aims_supervisor_portal';
	public const CAP_VIEW_DASHBOARD                 = 'view_aims_dashboard';

	public const CAP_RESP_SYSTEM_ADMIN              = 'aims_resp_system_admin';
	public const CAP_RESP_EVENT_PLANNING_ACCESS     = 'aims_resp_event_planning_access';
	public const CAP_RESP_EVENT_PLANNING_MUTATE     = 'aims_resp_event_planning_mutate';
	public const CAP_RESP_EVENT_PLANNING_ALL        = 'aims_resp_event_planning_all_events';
	public const CAP_RESP_VENDOR_MANAGEMENT         = 'aims_resp_vendor_management';
	public const CAP_RESP_VENDOR_SUBMIT_CHECKIN     = 'aims_resp_vendor_submit_checkin';
	public const CAP_RESP_VENDOR_VIEW_COMMISSION    = 'aims_resp_vendor_view_commission';
	public const CAP_RESP_VENDOR_MANAGE_INVENTORY   = 'aims_resp_vendor_manage_inventory';
	public const CAP_RESP_SQUARE_SYNC_MANAGEMENT    = 'aims_resp_square_sync_management';
	public const CAP_RESP_SQUARE_SYNC_REPLAY        = 'aims_resp_square_sync_replay';
	public const CAP_RESP_SQUARE_SYNC_UNDO          = 'aims_resp_square_sync_undo';
	public const CAP_RESP_REPORTS_VIEW              = 'aims_resp_reports_view';
	public const CAP_RESP_STITCH_ORDER_MANAGEMENT   = 'aims_resp_stitch_order_management';

	public function register(): void {
		self::register_roles_and_caps();
	}

	public static function cleanup(): void {
		self::remove_roles_and_caps();
	}

	public static function register_roles_and_caps(): void {
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );

		foreach ( array_filter( array( $administrator, $shop_manager ) ) as $role ) {
			foreach ( self::get_caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}

		self::migrate_template_role_users_to_runtime_roles();
		self::remove_template_roles_from_runtime();

		foreach ( self::get_runtime_role_definitions() as $definition ) {
			self::sync_role_definition( $definition );
		}
	}

	public static function remove_roles_and_caps(): void {
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );

		foreach ( array_filter( array( $administrator, $shop_manager ) ) as $role ) {
			foreach ( self::get_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		$aims_roles = array_values(
			array_unique(
				array_merge(
					self::get_runtime_role_slugs(),
					self::get_role_template_slugs()
				)
			)
		);
		$custom_users = get_users(
			array(
				'role__in' => $aims_roles,
				'fields'   => array( 'ID' ),
			)
		);

		foreach ( $custom_users as $custom_user ) {
			if ( ! class_exists( 'WP_User' ) ) {
				break;
			}

			$user = new WP_User( $custom_user->ID );
			foreach ( $aims_roles as $role_slug ) {
				$user->remove_role( $role_slug );
			}
		}

		foreach ( $aims_roles as $role_slug ) {
			remove_role( $role_slug );
		}

		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_CUSTOM_ROLE_REGISTRY );
		}
	}

	public static function get_caps(): array {
		$caps = array_merge(
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
				self::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
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
			),
			array_values( self::get_responsibility_cap_map() )
		);

		foreach ( self::get_role_definitions() as $definition ) {
			$caps = array_merge( $caps, array_keys( (array) ( $definition['caps'] ?? array() ) ) );
		}

		foreach ( self::get_capability_groups() as $group ) {
			$caps = array_merge( $caps, array_keys( (array) ( $group['external_caps'] ?? array() ) ), (array) ( $group['caps'] ?? array() ) );
		}

		return array_values( array_unique( array_map( 'sanitize_key', $caps ) ) );
	}

	public static function get_responsibility_cap_map(): array {
		return array(
			'system_admin'              => self::CAP_RESP_SYSTEM_ADMIN,
			'event_planning_access'     => self::CAP_RESP_EVENT_PLANNING_ACCESS,
			'event_planning_mutate'     => self::CAP_RESP_EVENT_PLANNING_MUTATE,
			'event_planning_all_events' => self::CAP_RESP_EVENT_PLANNING_ALL,
			'vendor_management'         => self::CAP_RESP_VENDOR_MANAGEMENT,
			'vendor_submit_checkin'     => self::CAP_RESP_VENDOR_SUBMIT_CHECKIN,
			'vendor_view_commission'    => self::CAP_RESP_VENDOR_VIEW_COMMISSION,
			'vendor_manage_inventory'   => self::CAP_RESP_VENDOR_MANAGE_INVENTORY,
			'square_sync_management'    => self::CAP_RESP_SQUARE_SYNC_MANAGEMENT,
			'square_sync_replay'        => self::CAP_RESP_SQUARE_SYNC_REPLAY,
			'square_sync_undo'          => self::CAP_RESP_SQUARE_SYNC_UNDO,
			'reports_view'              => self::CAP_RESP_REPORTS_VIEW,
			'stitch_order_management'   => self::CAP_RESP_STITCH_ORDER_MANAGEMENT,
		);
	}

	public static function get_person_subtype_capability_map(): array {
		return array(
			AIMS_Person_Identity_Service::SUBTYPE_VENDOR    => array(
				self::CAP_VIEW_VENDOR_PORTAL,
				self::CAP_RESP_VENDOR_SUBMIT_CHECKIN,
			),
			AIMS_Person_Identity_Service::SUBTYPE_STITCH    => array(
				self::CAP_VIEW_STITCH_PORTAL,
				self::CAP_RESP_STITCH_ORDER_MANAGEMENT,
				self::CAP_MANAGE_STITCH,
				self::CAP_MANAGE_STITCH_ORDERS,
			),
			AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE => array(
				self::CAP_MANAGE_INVENTORY,
				self::CAP_MANAGE_STORAGE_LOCATIONS,
				self::CAP_MANAGE_PHYSICAL_BUCKETS,
				self::CAP_MANAGE_EVENT_BUCKETS,
				self::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
			),
			AIMS_Person_Identity_Service::SUBTYPE_MANAGER   => array(
				self::CAP_VIEW_SUPERVISOR_PORTAL,
				self::CAP_MANAGE_EVENT_PLANNING,
				self::CAP_RESP_EVENT_PLANNING_ACCESS,
				self::CAP_RESP_EVENT_PLANNING_MUTATE,
				self::CAP_RESP_EVENT_PLANNING_ALL,
			),
		);
	}

	public static function get_capability_groups(): array {
		$groups = array(
			'core' => array(
				'label'       => 'Governance',
				'description' => 'Owner-level and system governance permissions for the AIMS stack.',
				'stack_level' => 'governance',
				'caps'  => array(
					self::CAP_MANAGE,
					self::CAP_MANAGE_RBAC,
					self::CAP_VIEW_DASHBOARD,
				),
			),
			'events' => array(
				'label'       => 'Planning',
				'description' => 'Planning, event setup, and assignment-facing permissions.',
				'stack_level' => 'planning',
				'caps'  => array(
					self::CAP_MANAGE_EVENTS,
					self::CAP_MANAGE_EVENT_PUBLICATION,
					self::CAP_MANAGE_EVENT_PLANNING,
					self::CAP_VIEW_EVENTS_SHELL,
					self::CAP_MANAGE_EVENT_BUCKETS,
				),
			),
			'inventory' => array(
				'label'       => 'Operations',
				'description' => 'Physical inventory, custody, warehouse, and reconciliation permissions.',
				'stack_level' => 'operations',
				'caps'  => array(
					self::CAP_VIEW_INVENTORY_SHELL,
					self::CAP_MANAGE_INVENTORY,
					self::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL,
					self::CAP_MANAGE_STORAGE_LOCATIONS,
					self::CAP_MANAGE_PHYSICAL_BUCKETS,
					self::CAP_VIEW_BUCKET_LEDGER,
					self::CAP_MANAGE_RECONCILIATION,
					self::CAP_MANAGE_PICK_PACK,
					self::CAP_MANAGE_FULFILLMENT,
				),
			),
			'vendors' => array(
				'label'       => 'Relationships',
				'description' => 'Vendor-facing access and people/partner workflow permissions.',
				'stack_level' => 'relationships',
				'caps'  => array(
					self::CAP_MANAGE_VENDORS,
					self::CAP_MANAGE_VENDOR_ACCESS,
					self::CAP_VIEW_VENDOR_PORTAL,
				),
			),
			'production' => array(
				'label'       => 'Execution',
				'description' => 'Production, stitch, and task-execution permissions.',
				'stack_level' => 'execution',
				'caps'  => array(
					self::CAP_MANAGE_STITCH,
					self::CAP_MANAGE_STITCH_ORDERS,
					self::CAP_MANAGE_PRODUCTION,
					self::CAP_RESP_STITCH_ORDER_MANAGEMENT,
					self::CAP_VIEW_STITCH_PORTAL,
				),
			),
			'square' => array(
				'label'       => 'Commerce Sync',
				'description' => 'Money-adjacent sync, replay, review, and commerce integration permissions.',
				'stack_level' => 'commerce_sync',
				'caps'  => array(
					self::CAP_MANAGE_SQUARE_SYNC,
					self::CAP_RUN_SYNC,
					self::CAP_REVIEW_SQUARE_EXCEPTIONS,
					self::CAP_REVIEW_VENDOR_SYNC,
					self::CAP_RUN_REPLAY,
					self::CAP_RUN_UNDO,
				),
			),
			'reports' => array(
				'label'       => 'Visibility',
				'description' => 'Reporting, readout, and payout visibility permissions.',
				'stack_level' => 'visibility',
				'caps'  => array(
					self::CAP_VIEW_REPORTS,
					self::CAP_MANAGE_REPORTS,
					self::CAP_MANAGE_PAYOUTS,
				),
			),
			'responsibilities' => array(
				'label'       => 'Workflow Responsibilities',
				'description' => 'Hat-based responsibility capabilities that narrow who can act in which workflow.',
				'stack_level' => 'workflow',
				'caps'  => array_values( self::get_responsibility_cap_map() ),
			),
			'portals' => array(
				'label'       => 'Surface Access',
				'description' => 'Permissions tied to user-facing surfaces and workflow entry points.',
				'stack_level' => 'surfaces',
				'caps'  => array(
					self::CAP_VIEW_VENDOR_PORTAL,
					self::CAP_VIEW_STITCH_PORTAL,
					self::CAP_VIEW_SUPERVISOR_PORTAL,
				),
			),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$groups = apply_filters( 'aims_capability_groups', $groups );
		}

		return self::normalize_capability_groups( is_array( $groups ) ? $groups : array() );
	}

	private static function normalize_capability_groups( array $groups ): array {
		$normalized = array();

		foreach ( $groups as $group_key => $group ) {
			$key = sanitize_key( (string) $group_key );
			if ( '' === $key || ! is_array( $group ) ) {
				continue;
			}

			$normalized[ $key ] = array(
				'label'        => (string) ( $group['label'] ?? ucfirst( str_replace( '_', ' ', $key ) ) ),
				'description'  => (string) ( $group['description'] ?? '' ),
				'stack_level'  => sanitize_key( (string) ( $group['stack_level'] ?? $key ) ),
				'caps'         => array_values( array_filter( array_map( 'sanitize_key', (array) ( $group['caps'] ?? array() ) ) ) ),
				'external_caps'=> (array) ( $group['external_caps'] ?? array() ),
			);
		}

		return $normalized;
	}

	public static function get_supported_surfaces(): array {
		return array(
			self::SURFACE_WP_ADMIN   => 'WordPress Dashboard',
			self::SURFACE_MOBILE_APP => 'Mobile App',
			self::SURFACE_API_DIRECT => 'Direct API',
		);
	}

	public static function get_role_templates(): array {
		return array(
			self::ROLE_VENDOR_USER => array(
				'role_slug'        => self::ROLE_VENDOR_USER,
				'role_name'        => 'AIMS Vendor User',
				'template_key'     => self::ROLE_VENDOR_USER,
				'description'      => 'Frontend vendor portal access with vendor-oriented workflow capabilities.',
				'person_subtypes'  => array( AIMS_Person_Identity_Service::SUBTYPE_VENDOR ),
				'caps'             => array(
					'read'                   => true,
					self::CAP_VIEW_VENDOR_PORTAL => true,
					self::CAP_RESP_VENDOR_SUBMIT_CHECKIN => true,
				),
				'is_builtin_template' => true,
			),
			self::ROLE_STITCH_USER => array(
				'role_slug'        => self::ROLE_STITCH_USER,
				'role_name'        => 'AIMS Stitch User',
				'template_key'     => self::ROLE_STITCH_USER,
				'description'      => 'Frontend stitch portal access for stitch execution users.',
				'person_subtypes'  => array( AIMS_Person_Identity_Service::SUBTYPE_STITCH ),
				'caps'             => array(
					'read'                   => true,
					self::CAP_RESP_STITCH_ORDER_MANAGEMENT => true,
					self::CAP_VIEW_STITCH_PORTAL => true,
				),
				'is_builtin_template' => true,
			),
			self::ROLE_WAREHOUSE_USER => array(
				'role_slug'        => self::ROLE_WAREHOUSE_USER,
				'role_name'        => 'AIMS Main Warehouse Operator',
				'template_key'     => self::ROLE_WAREHOUSE_USER,
				'description'      => 'Warehouse operators can move inventory across custody nodes and manage warehouse execution.',
				'person_subtypes'  => array( AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE ),
				'caps'             => array(
					'read'                   => true,
					self::CAP_VIEW_DASHBOARD => true,
					self::CAP_VIEW_REPORTS   => true,
					self::CAP_VIEW_EVENTS_SHELL => true,
					self::CAP_VIEW_INVENTORY_SHELL => true,
					self::CAP_MANAGE_INVENTORY => true,
					self::CAP_BYPASS_INVENTORY_TRANSFER_PROTOCOL => true,
					self::CAP_MANAGE_STORAGE_LOCATIONS => true,
					self::CAP_MANAGE_PHYSICAL_BUCKETS => true,
					self::CAP_MANAGE_EVENT_BUCKETS => true,
					self::CAP_VIEW_BUCKET_LEDGER => true,
					self::CAP_MANAGE_PICK_PACK => true,
					self::CAP_MANAGE_FULFILLMENT => true,
				),
				'is_builtin_template' => true,
			),
			self::ROLE_SUPERVISOR_USER => array(
				'role_slug'        => self::ROLE_SUPERVISOR_USER,
				'role_name'        => 'AIMS Supervisor User',
				'template_key'     => self::ROLE_SUPERVISOR_USER,
				'description'      => 'Supervisor portal users with planning and delegated inventory visibility.',
				'person_subtypes'  => array( AIMS_Person_Identity_Service::SUBTYPE_MANAGER ),
				'caps'             => array(
					'read'                   => true,
					self::CAP_VIEW_SUPERVISOR_PORTAL => true,
					self::CAP_VIEW_EVENTS_SHELL => true,
					self::CAP_MANAGE_EVENT_PLANNING => true,
					self::CAP_VIEW_INVENTORY_SHELL => true,
					self::CAP_RESP_EVENT_PLANNING_ACCESS => true,
				),
				'is_builtin_template' => true,
			),
			self::ROLE_MANAGER_USER => array(
				'role_slug'        => self::ROLE_MANAGER_USER,
				'role_name'        => 'AIMS Manager User',
				'template_key'     => self::ROLE_MANAGER_USER,
				'description'      => 'Management role with broad planning and reporting access.',
				'person_subtypes'  => array( AIMS_Person_Identity_Service::SUBTYPE_MANAGER ),
				'caps'             => array(
					'read'                   => true,
					self::CAP_VIEW_DASHBOARD => true,
					self::CAP_VIEW_REPORTS   => true,
					self::CAP_VIEW_EVENTS_SHELL => true,
					self::CAP_MANAGE_EVENT_PLANNING => true,
					self::CAP_VIEW_INVENTORY_SHELL => true,
					self::CAP_RESP_EVENT_PLANNING_ACCESS => true,
					self::CAP_RESP_REPORTS_VIEW => true,
				),
				'is_builtin_template' => true,
			),
		);
	}

	public static function get_portal_roles(): array {
		$roles = array();

		foreach ( self::get_runtime_role_definitions() as $definition ) {
			$roles[ $definition['role_slug'] ] = (string) $definition['role_name'];
		}

		return $roles;
	}

	public static function get_aims_role_slugs(): array {
		return self::get_runtime_role_slugs();
	}

	public static function get_role_definitions(): array {
		$definitions = self::get_role_templates();

		foreach ( self::get_custom_role_registry() as $role_slug => $definition ) {
			$definitions[ $role_slug ] = $definition;
		}

		return $definitions;
	}

	public static function get_runtime_role_definitions(): array {
		return self::get_custom_role_registry();
	}

	public static function get_role_template_slugs(): array {
		return array_keys( self::get_role_templates() );
	}

	public static function get_runtime_role_slugs(): array {
		return array_keys( self::get_runtime_role_definitions() );
	}

	public static function get_role_definition( string $role_slug ): ?array {
		$role_slug = sanitize_key( $role_slug );
		if ( '' === $role_slug ) {
			return null;
		}

		$definitions = self::get_role_definitions();
		return isset( $definitions[ $role_slug ] ) ? $definitions[ $role_slug ] : null;
	}

	public static function get_person_subtypes_for_role( string $role_slug ): array {
		$definition = self::get_role_definition( $role_slug );
		if ( ! is_array( $definition ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_key', (array) ( $definition['person_subtypes'] ?? array() ) ) ) );
	}

	public static function get_role_slugs_for_person_subtype( string $subtype ): array {
		return self::get_role_slugs_for_person_subtype_runtime( $subtype, true );
	}

	public static function get_role_slugs_for_person_subtype_runtime( string $subtype, bool $include_templates = false ): array {
		$subtype = sanitize_key( $subtype );
		if ( '' === $subtype ) {
			return array();
		}

		$matches = array();
		$definitions = $include_templates ? self::get_role_definitions() : self::get_runtime_role_definitions();

		foreach ( $definitions as $definition ) {
			if ( in_array( $subtype, (array) ( $definition['person_subtypes'] ?? array() ), true ) ) {
				$matches[] = (string) $definition['role_slug'];
			}
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $matches ) ) ) );
	}

	public static function create_or_update_custom_role( string $role_slug, string $role_name, string $template_key = '', array $caps = array(), array $person_subtypes = array() ): array {
		$role_slug = sanitize_key( $role_slug );
		$role_name = sanitize_text_field( $role_name );

		if ( '' !== $role_slug && 0 !== strpos( $role_slug, 'aims_' ) ) {
			$role_slug = 'aims_custom_' . $role_slug;
		}

		if ( '' === $role_slug || '' === $role_name ) {
			return array(
				'success' => false,
				'message' => 'Role name and slug are required.',
			);
		}

		if ( isset( self::get_role_templates()[ $role_slug ] ) ) {
			return array(
				'success' => false,
				'message' => 'Built-in role templates cannot be overwritten.',
			);
		}

		$template_key = sanitize_key( $template_key );
		$template     = self::get_role_templates()[ $template_key ] ?? null;
		$existing_registry  = self::get_custom_role_registry();
		$is_existing_custom = isset( $existing_registry[ $role_slug ] );
		$resolved_caps      = self::normalize_caps( $caps );
		$resolved_subtypes = array_values( array_unique( array_filter( array_map( 'sanitize_key', $person_subtypes ) ) ) );

		if ( is_array( $template ) ) {
			if ( empty( $resolved_caps ) && ! $is_existing_custom ) {
				$resolved_caps = self::normalize_caps( (array) ( $template['caps'] ?? array() ) );
			}

			$resolved_subtypes = array_values(
				array_unique(
					array_merge(
						(array) ( $template['person_subtypes'] ?? array() ),
						$resolved_subtypes
					)
				)
			);
		}

		$resolved_caps['read'] = true;

		$definition = array(
			'role_slug'            => $role_slug,
			'role_name'            => $role_name,
			'template_key'         => $template_key,
			'description'          => is_array( $template ) ? (string) ( $template['description'] ?? '' ) : '',
			'person_subtypes'      => $resolved_subtypes,
			'caps'                 => self::normalize_caps( $resolved_caps ),
			'is_builtin_template'  => false,
		);

		$registry               = $existing_registry;
		$registry[ $role_slug ] = $definition;
		self::persist_custom_role_registry( $registry );
		self::sync_role_definition( $definition );

		return array(
			'success' => true,
			'message' => 'Role saved.',
			'role'    => $definition,
		);
	}

	public static function delete_custom_role( string $role_slug ): bool {
		$role_slug = sanitize_key( $role_slug );
		if ( '' === $role_slug || isset( self::get_role_templates()[ $role_slug ] ) ) {
			return false;
		}

		$registry = self::get_custom_role_registry();
		if ( ! isset( $registry[ $role_slug ] ) ) {
			return false;
		}

		unset( $registry[ $role_slug ] );
		self::persist_custom_role_registry( $registry );
		remove_role( $role_slug );

		return true;
	}

	public static function get_custom_role_registry(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$stored = get_option( self::OPTION_CUSTOM_ROLE_REGISTRY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$registry = array();
		foreach ( $stored as $role_slug => $definition ) {
			$role_slug = sanitize_key( (string) $role_slug );
			if ( '' === $role_slug || ! is_array( $definition ) ) {
				continue;
			}

			$registry[ $role_slug ] = array(
				'role_slug'           => $role_slug,
				'role_name'           => sanitize_text_field( (string) ( $definition['role_name'] ?? $role_slug ) ),
				'template_key'        => sanitize_key( (string) ( $definition['template_key'] ?? '' ) ),
				'description'         => sanitize_text_field( (string) ( $definition['description'] ?? '' ) ),
				'person_subtypes'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $definition['person_subtypes'] ?? array() ) ) ) ),
				'caps'                => self::normalize_caps( (array) ( $definition['caps'] ?? array() ) ),
				'is_builtin_template' => false,
			);
		}

		return $registry;
	}

	private static function persist_custom_role_registry( array $registry ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_CUSTOM_ROLE_REGISTRY, $registry );
		}
	}

	private static function remove_template_roles_from_runtime(): void {
		foreach ( self::get_role_template_slugs() as $role_slug ) {
			if ( null !== get_role( $role_slug ) ) {
				remove_role( $role_slug );
			}
		}
	}

	private static function migrate_template_role_users_to_runtime_roles(): void {
		if ( ! function_exists( 'get_users' ) ) {
			return;
		}

		foreach ( self::get_role_templates() as $template_slug => $template ) {
			$users = get_users(
				array(
					'role__in' => array( $template_slug ),
					'fields'   => array( 'ID' ),
				)
			);

			if ( ! is_array( $users ) || empty( $users ) ) {
				continue;
			}

			$runtime_slug = self::get_migrated_runtime_role_slug( (string) $template_slug );
			if ( ! isset( self::get_custom_role_registry()[ $runtime_slug ] ) ) {
				self::create_or_update_custom_role(
					$runtime_slug,
					(string) ( $template['role_name'] ?? $runtime_slug ) . ' (Migrated)',
					(string) $template_slug,
					(array) ( $template['caps'] ?? array() ),
					(array) ( $template['person_subtypes'] ?? array() )
				);
			}

			foreach ( $users as $user_row ) {
				$user_id = is_object( $user_row ) ? (int) ( $user_row->ID ?? 0 ) : (int) $user_row;
				if ( $user_id <= 0 ) {
					continue;
				}

				self::replace_user_role( $user_id, (string) $template_slug, $runtime_slug );
			}
		}
	}

	private static function get_migrated_runtime_role_slug( string $template_slug ): string {
		$suffix = preg_replace( '/^aims_/', '', sanitize_key( $template_slug ) );

		return 'aims_migrated_' . $suffix;
	}

	private static function replace_user_role( int $user_id, string $old_role_slug, string $new_role_slug ): void {
		if ( $user_id <= 0 || '' === $old_role_slug || '' === $new_role_slug ) {
			return;
		}

		if ( class_exists( 'WP_User' ) ) {
			$user = new WP_User( $user_id );
			if ( method_exists( $user, 'add_role' ) ) {
				$user->add_role( $new_role_slug );
			}
			if ( method_exists( $user, 'remove_role' ) ) {
				$user->remove_role( $old_role_slug );
			}
			return;
		}

		if ( ! function_exists( 'get_user_by' ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! is_object( $user ) ) {
			return;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? array_values( array_map( 'sanitize_key', $user->roles ) ) : array();
		$roles = array_values( array_diff( $roles, array( sanitize_key( $old_role_slug ) ) ) );
		if ( ! in_array( sanitize_key( $new_role_slug ), $roles, true ) ) {
			$roles[] = sanitize_key( $new_role_slug );
		}

		$user->roles = $roles;
	}

	private static function sync_role_definition( array $definition ): void {
		$role_slug = sanitize_key( (string) ( $definition['role_slug'] ?? '' ) );
		$role_name = sanitize_text_field( (string) ( $definition['role_name'] ?? '' ) );
		$caps      = self::normalize_caps( (array) ( $definition['caps'] ?? array() ) );

		if ( '' === $role_slug || '' === $role_name ) {
			return;
		}

		$caps['read'] = true;

		$role = get_role( $role_slug );
		if ( null === $role ) {
			add_role( $role_slug, $role_name, $caps );
			return;
		}

		$managed_caps = array_values( array_unique( array_merge( self::get_caps(), array( 'read' ) ) ) );
		foreach ( $managed_caps as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				$role->add_cap( $cap );
				continue;
			}

			$role->remove_cap( $cap );
		}
	}

	private static function normalize_caps( array $caps ): array {
		$normalized = array();

		foreach ( $caps as $cap => $enabled ) {
			$cap = sanitize_key( is_string( $cap ) ? $cap : (string) $cap );
			if ( '' === $cap || ! $enabled ) {
				continue;
			}

			$normalized[ $cap ] = true;
		}

		return $normalized;
	}
}
