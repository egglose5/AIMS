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

	public function __construct( AIMS_Inventory_Custody_Endpoint_Repository $endpoints = null ) {
		$this->endpoints = $endpoints ?: new AIMS_Inventory_Custody_Endpoint_Repository();
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
}
