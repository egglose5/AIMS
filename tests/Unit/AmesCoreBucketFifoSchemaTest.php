<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Schema\BucketFifoSchema;

final class AmesCoreBucketFifoSchemaTest extends \AIMS\Tests\TestCase {
	public function testBucketFifoSchemaDefinesStandaloneAimsTables(): void {
		$sql = implode( "\n", BucketFifoSchema::migrationSql() );

		$this->assertStringContainsString( BucketFifoSchema::BUCKET_TABLE, $sql );
		$this->assertStringContainsString( BucketFifoSchema::LOT_TABLE, $sql );
		$this->assertStringContainsString( BucketFifoSchema::CUSTODY_TABLE, $sql );
		$this->assertStringContainsString( BucketFifoSchema::ALLOCATION_TABLE, $sql );
		$this->assertStringContainsString( 'unit_cost_cents', $sql );
		$this->assertStringContainsString( 'remaining_quantity', $sql );
		$this->assertStringContainsString( 'amount_paid_cents', $sql );
		$this->assertStringContainsString( 'tax_amount_cents', $sql );
	}
}
