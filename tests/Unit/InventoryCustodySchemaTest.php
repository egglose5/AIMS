<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryCustodySchemaTest extends \AIMS\Tests\TestCase {
	public function testSchemaRegistersCustodyEndpointTablesAndOverrideFields(): void {
		$schema = new \AIMS_Schema();
		$table_names = $schema->get_table_names();
		$definitions = $schema->get_table_definitions();
		$prefix = $this->wpdb()->prefix;

		$this->assertContains( $prefix . 'aims_inventory_custody_endpoints', $table_names );
		$this->assertContains( $prefix . 'aims_inventory_custody_endpoint_relationships', $table_names );

		$definition_sql = implode( "\n", $definitions );

		$this->assertStringContainsString( 'override_route varchar(64)', $definition_sql );
		$this->assertStringContainsString( 'override_reason varchar(191)', $definition_sql );
		$this->assertStringContainsString( 'override_actor_id bigint(20) unsigned NOT NULL DEFAULT 0', $definition_sql );
		$this->assertStringContainsString( 'aims_inventory_custody_endpoints', $definition_sql );
		$this->assertStringContainsString( 'default_route_policy varchar(50) NOT NULL DEFAULT \'guidance\'', $definition_sql );
		$this->assertStringContainsString( 'aims_inventory_custody_endpoint_relationships', $definition_sql );
	}
}
