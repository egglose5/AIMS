<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Custody_Route_Guidance_Service {
	private $endpoints;
	private $relationships;
	private $person_identity;

	public function __construct(
		AIMS_Inventory_Custody_Endpoint_Repository $endpoints = null,
		AIMS_Inventory_Custody_Endpoint_Relationship_Repository $relationships = null,
		AIMS_Person_Identity_Service $person_identity = null
	) {
		$this->endpoints     = $endpoints ?: new AIMS_Inventory_Custody_Endpoint_Repository();
		$this->relationships = $relationships ?: new AIMS_Inventory_Custody_Endpoint_Relationship_Repository();
		$this->person_identity = $person_identity ?: ( class_exists( 'AIMS_Person_Identity_Service' ) ? new AIMS_Person_Identity_Service() : null );
	}

	public function get_route_guidance_for_endpoint( int $endpoint_id ): array {
		$endpoint = $this->endpoints->find( $endpoint_id );
		if ( ! is_array( $endpoint ) ) {
			return $this->failure_response( 'Endpoint not found.' );
		}

		$relationships = $this->relationships->get_for_source_endpoint(
			$endpoint_id,
			array(
				'is_active' => 1,
			)
		);

		$routes = array();
		foreach ( $relationships as $relationship ) {
			if ( ! is_array( $relationship ) ) {
				continue;
			}

			$target_endpoint = $this->endpoints->find( (int) ( $relationship['target_endpoint_id'] ?? 0 ) );
			$routes[] = array(
				'relationship'    => $relationship,
				'target_endpoint' => $target_endpoint,
			);
		}

		$default_route = null;
		if ( is_object( $this->relationships ) && method_exists( $this->relationships, 'get_default_route_for_source_endpoint' ) ) {
			$default_route = $this->relationships->get_default_route_for_source_endpoint( $endpoint_id );
		}

		if ( ! is_array( $default_route ) && ! empty( $routes[0] ) ) {
			$default_route = $routes[0];
		}

		return array(
			'success'                  => true,
			'endpoint'                 => $endpoint,
			'default_route_policy'     => (string) ( $endpoint['default_route_policy'] ?? 'guidance' ),
			'allows_direct_collection' => ! empty( $endpoint['allows_direct_collection'] ),
			'allows_direct_recovery'   => ! empty( $endpoint['allows_direct_recovery'] ),
			'routes'                   => $routes,
			'default_route'            => $default_route,
		);
	}

	public function get_route_guidance_for_node( string $node_ref_type, int $node_ref_id ): array {
		$endpoints = $this->endpoints->get_active_for_node( $node_ref_type, $node_ref_id );
		if ( empty( $endpoints ) ) {
			return $this->failure_response( 'No custody endpoints are registered for this node.' );
		}

		$guidance = array();
		foreach ( $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) ) {
				continue;
			}

			$endpoint_id = (int) ( $endpoint['id'] ?? 0 );
			if ( $endpoint_id <= 0 ) {
				continue;
			}

			$guidance[] = $this->get_route_guidance_for_endpoint( $endpoint_id );
		}

		return array(
			'success'   => true,
			'node_ref_type' => sanitize_key( $node_ref_type ),
			'node_ref_id'   => $node_ref_id,
			'endpoints' => $endpoints,
			'guidance'  => $guidance,
		);
	}

	public function get_route_guidance_for_runtime_user( int $user_id = 0 ): array {
		$user_id = $this->resolve_user_id( $user_id );
		$node_ref = $this->resolve_user_node_reference( $user_id );

		if ( '' === (string) ( $node_ref['node_ref_type'] ?? '' ) || (int) ( $node_ref['node_ref_id'] ?? 0 ) <= 0 ) {
			return $this->failure_response( 'No custody node could be resolved for this user.' );
		}

		return $this->get_route_guidance_for_node(
			(string) $node_ref['node_ref_type'],
			(int) $node_ref['node_ref_id']
		);
	}

	public function resolve_user_node_reference( int $user_id ): array {
		$user_id = $this->resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return array(
				'node_ref_type' => '',
				'node_ref_id'   => 0,
			);
		}

		$node_ref_type = '';
		if ( is_object( $this->person_identity ) ) {
			if ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_WAREHOUSE ) ) {
				$node_ref_type = 'warehouse';
			} elseif ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_STITCH ) ) {
				$node_ref_type = 'stitcher';
			} elseif ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_VENDOR ) ) {
				$node_ref_type = 'vendor';
			} elseif ( $this->person_identity->has_person_subtype( $user_id, AIMS_Person_Identity_Service::SUBTYPE_MANAGER ) ) {
				$node_ref_type = 'supervisor';
			}
		}

		if ( '' === $node_ref_type ) {
			if ( user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_INVENTORY ) || user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_STORAGE_LOCATIONS ) ) {
				$node_ref_type = 'warehouse';
			} elseif ( user_can( $user_id, AIMS_Capabilities::CAP_VIEW_SUPERVISOR_PORTAL ) || user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_EVENT_PLANNING ) ) {
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

	private function resolve_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	private function failure_response( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
			'routes'  => array(),
		);
	}
}
