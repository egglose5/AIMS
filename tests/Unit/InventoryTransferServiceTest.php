<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class InventoryTransferServiceTest extends \AIMS\Tests\TestCase {
	private function build_transfer_auth( bool $allow_nodes = true, bool $allow_override = true ): \AIMS_Inventory_Transfer_Authorization_Service {
		return new class( $allow_nodes, $allow_override ) extends \AIMS_Inventory_Transfer_Authorization_Service {
			private $allow_nodes;
			private $allow_override;

			public function __construct( bool $allow_nodes, bool $allow_override ) {
				$this->allow_nodes    = $allow_nodes;
				$this->allow_override = $allow_override;
			}

			public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
				return true;
			}

			public function can_manage_transfer_nodes( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
				return $this->allow_nodes;
			}

			public function can_override_transfer_route( int $user_id = 0, string $transfer_type = 'standard' ): bool {
				return $this->allow_override;
			}
		};
	}

	public function testCreateDraftRequiresSourceAndTargetNodes(): void {
		$service = new \AIMS_Inventory_Transfer_Service();

		$result = $service->create_draft( 0, 0 );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing_source_node', $result['code'] ?? '' );
	}

	public function testCreateDraftWithValidNodesReturnsTransferId(): void {
		$repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public function __construct() {}

			public function create( array $data ): int {
				return 123;
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $repo, null, null, null, $this->build_transfer_auth() );
		$result  = $service->create_draft( 1, 2 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 123, $result['transfer_id'] );
	}

	public function testCreateDraftStoresOverrideAuditMetadataWhenAuthorized(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public array $captured = array();

			public function __construct() {}

			public function create( array $data ): int {
				$this->captured = $data;
				return 321;
			}
		};

		$auth = new class() extends \AIMS_Inventory_Transfer_Authorization_Service {
			public function __construct() {}

			public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
				return true;
			}

			public function can_manage_transfer_nodes( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
				return true;
			}

			public function can_override_transfer_route( int $user_id = 0, string $transfer_type = 'standard' ): bool {
				return true;
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, null, null, null, $auth );
		$result  = $service->create_draft(
			10,
			20,
			array(
				'transfer_type'   => 'direct_collection',
				'override_route'  => true,
				'override_reason' => 'Direct collection approved by operations.',
				'route_guidance'  => 'Default warehouse routing bypassed for pickup.',
				'notes'           => 'Initial transfer note.',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 321, $result['transfer_id'] );
		$this->assertSame( 'direct_collection', $transfer_repo->captured['transfer_type'] );
		$this->assertSame( 'inventory_route_override', $transfer_repo->captured['reference_type'] );
		$this->assertSame( 'override', $transfer_repo->captured['override_route'] );
		$this->assertSame( 'Direct collection approved by operations.', $transfer_repo->captured['override_reason'] );
		$this->assertSame( 'Default warehouse routing bypassed for pickup.', $transfer_repo->captured['override_note'] );
		$this->assertStringContainsString( 'Initial transfer note.', (string) $transfer_repo->captured['notes'] );
		$this->assertStringContainsString( 'Transfer type: direct_collection', (string) $transfer_repo->captured['notes'] );
		$this->assertStringContainsString( 'Override reason: Direct collection approved by operations.', (string) $transfer_repo->captured['notes'] );
	}

	public function testCreateDraftRejectsExceptionalRoutingWithoutPermission(): void {
		$auth = new class() extends \AIMS_Inventory_Transfer_Authorization_Service {
			public function __construct() {}

			public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
				return true;
			}

			public function can_manage_transfer_nodes( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
				return true;
			}

			public function can_override_transfer_route( int $user_id = 0, string $transfer_type = 'standard' ): bool {
				return false;
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( null, null, null, null, $auth );
		$result  = $service->create_draft(
			10,
			20,
			array(
				'transfer_type'   => 'recovery',
				'override_route'  => true,
				'override_reason' => 'Recovery route required.',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'transfer_override_denied', $result['code'] ?? '' );
	}

	public function testAddItemToTransferValidatesTransferExists(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public function __construct() {}

			public function find( int $transfer_id ): ?array {
				return null;
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, null, null, null, $this->build_transfer_auth() );
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

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, null, null, null, $this->build_transfer_auth() );
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
					'source_node_type' => 'vendor',
					'source_node_id'   => 10,
					'target_node_type' => 'warehouse',
					'target_node_id'   => 20,
					'source_vendor_id' => 10,
				);
			}

			public function update_status( int $transfer_id, string $status, array $extra_data = array() ): bool {
				$this->calls[]       = array(
					'transfer_id' => $transfer_id,
					'status'      => $status,
					'extra_data'  => $extra_data,
				);
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
			public array $calls = array();

			public function __construct() {}

			public function create_transfer_out( array $data ): array {
				$this->calls[] = $data;
				return array(
					'success'     => true,
					'movement_id' => 501,
				);
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, $items_repo, $custody_service, null, $this->build_transfer_auth() );
		$result  = $service->dispatch_transfer(
			1,
			array(
				'override_route' => true,
				'audit_reason'   => 'Direct collection approved by operations.',
				'route_guidance' => 'Warehouse routing bypassed.',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $transfer_repo->calls );
		$this->assertSame( 'dispatched', $transfer_repo->calls[0]['status'] );
		$this->assertStringContainsString( 'Direct collection approved by operations.', (string) $transfer_repo->calls[0]['extra_data']['notes'] );
		$this->assertStringContainsString( 'Route mode: override', (string) $custody_service->calls[0]['note'] );
		$this->assertStringContainsString( 'Dispatch reason: Direct collection approved by operations.', (string) $custody_service->calls[0]['note'] );
	}

	public function testConfirmReceiptChangesTransferAndItemStatus(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public array $status_updates = array();

			public function __construct() {}

			public function find( int $transfer_id ): ?array {
				return array(
					'id'              => 1,
					'transfer_status' => 'dispatched',
					'source_node_type' => 'vendor',
					'source_node_id'   => 10,
					'target_node_type' => 'warehouse',
					'target_node_id'   => 20,
				);
			}

			public function update_status( int $transfer_id, string $status, array $extra_data = array() ): bool {
				$this->status_updates[] = array(
					'status'     => $status,
					'extra_data' => $extra_data,
				);
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
			public array $calls = array();

			public function __construct() {}

			public function confirm_transfer_receipt( array $data ): array {
				$this->calls[] = $data;
				return array(
					'success'     => true,
					'movement_id' => 502,
				);
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, $items_repo, $custody_service, null, $this->build_transfer_auth() );
		$result  = $service->confirm_receipt(
			1,
			array(),
			array(
				'override_route' => true,
				'audit_reason'   => 'Recovery receipt approved by operations.',
				'route_guidance' => 'Warehouse return routing recorded.',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'received', $transfer_repo->status_updates[0]['status'] );
		$this->assertStringContainsString( 'Recovery receipt approved by operations.', (string) $transfer_repo->status_updates[0]['extra_data']['notes'] );
		$this->assertStringContainsString( 'Route mode: override', (string) $custody_service->calls[0]['note'] );
	}

	public function testCreateDraftRejectsUnauthorizedCustodyNodes(): void {
		$transfer_repo = new class() extends \AIMS_Inventory_Transfer_Repository {
			public function __construct() {}

			public function create( array $data ): int {
				return 0;
			}
		};

		$auth = new class() extends \AIMS_Inventory_Transfer_Authorization_Service {
			public function __construct() {}

			public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
				return true;
			}

			public function can_manage_transfer_nodes( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
				return false;
			}
		};

		$service = new \AIMS_Inventory_Transfer_Service( $transfer_repo, null, null, null, $auth );
		$result  = $service->create_draft(
			10,
			20,
			array(
				'source_node_type' => 'vendor',
				'target_node_type' => 'warehouse',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'transfer_node_access_denied', $result['code'] ?? '' );
	}
}
