<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Custody_Endpoint_Directory_Service {
	public const TYPE_WAREHOUSE = AIMS_Inventory_Custody_Endpoint_Repository::TYPE_WAREHOUSE;
	public const TYPE_SUPERVISOR = AIMS_Inventory_Custody_Endpoint_Repository::TYPE_SUPERVISOR;
	public const TYPE_VENDOR    = AIMS_Inventory_Custody_Endpoint_Repository::TYPE_VENDOR;
	public const TYPE_STITCHER  = AIMS_Inventory_Custody_Endpoint_Repository::TYPE_STITCHER;
	public const TYPE_EVENT     = AIMS_Inventory_Custody_Endpoint_Repository::TYPE_EVENT;
	public const TYPE_CUSTOM    = AIMS_Inventory_Custody_Endpoint_Repository::TYPE_CUSTOM;

	private $endpoints;
	private $route_guidance;
	private $person_identity;

	public function __construct(
		AIMS_Inventory_Custody_Endpoint_Repository $endpoints = null,
		AIMS_Inventory_Custody_Route_Guidance_Service $route_guidance = null,
		AIMS_Person_Identity_Service $person_identity = null
	) {
		$this->endpoints = $endpoints ?: new AIMS_Inventory_Custody_Endpoint_Repository();
		$this->route_guidance = $route_guidance ?: ( class_exists( 'AIMS_Inventory_Custody_Route_Guidance_Service' ) ? new AIMS_Inventory_Custody_Route_Guidance_Service() : null );
		$this->person_identity = $person_identity ?: ( class_exists( 'AIMS_Person_Identity_Service' ) ? new AIMS_Person_Identity_Service() : null );
	}

	public function get_endpoint_types(): array {
		return array(
			self::TYPE_WAREHOUSE,
			self::TYPE_SUPERVISOR,
			self::TYPE_VENDOR,
			self::TYPE_STITCHER,
			self::TYPE_EVENT,
			self::TYPE_CUSTOM,
		);
	}

	public function save_endpoint( array $data, int $endpoint_id = 0 ): int {
		return $this->endpoints->save( $data, $endpoint_id );
	}

	public function find_endpoint( int $endpoint_id ): ?array {
		return $this->endpoints->find( $endpoint_id );
	}

	public function find_endpoint_by_key( string $endpoint_key ): ?array {
		return $this->endpoints->find_by_key( $endpoint_key );
	}

	public function get_directory( array $args = array() ): array {
		return $this->endpoints->get_directory( $args );
	}

	public function get_endpoints_for_node( string $node_ref_type, int $node_ref_id ): array {
		return $this->endpoints->get_active_for_node( $node_ref_type, $node_ref_id );
	}

	public function get_runtime_endpoints( int $user_id = 0 ): array {
		$node_ref = $this->resolve_user_node_reference( $user_id );
		if ( '' === (string) ( $node_ref['node_ref_type'] ?? '' ) || (int) ( $node_ref['node_ref_id'] ?? 0 ) <= 0 ) {
			return array();
		}

		$endpoints = $this->get_endpoints_for_node(
			(string) $node_ref['node_ref_type'],
			(int) $node_ref['node_ref_id']
		);

		return $this->index_endpoints( $endpoints );
	}

	public function resolve_runtime_endpoint( int $user_id = 0, string $preferred_endpoint_key = '' ): array {
		$preferred_endpoint_key = sanitize_key( $preferred_endpoint_key );
		$runtime = $this->get_runtime_endpoints( $user_id );

		if ( '' !== $preferred_endpoint_key && isset( $runtime[ $preferred_endpoint_key ] ) ) {
			return $runtime[ $preferred_endpoint_key ];
		}

		foreach ( $runtime as $endpoint ) {
			if ( ! is_array( $endpoint ) ) {
				continue;
			}

			if ( ! empty( $endpoint['is_current'] ) ) {
				return $endpoint;
			}
		}

		return ! empty( $runtime ) ? reset( $runtime ) : array();
	}

	public function get_endpoint_choices( int $user_id = 0 ): array {
		$choices = array();
		foreach ( $this->get_runtime_endpoints( $user_id ) as $endpoint_key => $endpoint ) {
			$choices[ $endpoint_key ] = (string) ( $endpoint['endpoint_label'] ?? $endpoint['endpoint_name'] ?? $endpoint_key );
		}

		return $choices;
	}

	public function get_route_suggestions( int $user_id = 0 ): array {
		$runtime_guidance = $this->get_runtime_guidance( $user_id );
		if ( ! empty( $runtime_guidance ) ) {
			$suggestions = $this->normalize_route_guidance_suggestions( $runtime_guidance );
			if ( ! empty( $suggestions ) ) {
				return $suggestions;
			}
		}

		$runtime = $this->get_runtime_endpoints( $user_id );
		$suggestions = array();

		foreach ( $runtime as $source_key => $source_endpoint ) {
			$source_type = sanitize_key( (string) ( $source_endpoint['endpoint_type'] ?? $source_endpoint['node_type'] ?? '' ) );
			$targets = array_values( array_filter( array_map( 'sanitize_key', (array) ( $source_endpoint['suggested_targets'] ?? array() ) ) ) );

			foreach ( $runtime as $target_key => $target_endpoint ) {
				$target_type = sanitize_key( (string) ( $target_endpoint['endpoint_type'] ?? $target_endpoint['node_type'] ?? '' ) );
				if ( '' === $source_type || '' === $target_type || $source_type === $target_type ) {
					continue;
				}

				if ( ! empty( $targets ) && ! in_array( $target_type, $targets, true ) ) {
					continue;
				}

				$suggestions[] = array(
					'source_endpoint_key' => (string) $source_key,
					'source_label'        => (string) ( $source_endpoint['endpoint_label'] ?? $source_endpoint['endpoint_name'] ?? $source_key ),
					'target_endpoint_key' => (string) $target_key,
					'target_label'        => (string) ( $target_endpoint['endpoint_label'] ?? $target_endpoint['endpoint_name'] ?? $target_key ),
					'label'               => sprintf(
						'%s -> %s',
						(string) ( $source_endpoint['endpoint_label'] ?? $source_endpoint['endpoint_name'] ?? $source_key ),
						(string) ( $target_endpoint['endpoint_label'] ?? $target_endpoint['endpoint_name'] ?? $target_key )
					),
				);
			}
		}

		return $suggestions;
	}

	public function get_suggested_route_label( int $user_id = 0 ): string {
		$runtime_guidance = $this->get_runtime_guidance( $user_id );
		if ( ! empty( $runtime_guidance[0]['default_route']['guidance_label'] ) ) {
			return sanitize_text_field( (string) $runtime_guidance[0]['default_route']['guidance_label'] );
		}

		if ( ! empty( $runtime_guidance[0]['routes'][0]['relationship']['guidance_label'] ) ) {
			return sanitize_text_field( (string) $runtime_guidance[0]['routes'][0]['relationship']['guidance_label'] );
		}

		$current = $this->resolve_runtime_endpoint( $user_id );
		return sprintf(
			'%s default dispatch guidance',
			(string) ( $current['endpoint_label'] ?? $current['endpoint_name'] ?? 'Custody' )
		);
	}

	public function get_suggested_route_note( int $user_id = 0 ): string {
		$runtime_guidance = $this->get_runtime_guidance( $user_id );
		if ( ! empty( $runtime_guidance[0]['default_route']['guidance_notes'] ) ) {
			return sanitize_textarea_field( (string) $runtime_guidance[0]['default_route']['guidance_notes'] );
		}

		if ( ! empty( $runtime_guidance[0]['routes'][0]['relationship']['guidance_notes'] ) ) {
			return sanitize_textarea_field( (string) $runtime_guidance[0]['routes'][0]['relationship']['guidance_notes'] );
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

	private function get_runtime_guidance( int $user_id ): array {
		$node_ref = $this->resolve_user_node_reference( $user_id );
		if ( '' === (string) ( $node_ref['node_ref_type'] ?? '' ) || (int) ( $node_ref['node_ref_id'] ?? 0 ) <= 0 ) {
			return array();
		}

		if ( is_object( $this->route_guidance ) && method_exists( $this->route_guidance, 'get_route_guidance_for_node' ) ) {
			$result = $this->route_guidance->get_route_guidance_for_node(
				(string) $node_ref['node_ref_type'],
				(int) $node_ref['node_ref_id']
			);

			return is_array( $result ) && ! empty( $result['success'] ) ? (array) ( $result['guidance'] ?? array() ) : array();
		}

		return array();
	}

	private function normalize_route_guidance_suggestions( array $runtime_guidance ): array {
		$suggestions = array();

		foreach ( $runtime_guidance as $guidance ) {
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
					'source_label'        => (string) ( $endpoint['endpoint_label'] ?? $endpoint['endpoint_name'] ?? $current_key ),
					'target_endpoint_key' => $target_key,
					'target_label'        => (string) ( $target['endpoint_label'] ?? $target['endpoint_name'] ?? $target_key ),
					'label'               => ! empty( $relationship['guidance_label'] )
						? sanitize_text_field( (string) $relationship['guidance_label'] )
						: sprintf(
							'%s -> %s',
							(string) ( $endpoint['endpoint_label'] ?? $endpoint['endpoint_name'] ?? $current_key ),
							(string) ( $target['endpoint_label'] ?? $target['endpoint_name'] ?? $target_key )
						),
				);
			}
		}

		return $suggestions;
	}

	private function resolve_user_node_reference( int $user_id ): array {
		$user_id = max( 0, $user_id );
		if ( $user_id <= 0 ) {
			return array(
				'node_ref_type' => '',
				'node_ref_id'   => 0,
			);
		}

		$node_ref_type = '';
		if ( is_object( $this->person_identity ) ) {
			if ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_STITCH ) ) {
				$node_ref_type = self::TYPE_STITCHER;
			} elseif ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR ) ) {
				$node_ref_type = self::TYPE_VENDOR;
			} elseif ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_MANAGER ) ) {
				$node_ref_type = self::TYPE_SUPERVISOR;
			}
		}

		if ( '' === $node_ref_type ) {
			if ( current_user_can( AIMS_Capabilities::CAP_MANAGE_INVENTORY ) || current_user_can( AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS ) ) {
				$node_ref_type = self::TYPE_WAREHOUSE;
			} elseif ( current_user_can( AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL ) || current_user_can( AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING ) ) {
				$node_ref_type = self::TYPE_SUPERVISOR;
			} else {
				$node_ref_type = self::TYPE_VENDOR;
			}
		}

		return array(
			'node_ref_type' => sanitize_key( $node_ref_type ),
			'node_ref_id'   => $user_id,
		);
	}

	private function index_endpoints( array $endpoints ): array {
		$indexed = array();

		foreach ( $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) ) {
				continue;
			}

			$key = sanitize_key( (string) ( $endpoint['endpoint_key'] ?? $endpoint['endpoint_type'] ?? $endpoint['node_type'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$indexed[ $key ] = $this->normalize_endpoint( $endpoint );
		}

		return $indexed;
	}

	private function normalize_endpoint( array $endpoint ): array {
		$endpoint_key = sanitize_key( (string) ( $endpoint['endpoint_key'] ?? $endpoint['endpoint_type'] ?? $endpoint['node_type'] ?? '' ) );
		if ( '' === $endpoint_key ) {
			$endpoint_key = self::TYPE_CUSTOM;
		}

		return array_merge(
			$endpoint,
			array(
				'endpoint_key'   => $endpoint_key,
				'endpoint_type'  => sanitize_key( (string) ( $endpoint['endpoint_type'] ?? $endpoint['node_type'] ?? $endpoint_key ) ),
				'endpoint_label' => sanitize_text_field( (string) ( $endpoint['endpoint_label'] ?? $endpoint['endpoint_name'] ?? ucfirst( $endpoint_key ) ) ),
				'node_type'      => sanitize_key( (string) ( $endpoint['node_type'] ?? $endpoint['endpoint_type'] ?? $endpoint_key ) ),
				'node_id'        => max( 0, (int) ( $endpoint['node_id'] ?? $endpoint['node_ref_id'] ?? 0 ) ),
				'is_current'     => ! empty( $endpoint['is_current'] ),
			)
		);
	}
}
