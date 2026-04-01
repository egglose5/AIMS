<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Endpoint_Directory_Service {
	public const OPTION_DIRECTORY = 'aims_inventory_endpoint_directory';
	public const ENDPOINT_VENDOR = 'vendor';
	public const ENDPOINT_SUPERVISOR = 'supervisor';
	public const ENDPOINT_WAREHOUSE = 'warehouse';

	private $responsibility_auth;
	private $person_identity;
	private $endpoint_repository;
	private $route_guidance;

	public function __construct(
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null,
		AIMS_Person_Identity_Service $person_identity = null,
		AIMS_Inventory_Custody_Endpoint_Repository $endpoint_repository = null,
		AIMS_Inventory_Custody_Route_Guidance_Service $route_guidance = null
	) {
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
		$this->person_identity     = $person_identity ?: ( class_exists( 'AIMS_Person_Identity_Service' ) ? new AIMS_Person_Identity_Service() : null );
		$this->endpoint_repository = $endpoint_repository ?: ( class_exists( 'AIMS_Inventory_Custody_Endpoint_Repository' ) ? new AIMS_Inventory_Custody_Endpoint_Repository() : null );
		$this->route_guidance      = $route_guidance ?: ( class_exists( 'AIMS_Inventory_Custody_Route_Guidance_Service' ) ? new AIMS_Inventory_Custody_Route_Guidance_Service() : null );
	}

	public function get_directory(): array {
		$directory = $this->get_default_directory();
		$stored    = get_option( self::OPTION_DIRECTORY, array() );

		if ( is_array( $stored ) ) {
			foreach ( $stored as $endpoint_key => $endpoint ) {
				$endpoint_key = sanitize_key( (string) $endpoint_key );
				if ( '' === $endpoint_key || ! is_array( $endpoint ) ) {
					continue;
				}

				$directory[ $endpoint_key ] = $this->normalize_endpoint( array_merge( $directory[ $endpoint_key ] ?? array(), $endpoint ) );
			}
		}

		foreach ( $directory as $endpoint_key => $endpoint ) {
			$directory[ $endpoint_key ] = $this->normalize_endpoint( $endpoint );
		}

		return $directory;
	}

	public function get_endpoint( string $endpoint_key ): ?array {
		$endpoint_key = sanitize_key( $endpoint_key );
		if ( '' === $endpoint_key ) {
			return null;
		}

		$directory = $this->get_directory();
		if ( ! isset( $directory[ $endpoint_key ] ) ) {
			$runtime = $this->get_runtime_endpoints( 0 );
			if ( ! isset( $runtime[ $endpoint_key ] ) ) {
				return null;
			}

			return $runtime[ $endpoint_key ];
		}

		return $directory[ $endpoint_key ];
	}

	public function get_runtime_endpoints( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );
		$persisted = $this->get_persisted_runtime_endpoints( $user_id );

		if ( ! empty( $persisted ) ) {
			return $persisted;
		}

		$templates = $this->get_directory();
		$choices   = array();

		if ( $this->endpoint_is_available_for_user( self::ENDPOINT_WAREHOUSE, $user_id ) && isset( $templates[ self::ENDPOINT_WAREHOUSE ] ) ) {
			$warehouse = $this->build_runtime_endpoint(
				$templates[ self::ENDPOINT_WAREHOUSE ],
				'warehouse_main',
				'Main Warehouse',
				1,
				self::ENDPOINT_WAREHOUSE,
				array(
					'is_current' => $this->user_prefers_endpoint_type( $user_id, self::ENDPOINT_WAREHOUSE ),
				)
			);

			$choices['warehouse']      = array_merge( $warehouse, array( 'endpoint_key' => 'warehouse', 'is_alias' => true ) );
			$choices['warehouse_main'] = $warehouse;
		}

		$self_supervisor = $this->maybe_build_user_endpoint( $user_id, self::ENDPOINT_SUPERVISOR, $templates );
		if ( ! empty( $self_supervisor ) ) {
			$choices['supervisor'] = array_merge( $self_supervisor, array( 'endpoint_key' => 'supervisor', 'is_alias' => true ) );
			$choices[ $self_supervisor['endpoint_key'] ] = $self_supervisor;
		}

		$self_vendor = $this->maybe_build_user_endpoint( $user_id, self::ENDPOINT_VENDOR, $templates );
		if ( ! empty( $self_vendor ) ) {
			$choices['vendor'] = array_merge( $self_vendor, array( 'endpoint_key' => 'vendor', 'is_alias' => true ) );
			$choices[ $self_vendor['endpoint_key'] ] = $self_vendor;
		}

		if ( $this->can_directory_enumerate_transfer_targets( $user_id ) ) {
			foreach ( $this->get_runtime_users_for_role( 'aims_supervisor_user' ) as $supervisor ) {
				$endpoint = $this->build_user_endpoint_from_user( $supervisor, self::ENDPOINT_SUPERVISOR, $templates );
				if ( ! empty( $endpoint ) ) {
					$choices[ $endpoint['endpoint_key'] ] = $endpoint;
				}
			}

			foreach ( $this->get_runtime_users_for_role( 'aims_vendor_user' ) as $vendor_user ) {
				$endpoint = $this->build_user_endpoint_from_user( $vendor_user, self::ENDPOINT_VENDOR, $templates );
				if ( ! empty( $endpoint ) ) {
					$choices[ $endpoint['endpoint_key'] ] = $endpoint;
				}
			}
		}

		if ( empty( $choices ) && isset( $templates[ self::ENDPOINT_VENDOR ] ) ) {
			$choices[ self::ENDPOINT_VENDOR ] = $this->build_runtime_endpoint(
				$templates[ self::ENDPOINT_VENDOR ],
				self::ENDPOINT_VENDOR,
				'Vendor',
				max( 0, $user_id ),
				self::ENDPOINT_VENDOR
			);
		}

		return $choices;
	}

	public function resolve_runtime_endpoint( int $user_id = 0, string $preferred_endpoint_key = '' ): array {
		$user_id = $this->resolve_user_id( $user_id );
		$directory = $this->get_runtime_endpoints( $user_id );
		$preferred_endpoint_key = sanitize_key( $preferred_endpoint_key );

		if ( '' !== $preferred_endpoint_key && isset( $directory[ $preferred_endpoint_key ] ) ) {
			return $directory[ $preferred_endpoint_key ];
		}

		foreach ( $directory as $endpoint ) {
			if ( ! empty( $endpoint['is_alias'] ) ) {
				continue;
			}

			if ( ! empty( $endpoint['is_current'] ) ) {
				return $endpoint;
			}
		}

		foreach ( array( 'warehouse_main', 'supervisor_' . $user_id, 'vendor_' . $user_id, self::ENDPOINT_WAREHOUSE, self::ENDPOINT_SUPERVISOR, self::ENDPOINT_VENDOR ) as $endpoint_key ) {
			if ( isset( $directory[ $endpoint_key ] ) ) {
				return $directory[ $endpoint_key ];
			}
		}

		return $this->normalize_endpoint(
			array(
				'endpoint_key'   => self::ENDPOINT_VENDOR,
				'endpoint_type'  => self::ENDPOINT_VENDOR,
				'endpoint_label' => 'Vendor',
				'node_type'      => 'vendor',
				'node_id'        => max( 0, $user_id ),
			)
		);
	}

	public function resolve_endpoint_from_node( int $node_id, string $node_type = '', int $user_id = 0 ): array {
		$node_id = max( 0, $node_id );
		$node_type = sanitize_key( $node_type );

		$runtime = $this->get_runtime_endpoints( $user_id );
		foreach ( $runtime as $endpoint ) {
			if ( ! is_array( $endpoint ) ) {
				continue;
			}

			if ( $node_id > 0 && (int) ( $endpoint['node_id'] ?? 0 ) !== $node_id ) {
				continue;
			}

			$endpoint_type = sanitize_key( (string) ( $endpoint['endpoint_type'] ?? $endpoint['node_type'] ?? '' ) );
			if ( '' !== $node_type && $node_type !== $endpoint_type && $node_type !== sanitize_key( (string) ( $endpoint['node_type'] ?? '' ) ) ) {
				continue;
			}

			return $endpoint;
		}

		if ( '' !== $node_type && in_array( $node_type, array( self::ENDPOINT_VENDOR, self::ENDPOINT_SUPERVISOR, self::ENDPOINT_WAREHOUSE ), true ) ) {
			$endpoint = $this->get_endpoint( $node_type );
			if ( is_array( $endpoint ) ) {
				return array_merge( $endpoint, array( 'node_id' => $node_id > 0 ? $node_id : (int) ( $endpoint['node_id'] ?? 0 ) ) );
			}
		}

		return $this->resolve_runtime_endpoint( $user_id );
	}

	public function get_persisted_runtime_endpoints( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );
		$node_ref = $this->resolve_user_node_reference( $user_id );

		if ( ! is_object( $this->endpoint_repository ) ) {
			return array();
		}

		$node_ref_type = sanitize_key( (string) ( $node_ref['node_ref_type'] ?? '' ) );
		$node_ref_id   = (int) ( $node_ref['node_ref_id'] ?? 0 );
		if ( '' === $node_ref_type || $node_ref_id <= 0 ) {
			return array();
		}

		$endpoints = array();
		$rows = array();
		if ( method_exists( $this->endpoint_repository, 'get_active_for_node' ) ) {
			$rows = $this->endpoint_repository->get_active_for_node( $node_ref_type, $node_ref_id );
		} elseif ( method_exists( $this->endpoint_repository, 'get_for_node' ) ) {
			$rows = $this->endpoint_repository->get_for_node(
				$node_ref_type,
				$node_ref_id,
				array( 'endpoint_status' => AIMS_Inventory_Custody_Endpoint_Repository::STATUS_ACTIVE )
			);
		}

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$endpoint_key = sanitize_key( (string) ( $row['endpoint_key'] ?? '' ) );
			if ( '' === $endpoint_key ) {
				continue;
			}

			$endpoints[ $endpoint_key ] = $this->normalize_endpoint(
				array_merge(
					$row,
					array(
						'endpoint_key'  => $endpoint_key,
						'endpoint_type'  => (string) ( $row['endpoint_type'] ?? $row['node_type'] ?? $node_ref_type ),
						'node_type'     => (string) ( $row['node_type'] ?? $node_ref_type ),
						'node_id'       => (int) ( $row['node_ref_id'] ?? $node_ref_id ),
						'is_current'    => true,
					)
				)
			);
		}

		return $endpoints;
	}

	public function get_endpoint_choices( int $user_id = 0 ): array {
		$choices = array();
		foreach ( $this->get_runtime_endpoints( $user_id ) as $endpoint_key => $endpoint ) {
			$choices[ $endpoint_key ] = (string) ( $endpoint['endpoint_label'] ?? $endpoint_key );
		}

		return $choices;
	}

	public function get_route_suggestions( int $user_id = 0 ): array {
		$runtime_guidance = $this->call_route_guidance( $user_id );
		if ( is_array( $runtime_guidance ) && ! empty( $runtime_guidance['success'] ) && ! empty( $runtime_guidance['guidance'] ) ) {
			$suggestions = $this->normalize_route_guidance_suggestions( $runtime_guidance );
			if ( ! empty( $suggestions ) ) {
				return $suggestions;
			}
		}

		$current     = $this->resolve_runtime_endpoint( $user_id );
		$directory   = $this->get_runtime_endpoints( $user_id );
		$suggestions = array();
		$current_key = sanitize_key( (string) ( $current['endpoint_key'] ?? '' ) );
		$preferred_target_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $current['suggested_targets'] ?? array() ) ) ) );

		foreach ( $directory as $target_key => $target_endpoint ) {
			$target_key = sanitize_key( (string) $target_key );
			if ( '' === $target_key || $target_key === $current_key || ! is_array( $target_endpoint ) || ! empty( $target_endpoint['is_alias'] ) ) {
				continue;
			}

			$target_type = sanitize_key( (string) ( $target_endpoint['endpoint_type'] ?? $target_endpoint['node_type'] ?? '' ) );
			if ( ! empty( $preferred_target_types ) && ! in_array( $target_type, $preferred_target_types, true ) ) {
				continue;
			}

			$suggestions[] = array(
				'source_endpoint_key' => $current_key,
				'source_label'        => (string) ( $current['endpoint_label'] ?? $current_key ),
				'target_endpoint_key' => $target_key,
				'target_label'        => (string) ( $target_endpoint['endpoint_label'] ?? $target_key ),
				'label'               => sprintf(
					'%s -> %s',
					(string) ( $current['endpoint_label'] ?? $current_key ),
					(string) ( $target_endpoint['endpoint_label'] ?? $target_key )
				),
			);
		}

		return $suggestions;
	}

	public function get_suggested_route_label( int $user_id = 0 ): string {
		$runtime_guidance = $this->call_route_guidance( $user_id );
		if ( is_array( $runtime_guidance ) && ! empty( $runtime_guidance['success'] ) ) {
			foreach ( (array) ( $runtime_guidance['guidance'] ?? array() ) as $guidance ) {
				if ( ! is_array( $guidance ) ) {
					continue;
				}

				$default_route = is_array( $guidance['default_route'] ?? null ) ? $guidance['default_route'] : array();
				if ( ! empty( $default_route['guidance_label'] ) ) {
					return sanitize_text_field( (string) $default_route['guidance_label'] );
				}
			}
		}

		$current = $this->resolve_runtime_endpoint( $user_id );

		return sprintf(
			'%s default dispatch guidance',
			(string) ( $current['endpoint_label'] ?? 'Transfer' )
		);
	}

	public function get_suggested_route_note( int $user_id = 0 ): string {
		$runtime_guidance = $this->call_route_guidance( $user_id );
		if ( is_array( $runtime_guidance ) && ! empty( $runtime_guidance['success'] ) ) {
			$notes = array();
			foreach ( (array) $runtime_guidance['guidance'] as $guidance ) {
				if ( ! is_array( $guidance ) ) {
					continue;
				}

				$default_route = is_array( $guidance['default_route'] ?? null ) ? $guidance['default_route'] : array();
				$parts = array();
				if ( ! empty( $default_route['guidance_notes'] ) ) {
					$parts[] = sanitize_textarea_field( (string) $default_route['guidance_notes'] );
				}
				if ( ! empty( $default_route['guidance_label'] ) ) {
					$parts[] = sanitize_text_field( (string) $default_route['guidance_label'] );
				}

				if ( ! empty( $parts ) ) {
					$notes[] = implode( ' | ', array_values( array_unique( $parts ) ) );
				}
			}

			if ( ! empty( $notes ) ) {
				return implode( ' ', array_values( array_unique( $notes ) ) );
			}
		}

		$suggestions = $this->get_route_suggestions( $user_id );
		if ( empty( $suggestions ) ) {
			return 'Default route guidance is available, but elevated operators can still collect directly with an audit reason.';
		}

		$targets = array_map(
			static function ( array $suggestion ): string {
				return (string) ( $suggestion['target_label'] ?? '' );
			},
			$suggestions
		);
		$targets = array_values( array_unique( array_filter( $targets ) ) );

		return 'Suggested targets for the current custody node: ' . implode( ', ', $targets ) . '. Overrides remain available for elevated operators with an audit reason.';
	}

	private function get_default_directory(): array {
		return array(
			self::ENDPOINT_VENDOR => $this->normalize_endpoint(
				array(
					'endpoint_key'       => self::ENDPOINT_VENDOR,
					'endpoint_label'     => 'Vendor',
					'node_type'          => 'vendor',
					'priority'           => 30,
					'bucket_statuses'    => array( 'available', 'staged' ),
					'current_location_types' => array( 'vendor', 'staging' ),
					'suggested_targets'  => array( self::ENDPOINT_WAREHOUSE, self::ENDPOINT_SUPERVISOR ),
				)
			),
			self::ENDPOINT_SUPERVISOR => $this->normalize_endpoint(
				array(
					'endpoint_key'       => self::ENDPOINT_SUPERVISOR,
					'endpoint_label'     => 'Supervisor',
					'node_type'          => 'supervisor',
					'priority'           => 20,
					'bucket_statuses'    => array( 'available', 'staged' ),
					'current_location_types' => array( 'vendor', 'warehouse', 'staging' ),
					'suggested_targets'  => array( self::ENDPOINT_WAREHOUSE, self::ENDPOINT_VENDOR ),
				)
			),
			self::ENDPOINT_WAREHOUSE => $this->normalize_endpoint(
				array(
					'endpoint_key'       => self::ENDPOINT_WAREHOUSE,
					'endpoint_label'     => 'Warehouse',
					'node_type'          => 'warehouse',
					'priority'           => 10,
					'bucket_statuses'    => array( 'available', 'staged', 'in_transit' ),
					'current_location_types' => array( 'warehouse', 'staging' ),
					'suggested_targets'  => array( self::ENDPOINT_VENDOR, self::ENDPOINT_SUPERVISOR ),
				)
			),
		);
	}

	private function endpoint_is_available_for_user( string $endpoint_key, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		switch ( sanitize_key( $endpoint_key ) ) {
			case self::ENDPOINT_WAREHOUSE:
				return current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY )
					|| current_user_can( AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS )
					|| current_user_can( AIMS_Capabilities::CAP_MANAGE_PHYSICAL_BUCKETS )
					|| current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_BUCKETS );

			case self::ENDPOINT_SUPERVISOR:
				return current_user_can( AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL )
					|| current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING );

			case self::ENDPOINT_VENDOR:
			default:
				return current_user_can( AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL )
					|| current_user_can( AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL )
					|| current_user_can( AIMS_Capabilities::CAP_MANAGE_VENDOR_ACCESS );
		}
	}

	private function normalize_endpoint( array $endpoint ): array {
		$endpoint_key = sanitize_key( (string) ( $endpoint['endpoint_key'] ?? '' ) );
		if ( '' === $endpoint_key ) {
			$endpoint_key = self::ENDPOINT_VENDOR;
		}

		return array(
			'endpoint_key'          => $endpoint_key,
			'endpoint_type'         => sanitize_key( (string) ( $endpoint['endpoint_type'] ?? $endpoint['node_type'] ?? $endpoint_key ) ),
			'endpoint_label'        => sanitize_text_field( (string) ( $endpoint['endpoint_label'] ?? ucfirst( $endpoint_key ) ) ),
			'node_type'             => sanitize_key( (string) ( $endpoint['node_type'] ?? $endpoint_key ) ),
			'node_id'               => max( 0, (int) ( $endpoint['node_id'] ?? 0 ) ),
			'vendor_id'             => max( 0, (int) ( $endpoint['vendor_id'] ?? 0 ) ),
			'vendor_ids'            => array_values( array_filter( array_map( 'intval', (array) ( $endpoint['vendor_ids'] ?? array() ) ) ) ),
			'priority'              => max( 0, (int) ( $endpoint['priority'] ?? 0 ) ),
			'bucket_statuses'       => array_values( array_filter( array_map( 'sanitize_key', (array) ( $endpoint['bucket_statuses'] ?? array() ) ) ) ),
			'current_location_types'=> array_values( array_filter( array_map( 'sanitize_key', (array) ( $endpoint['current_location_types'] ?? array() ) ) ) ),
			'suggested_targets'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $endpoint['suggested_targets'] ?? array() ) ) ) ),
			'is_alias'              => ! empty( $endpoint['is_alias'] ),
			'is_current'            => ! empty( $endpoint['is_current'] ),
		);
	}

	private function resolve_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	private function resolve_user_node_reference( int $user_id ): array {
		$user_id = $this->resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return array(
				'node_ref_type' => '',
				'node_ref_id'   => 0,
			);
		}

		$node_ref_type = '';
		if ( is_object( $this->person_identity ) ) {
			if ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_STITCH ) ) {
				$node_ref_type = 'stitcher';
			} elseif ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR ) ) {
				$node_ref_type = 'vendor';
			} elseif ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_MANAGER ) ) {
				$node_ref_type = 'supervisor';
			}
		}

		if ( '' === $node_ref_type ) {
			if ( current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY ) || current_user_can( AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS ) ) {
				$node_ref_type = 'warehouse';
			} elseif ( current_user_can( AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL ) || current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING ) ) {
				$node_ref_type = 'supervisor';
			} else {
				$node_ref_type = 'vendor';
			}
		}

		return array(
			'node_ref_type' => sanitize_key( $node_ref_type ),
			'node_ref_id'   => $user_id,
		);
	}

	private function call_route_guidance( int $user_id ): ?array {
		if ( is_object( $this->route_guidance ) && method_exists( $this->route_guidance, 'get_route_guidance_for_runtime_user' ) ) {
			return $this->route_guidance->get_route_guidance_for_runtime_user( $user_id );
		}

		$node_ref = $this->resolve_user_node_reference( $user_id );
		if ( '' === (string) ( $node_ref['node_ref_type'] ?? '' ) || (int) ( $node_ref['node_ref_id'] ?? 0 ) <= 0 ) {
			return null;
		}

		if ( is_object( $this->route_guidance ) && method_exists( $this->route_guidance, 'get_route_guidance_for_node' ) ) {
			return $this->route_guidance->get_route_guidance_for_node(
				(string) $node_ref['node_ref_type'],
				(int) $node_ref['node_ref_id']
			);
		}

		return null;
	}

	private function normalize_route_guidance_suggestions( array $runtime_guidance ): array {
		$suggestions = array();

		foreach ( (array) ( $runtime_guidance['guidance'] ?? array() ) as $guidance ) {
			if ( ! is_array( $guidance ) ) {
				continue;
			}

			$endpoint = is_array( $guidance['endpoint'] ?? null ) ? $guidance['endpoint'] : array();
			$current_key = sanitize_key( (string) ( $endpoint['endpoint_key'] ?? $endpoint['endpoint_type'] ?? '' ) );
			foreach ( (array) ( $guidance['routes'] ?? array() ) as $route ) {
				if ( ! is_array( $route ) ) {
					continue;
				}

				$relationship = is_array( $route['relationship'] ?? null ) ? $route['relationship'] : array();
				$target = is_array( $route['target_endpoint'] ?? null ) ? $route['target_endpoint'] : array();
				$target_key = sanitize_key( (string) ( $target['endpoint_key'] ?? $target['endpoint_type'] ?? '' ) );

				if ( '' === $current_key || '' === $target_key || $current_key === $target_key ) {
					continue;
				}

				$suggestions[] = array(
					'source_endpoint_key' => $current_key,
					'source_label'        => (string) ( $endpoint['endpoint_label'] ?? $current_key ),
					'target_endpoint_key' => $target_key,
					'target_label'        => (string) ( $target['endpoint_label'] ?? $target_key ),
					'label'               => ! empty( $relationship['guidance_label'] )
						? sanitize_text_field( (string) $relationship['guidance_label'] )
						: sprintf(
							'%s -> %s',
							(string) ( $endpoint['endpoint_label'] ?? $current_key ),
							(string) ( $target['endpoint_label'] ?? $target_key )
						),
				);
			}
		}

		return $suggestions;
	}

	private function build_runtime_endpoint( array $template, string $endpoint_key, string $label, int $node_id, string $endpoint_type, array $extra = array() ): array {
		return $this->normalize_endpoint(
			array_merge(
				$template,
				$extra,
				array(
					'endpoint_key'   => sanitize_key( $endpoint_key ),
					'endpoint_type'  => sanitize_key( $endpoint_type ),
					'endpoint_label' => $label,
					'node_type'      => sanitize_key( $endpoint_type ),
					'node_id'        => max( 0, $node_id ),
				)
			)
		);
	}

	private function maybe_build_user_endpoint( int $user_id, string $endpoint_type, array $templates ): array {
		if ( $user_id <= 0 || empty( $templates[ $endpoint_type ] ) || ! $this->user_matches_endpoint_type( $user_id, $endpoint_type ) ) {
			return array();
		}

		$user = $this->load_user( $user_id );
		if ( ! is_object( $user ) ) {
			return array();
		}

		return $this->build_user_endpoint_from_user( $user, $endpoint_type, $templates, true );
	}

	private function build_user_endpoint_from_user( $user, string $endpoint_type, array $templates, bool $is_current = false ): array {
		if ( ! is_object( $user ) || empty( $user->ID ) || empty( $templates[ $endpoint_type ] ) ) {
			return array();
		}

		$user_id = (int) $user->ID;
		$label   = $this->build_user_endpoint_label( $user, $endpoint_type );
		$vendor_ids = array();

		if ( self::ENDPOINT_VENDOR === $endpoint_type || self::ENDPOINT_SUPERVISOR === $endpoint_type ) {
			$vendor_ids[] = $user_id;
		}

		return $this->build_runtime_endpoint(
			$templates[ $endpoint_type ],
			$endpoint_type . '_' . $user_id,
			$label,
			$user_id,
			$endpoint_type,
			array(
				'vendor_id'  => self::ENDPOINT_VENDOR === $endpoint_type ? $user_id : 0,
				'vendor_ids' => $vendor_ids,
				'is_current' => $is_current,
			)
		);
	}

	private function build_user_endpoint_label( $user, string $endpoint_type ): string {
		$name = sanitize_text_field( (string) ( $user->display_name ?? $user->user_login ?? 'Endpoint' ) );

		switch ( $endpoint_type ) {
			case self::ENDPOINT_SUPERVISOR:
				return $name . ' Supervisor Pool';

			case self::ENDPOINT_VENDOR:
			default:
				return $name . ' Vendor Pool';
		}
	}

	private function can_directory_enumerate_transfer_targets( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_PRODUCTION )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_VENDOR_ACCESS )
			|| current_user_can( AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL );
	}

	private function get_runtime_users_for_role( string $role_slug ): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'role' => sanitize_key( $role_slug ),
			)
		);

		return is_array( $users ) ? $users : array();
	}

	private function load_user( int $user_id ) {
		if ( $user_id <= 0 || ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		return get_user_by( 'id', $user_id );
	}

	private function user_prefers_endpoint_type( int $user_id, string $endpoint_type ): bool {
		switch ( $endpoint_type ) {
			case self::ENDPOINT_WAREHOUSE:
				return current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY );

			case self::ENDPOINT_SUPERVISOR:
				return $this->user_matches_endpoint_type( $user_id, self::ENDPOINT_SUPERVISOR );

			case self::ENDPOINT_VENDOR:
			default:
				return $this->user_matches_endpoint_type( $user_id, self::ENDPOINT_VENDOR );
		}
	}

	private function user_matches_endpoint_type( int $user_id, string $endpoint_type ): bool {
		$user = $this->load_user( $user_id );
		if ( ! is_object( $user ) ) {
			return false;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? array_map( 'sanitize_key', $user->roles ) : array();

		switch ( $endpoint_type ) {
			case self::ENDPOINT_SUPERVISOR:
				return in_array( 'aims_supervisor_user', $roles, true ) || current_user_can( AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL );

			case self::ENDPOINT_VENDOR:
				if ( in_array( 'aims_vendor_user', $roles, true ) ) {
					return true;
				}

				return is_object( $this->person_identity ) && $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR );

			case self::ENDPOINT_WAREHOUSE:
				return current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY );

			default:
				return false;
		}
	}
}
