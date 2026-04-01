<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class MovementLifecycleSchemaTest extends \AIMS\Tests\TestCase {
	public function testSchemaRegistersMovementBatchLifecycleTablesAndColumns(): void {
		$schema = new \AIMS_Schema();
		$table_names = $schema->get_table_names();
		$definitions = implode( "\n", $schema->get_table_definitions() );
		$prefix = $this->wpdb()->prefix;

		$this->assertContains( $prefix . 'aims_movement_batches', $table_names );
		$this->assertContains( $prefix . 'aims_movement_archive_manifests', $table_names );
		$this->assertStringContainsString( 'movement_batch_id bigint(20) unsigned NOT NULL DEFAULT 0', $definitions );
		$this->assertStringContainsString( 'movement_lifecycle varchar(32) NOT NULL DEFAULT \'hot\'', $definitions );
		$this->assertStringContainsString( 'line_meta_json longtext NULL', $definitions );
		$this->assertStringContainsString( 'archive_compression varchar(32) NOT NULL DEFAULT \'none\'', $definitions );
		$this->assertStringContainsString( 'compression_codec varchar(32) NOT NULL DEFAULT \'gzip\'', $definitions );
	}
}
