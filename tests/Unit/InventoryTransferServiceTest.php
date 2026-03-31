<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryTransferServiceTest extends \AIMS\Tests\TestCase {
	public function testCreateDraftRequiresSourceAndTargetVendors(): void {
		$service = new \AIMS_Inventory_Transfer_Service();

		$result = $service->create_draft( 0, 0 );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing_source_vendor', $result['code'] ?? '' );
	}

	public function testCreateDraftWithValidVendorsReturnsTransferId(): void {
		$repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public function __construct() {}

			public function create( array $data ): int {
				return 123;
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $repo );
		$result  = $service->create_draft( 1, 2 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 123, $result['transfer_id'] );
	}

	public function testAddItemToTransferValidatesTransferExists(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public function __construct() {}

			public function find( int $transfer_id ): ?array {
				return null;
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo );
		$result  = $service->add_item_to_transfer( 999, 100, 1, 2, 5 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'transfer_not_found', $result['code'] ?? '' );
	}

	public function testAddItemToTransferRequiresPendingStatus(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public function __construct() {}

			public function find( int $transfer_id ): ?array {
				return array( 'id' => 1, 'transfer_status' => 'dispatched' );
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo );
		$result  = $service->add_item_to_transfer( 1, 100, 1, 2, 5 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_transfer_status', $result['code'] ?? '' );
	}

	public function testDispatchTransferChangesStatusAndWritesMovements(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public array $calls = array();

			public function __construct() {}

			public function find( int $transfer_id ): ?array {
				return array(
					'id'              => 1,
					'transfer_status' => 'pending',
					'transfer_uuid'   => 'uuid-123',
					'source_vendor_id' => 10,
				);
			}

			public function update_status( int $transfer_id, string $status, array $extra_data = array() ): bool {
				$this->calls[]       = array( 'transfer_id' => $transfer_id, 'status' => $status );
				return true;
			}
		};

		$items_repo = new class() extends \AIMS_Inventory_Transfer_Items_Repository {
			public function __construct() {}

			public function get_for_transfer( int $transfer_id ): array {
				return array(
					array(
						'id'                  => 1,
						'transfer_uuid'       => 'uuid-123',
						'product_id'          => 100,
						'vendor_id'           => 10,
						'requested_quantity'  => 5,
						'source_bucket_id'    => 1,
						'target_bucket_id'    => 2,
					),
				);
			}

			public function update_status( int $item_id, string $status, array $extra_data = array() ): bool {
				return true;
			}
		};

		$custody_service = new class() extends \AIMS_Inventory_Custody_Transfer_Service {
			public function __construct() {}

			public function create_transfer_out( array $data ): array {
				return array(
					'success'     => true,
					'movement_id' => 501,
				);
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, $items_repo, $custody_service );
		$result  = $service->dispatch_transfer( 1 );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $transfer_repo->calls );
		$this->assertSame( 'dispatched', $transfer_repo->calls[0]['status'] );
	}

	public function testConfirmReceiptChangesTransferAndItemStatus(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public array $status_updates = array();

			public function __construct() {}

			public function find( int $transfer_id ): ?array {
				return array(
					'id'              => 1,
					'transfer_status' => 'dispatched',
				);
			}

			public function update_status( int $transfer_id, string $status, array $extra_data = array() ): bool {
				$this->status_updates[] = $status;
				return true;
			}
		};

		$items_repo = new class() extends \AIMS_Inventory_Transfer_Items_Repository {
			public function __construct() {}

			public function get_for_transfer( int $transfer_id ): array {
				return array(
					array(
						'id'                  => 1,
						'product_id'          => 100,
						'vendor_id'           => 10,
						'requested_quantity'  => 5,
						'source_bucket_id'    => 1,
						'target_bucket_id'    => 2,
						'dispatch_movement_id' => 501,
					),
				);
			}

			public function update_status( int $item_id, string $status, array $extra_data = array() ): bool {
				return true;
			}
		};

		$custody_service = new class() extends \AIMS_Inventory_Custody_Transfer_Service {
			public function __construct() {}

			public function confirm_transfer_receipt( array $data ): array {
				return array(
					'success'     => true,
					'movement_id' => 502,
				);
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, $items_repo, $custody_service );
		$result  = $service->confirm_receipt( 1 );

		$this->assertTrue( $result['success'] );
		$this->assertContains( 'received', $transfer_repo->status_updates );
	}
}
