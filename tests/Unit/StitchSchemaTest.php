<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class StitchSchemaTest extends \AIMS\Tests\TestCase {
	public function testSchemaRegistersStitchItemTables(): void {
		$original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}
		};

		try {
			$schema = new \AIMS_Schema();
			$tables = $schema->get_table_names();
			$definitions = implode( "\n", $schema->get_table_definitions() );

			$this->assertContains( 'wp_aims_stitch_job_items', $tables );
			$this->assertContains( 'wp_aims_stitch_job_item_payout_snapshots', $tables );
			$this->assertStringContainsString( 'producer_user_id bigint(20) unsigned NOT NULL DEFAULT 0', $definitions );
			$this->assertStringContainsString( 'quantity_completed decimal(20,4) NOT NULL DEFAULT 0.0000', $definitions );
			$this->assertStringContainsString( 'quantity_received_back decimal(20,4) NOT NULL DEFAULT 0.0000', $definitions );
			$this->assertStringContainsString( 'unit_payout_snapshot decimal(20,4) NOT NULL DEFAULT 0.0000', $definitions );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}
}
