<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Custody_Route_Guidance_Service {
	private $endpoints;
	private $relationships;

	public function __construct(
		AIMS_Inventory_Custody_Endpoint_Repository $endpoints = null,
		AIMS_Inventory_Custody_Endpoint_Relationship_Repository $relationships = null
	) {
		$this->endpoints     = $endpoints ?: new AIMS_Inventory_Custody_Endpoint_Repository();
		$this->relationships = $relationships ?: new AIMS_Inventory_Custody_Endpoint_Relationship_Repository();
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

		return array(
			'success'                  => true,
			'endpoint'                 => $endpoint,
			'default_route_policy'     => (string) ( $endpoint['default_route_policy'] ?? 'guidance' ),
			'allows_direct_collection' => ! empty( $endpoint['allows_direct_collection'] ),
			'allows_direct_recovery'   => ! empty( $endpoint['allows_direct_recovery'] ),
			'routes'                   => $routes,
			'default_route'            => ! empty( $routes[0] ) ? $routes[0] : null,
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
			'endpoints' => $endpoints,
			'guidance'  => $guidance,
		);
	}

	private function failure_response( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
			'routes'  => array(),
		);
	}
}
