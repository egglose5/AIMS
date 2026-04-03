<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SchemaResponsibilityCleanupTest extends \AIMS\Tests\TestCase {
	public function testSchemaNoLongerRegistersLegacyVendorTable(): void {
		$schema = new \AIMS_Schema();
		$table_names = $schema->get_table_names();
		$prefix = $this->wpdb()->prefix;

		$this->assertNotContains( $prefix . 'aims_vendors', $table_names );
		$this->assertContains( $prefix . 'aims_responsibility_templates', $table_names );
		$this->assertContains( $prefix . 'aims_user_responsibilities', $table_names );
		$this->assertContains( $prefix . 'aims_user_surface_capabilities', $table_names );
		$this->assertContains( $prefix . 'aims_event_bucket_materials', $table_names );
	}

	public function testSchemaVersionBumpsWithEventBucketMaterials(): void {
		$this->assertSame( '0.16.0', \AIMS_Plugin::SCHEMA_VERSION );
	}
}
