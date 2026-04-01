<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryCustodyTransferServiceTest extends \AIMS\Tests\TestCase {
	public function testCreateTransferOutWritesNegativeQuantityAndCustodyTransferReferenceType(): void {
		$movement_service = new class() extends \AIMS_Bucket_Movement_Service {
			public array $calls = array();

			public function __construct() {}

			public function record_transfer( array $data ) {
				$this->calls[] = $data;

				return array(
					'movement_id'      => 901,
					'current_quantity' => 10.25,
				);
			}
		};

		$bucket_repo = new class() extends \AIMS_Physical_Bucket_Repository {
			public function __construct() {}

			public function find( int $bucket_id ): ?array {
				if ( 100 === $bucket_id ) {
					return array(
						'id'        => 100,
						'vendor_id' => 55,
					);
				}

				return null;
			}
		};

		$service = new \AIMS_Inventory_Custody_Transfer_Service( $movement_service, $bucket_repo );
		$result  = $service->create_transfer_out(
			array(
				'product_id'       => 777,
				'source_bucket_id' => 100,
				'target_bucket_id' => 200,
				'quantity_delta'   => 4,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 901, $result['movement_id'] );
		$this->assertCount( 1, $movement_service->calls );
		$this->assertSame( -4.0, $movement_service->calls[0]['quantity_delta'] );
		$this->assertSame( 'custody_transfer', $movement_service->calls[0]['reference_type'] );
		$this->assertSame( \AIMS_Inventory_Movement_Events::WAREHOUSE_TRANSFER, $movement_service->calls[0]['movement_type'] );
		$this->assertSame( 100, $movement_service->calls[0]['bucket_id'] );
		$this->assertSame( 100, $result['source_bucket_id'] );
		$this->assertSame( 200, $result['target_bucket_id'] );
	}

	public function testConfirmTransferReceiptWritesPositiveQuantityAndCustodyReceiptReferenceType(): void {
		$movement_service = new class() extends \AIMS_Bucket_Movement_Service {
			public array $calls = array();

			public function __construct() {}

			public function record_transfer( array $data ) {
				$this->calls[] = $data;

				return array(
					'movement_id'      => 902,
					'current_quantity' => 22.5,
				);
			}
		};

		$bucket_repo = new class() extends \AIMS_Physical_Bucket_Repository {
			public function __construct() {}

			public function find( int $bucket_id ): ?array {
				if ( 300 === $bucket_id ) {
					return array(
						'id'        => 300,
						'vendor_id' => 90,
					);
				}

				return null;
			}
		};

		$service = new \AIMS_Inventory_Custody_Transfer_Service( $movement_service, $bucket_repo );
		$result  = $service->confirm_transfer_receipt(
			array(
				'reference_id'     => 'custody-manual-abc',
				'product_id'       => 888,
				'source_bucket_id' => 250,
				'target_bucket_id' => 300,
				'quantity_delta'   => -3,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 902, $result['movement_id'] );
		$this->assertCount( 1, $movement_service->calls );
		$this->assertSame( 3.0, $movement_service->calls[0]['quantity_delta'] );
		$this->assertSame( 'custody_receipt', $movement_service->calls[0]['reference_type'] );
		$this->assertSame( 300, $movement_service->calls[0]['bucket_id'] );
		$this->assertSame( 'custody-manual-abc', $movement_service->calls[0]['reference_id'] );
	}

	public function testCreateTransferOutPersistsRouteGuidanceAndAuditReasonInMovementNote(): void {
		$movement_service = new class() extends \AIMS_Bucket_Movement_Service {
			public array $calls = array();

			public function __construct() {}

			public function record_transfer( array $data ) {
				$this->calls[] = $data;

				return array(
					'movement_id'      => 903,
					'current_quantity' => 9.5,
				);
			}
		};

		$bucket_repo = new class() extends \AIMS_Physical_Bucket_Repository {
			public function __construct() {}

			public function find( int $bucket_id ): ?array {
				return array(
					'id'        => $bucket_id,
					'vendor_id' => 77,
				);
			}
		};

		$service = new \AIMS_Inventory_Custody_Transfer_Service( $movement_service, $bucket_repo );
		$result  = $service->create_transfer_out(
			array(
				'product_id'       => 901,
				'source_bucket_id' => 100,
				'target_bucket_id' => 200,
				'quantity_delta'   => 2,
				'route_mode'       => 'override',
				'route_guidance'   => 'Collect directly from the stitcher after completion.',
				'audit_reason'     => 'Exceptional source selected by operations.',
				'note'             => 'Custody dispatch note.',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $movement_service->calls );
		$this->assertSame( -2.0, $movement_service->calls[0]['quantity_delta'] );
		$this->assertStringContainsString( 'Custody dispatch note.', (string) $movement_service->calls[0]['note'] );
		$this->assertStringContainsString( 'Route mode: override', (string) $movement_service->calls[0]['note'] );
		$this->assertStringContainsString( 'Route guidance: Collect directly from the stitcher after completion.', (string) $movement_service->calls[0]['note'] );
		$this->assertStringContainsString( 'Audit reason: Exceptional source selected by operations.', (string) $movement_service->calls[0]['note'] );
	}

	public function testMissingRequiredFieldsReturnsFailureWithErrorCode(): void {
		$movement_service = new class() extends \AIMS_Bucket_Movement_Service {
			public function __construct() {}
		};

		$service = new \AIMS_Inventory_Custody_Transfer_Service(
			$movement_service,
			new class() extends \AIMS_Physical_Bucket_Repository {
				public function __construct() {}
			}
		);

		$result = $service->confirm_transfer_receipt(
			array(
				'product_id'       => 0,
				'source_bucket_id' => 0,
				'target_bucket_id' => 0,
				'quantity_delta'   => 0,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['error_code'] );
	}
}
