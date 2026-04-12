<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class CycleCountServiceTest extends \AIMS\Tests\TestCase {
	public function testSubmitCountSkipsUnknownSkuAndDoesNotPersistOrMove(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public array $saved = array();

			public function __construct() {}

			public function find_by_bucket_vendor_product( int $bucket_id, int $vendor_id, int $product_id ): ?array {
				unset( $bucket_id, $vendor_id, $product_id );
				return null;
			}

			public function save( array $data, int $position_id = 0 ): int {
				unset( $position_id );
				$this->saved[] = $data;
				return 1;
			}

			public function get_for_bucket( int $bucket_id ): array {
				unset( $bucket_id );
				return array();
			}
		};

		$movements = new class() extends \AIMS_Bucket_Inventory_Movement_Repository {
			public array $created = array();

			public function __construct() {}

			public function create( array $data ): int {
				$this->created[] = $data;
				return 1;
			}
		};

		$buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function __construct() {}
		};

		$service = new class( $buckets, $positions, $movements ) extends \AIMS_Cycle_Count_Service {
			public function resolve_sku_to_product_id( string $sku ): int {
				unset( $sku );
				return 0;
			}
		};

		$result = $service->submit_count(
			10,
			array(
				array(
					'sku'      => 'NO-MATCH',
					'quantity' => 2,
				),
			),
			'test note',
			55
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['applied_lines'] );
		$this->assertSame( 1, $result['skipped_lines'] );
		$this->assertCount( 0, $positions->saved );
		$this->assertCount( 0, $movements->created );
		$this->assertStringContainsString( 'NO-MATCH', (string) ( $result['errors'][0] ?? '' ) );
	}

	public function testSubmitCountReconcilesOmittedExistingPositionsToZero(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public array $saved = array();

			public function __construct() {}

			public function get_for_bucket( int $bucket_id ): array {
				unset( $bucket_id );
				return array(
					array(
						'id'                => 101,
						'bucket_id'         => 10,
						'vendor_id'         => 0,
						'product_id'        => 21,
						'quantity'          => 5.0,
						'reserved_quantity' => 2.0,
					),
				);
			}

			public function save( array $data, int $position_id = 0 ): int {
				$this->saved[] = array(
					'data'        => $data,
					'position_id' => $position_id,
				);
				return 101;
			}
		};

		$movements = new class() extends \AIMS_Bucket_Inventory_Movement_Repository {
			public array $created = array();

			public function __construct() {}

			public function create( array $data ): int {
				$this->created[] = $data;
				return 901;
			}
		};

		$buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function __construct() {}
		};

		$service = new class( $buckets, $positions, $movements ) extends \AIMS_Cycle_Count_Service {
			public function resolve_sku_to_product_id( string $sku ): int {
				unset( $sku );
				return 0;
			}
		};

		$result = $service->submit_count( 10, array(), 'reconcile', 44 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['applied_lines'] );
		$this->assertCount( 1, $positions->saved );
		$this->assertSame( 0.0, (float) $positions->saved[0]['data']['quantity'] );
		$this->assertSame( 2.0, (float) $positions->saved[0]['data']['reserved_quantity'] );
		$this->assertCount( 1, $movements->created );
		$this->assertSame( -5.0, (float) $movements->created[0]['quantity_delta'] );
	}

	public function testSubmitCountPreservesExistingReservedQuantityOnUpsert(): void {
		$positions = new class() extends \AIMS_Bucket_Inventory_Position_Repository {
			public array $saved = array();

			public function __construct() {}

			public function find_by_bucket_vendor_product( int $bucket_id, int $vendor_id, int $product_id ): ?array {
				unset( $bucket_id, $vendor_id, $product_id );
				return array(
					'id'                => 77,
					'quantity'          => 3.0,
					'reserved_quantity' => 1.5,
				);
			}

			public function save( array $data, int $position_id = 0 ): int {
				$this->saved[] = array(
					'data'        => $data,
					'position_id' => $position_id,
				);
				return 77;
			}

			public function get_for_bucket( int $bucket_id ): array {
				unset( $bucket_id );
				return array();
			}
		};

		$movements = new class() extends \AIMS_Bucket_Inventory_Movement_Repository {
			public array $created = array();

			public function __construct() {}

			public function create( array $data ): int {
				$this->created[] = $data;
				return 402;
			}
		};

		$buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function __construct() {}
		};

		$service = new class( $buckets, $positions, $movements ) extends \AIMS_Cycle_Count_Service {
			public function resolve_sku_to_product_id( string $sku ): int {
				return 'SKU-101' === $sku ? 33 : 0;
			}
		};

		$result = $service->submit_count(
			10,
			array(
				array(
					'sku'      => 'SKU-101',
					'quantity' => 4,
				),
			),
			'counted',
			40
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $positions->saved );
		$this->assertSame( 1.5, (float) $positions->saved[0]['data']['reserved_quantity'] );
		$this->assertSame( 4.0, (float) $positions->saved[0]['data']['quantity'] );
		$this->assertCount( 1, $movements->created );
		$this->assertSame( 1.0, (float) $movements->created[0]['quantity_delta'] );
	}
}
