<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareSalesSchemaTest extends \AIMS\Tests\TestCase {
	public function testSchemaRegistersSquareSalesIdempotencyGuards(): void {
		$schema = new \AIMS_Schema();
		$definitions = implode( "\n", $schema->get_table_definitions() );

		$this->assertStringContainsString( 'UNIQUE KEY square_order_id (square_order_id)', $definitions );
		$this->assertStringContainsString( 'UNIQUE KEY dedupe_key (dedupe_key)', $definitions );
		$this->assertStringContainsString( 'UNIQUE KEY square_line (square_order_id, square_line_item_uid)', $definitions );
		$this->assertStringContainsString( 'UNIQUE KEY sale_allocation_identity (square_sale_id, square_order_id, product_id, vendor_id, event_id, allocation_type, source_bucket_code)', $definitions );
	}
}
