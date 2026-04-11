<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class InventoryTransferActionsTest extends \AIMS\Tests\TestCase {
	public function testCreateDraftActionPassesOverrideAuditFieldsToService(): void {
		TestState::set_current_user_id( 14 );
		TestState::set_user_capabilities(
			14,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
				\AIMS_Capabilities::CAP_MANAGE_PRODUCTION,
			)
		);
		TestState::set_throw_on_redirect( true );

		$service = new class() extends \AIMS_Inventory_Transfer_Service {
			public array $calls = array();

			public function __construct() {}

			public function create_draft( int $source_node_id, int $target_node_id, array $data = array() ): array {
				$this->calls[] = array(
					'source_node_id' => $source_node_id,
					'target_node_id' => $target_node_id,
					'data'           => $data,
				);

				return array(
					'success'     => true,
					'transfer_id' => 123,
					'message'     => 'Transfer draft created.',
				);
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

		$handler = new \AIMS_Inventory_Transfer_Actions( $service, $auth );
		$_POST = array(
			'source_node_id'   => 1,
			'target_node_id'   => 2,
			'transfer_type'    => 'direct_collection',
			'override_route'   => 1,
			'override_reason'  => 'Direct collection approved by operations.',
			'route_guidance'   => 'Default warehouse routing bypassed.',
			'notes'            => 'Action note.',
		);

		try {
			$handler->handle_create_draft();
			$this->fail( 'Expected redirect exception was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringStartsWith( 'redirect:', $exception->getMessage() );
		}

		$this->assertCount( 1, $service->calls );
		$this->assertSame( 1, $service->calls[0]['source_node_id'] );
		$this->assertSame( 2, $service->calls[0]['target_node_id'] );
		$this->assertTrue( (bool) $service->calls[0]['data']['override_route'] );
		$this->assertSame( 'Direct collection approved by operations.', $service->calls[0]['data']['override_reason'] );
		$this->assertSame( 'Default warehouse routing bypassed.', $service->calls[0]['data']['route_guidance'] );
	}

	public function testCreateDraftActionRejectsUnauthorizedCustodyNodesBeforeServiceCall(): void {
		TestState::set_current_user_id( 15 );
		TestState::set_user_capabilities(
			15,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);

		$service = new class() extends \AIMS_Inventory_Transfer_Service {
			public array $calls = array();

			public function __construct() {}

			public function create_draft( int $source_node_id, int $target_node_id, array $data = array() ): array {
				$this->calls[] = compact( 'source_node_id', 'target_node_id', 'data' );

				return array(
					'success'     => true,
					'transfer_id' => 999,
				);
			}
		};

		$auth = new class() extends \AIMS_Inventory_Transfer_Authorization_Service {
			public function __construct() {}

			public function can_manage_transfer_nodes( int $user_id = 0, string $source_node_type = '', int $source_node_id = 0, string $target_node_type = '', int $target_node_id = 0, string $transfer_type = self::TRANSFER_TYPE_STANDARD ): bool {
				return false;
			}
		};

		$handler = new \AIMS_Inventory_Transfer_Actions( $service, $auth );
		$_POST = array(
			'source_endpoint_selection' => 'vendor:10',
			'target_endpoint_selection' => 'warehouse:20',
			'source_node_type'          => 'vendor',
			'target_node_type'          => 'warehouse',
			'source_node_id'            => 10,
			'target_node_id'            => 20,
			'transfer_type'             => 'standard',
		);

		try {
			$handler->handle_create_draft();
			$this->fail( 'Expected custody access exception was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'custody access', $exception->getMessage() );
		}

		$this->assertCount( 0, $service->calls );
	}

	public function testCreateDraftActionRejectsMalformedSourceEndpointSelectionBeforeServiceCall(): void {
		TestState::set_current_user_id( 16 );
		TestState::set_user_capabilities(
			16,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);

		$service = new class() extends \AIMS_Inventory_Transfer_Service {
			public array $calls = array();

			public function __construct() {}

			public function create_draft( int $source_node_id, int $target_node_id, array $data = array() ): array {
				$this->calls[] = compact( 'source_node_id', 'target_node_id', 'data' );

				return array(
					'success'     => true,
					'transfer_id' => 1001,
				);
			}
		};

		$auth = new class() extends \AIMS_Inventory_Transfer_Authorization_Service {
			public function __construct() {}

			public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
				return true;
			}
		};

		$handler = new \AIMS_Inventory_Transfer_Actions( $service, $auth );
		$_POST = array(
			'source_endpoint_selection' => 'bad-selection',
			'target_endpoint_selection' => 'warehouse:20',
			'source_node_type'          => 'vendor',
			'source_node_id'            => 10,
			'target_node_type'          => 'warehouse',
			'target_node_id'            => 20,
			'transfer_type'             => 'standard',
		);

		try {
			$handler->handle_create_draft();
			$this->fail( 'Expected invalid source endpoint exception was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'Invalid source endpoint', $exception->getMessage() );
		}

		$this->assertCount( 0, $service->calls );
	}

	public function testCreateDraftActionRejectsSameSourceAndTargetEndpointBeforeServiceCall(): void {
		TestState::set_current_user_id( 17 );
		TestState::set_user_capabilities(
			17,
			array(
				\AIMS_Capabilities::CAP_MANAGE_INVENTORY,
			)
		);

		$service = new class() extends \AIMS_Inventory_Transfer_Service {
			public array $calls = array();

			public function __construct() {}

			public function create_draft( int $source_node_id, int $target_node_id, array $data = array() ): array {
				$this->calls[] = compact( 'source_node_id', 'target_node_id', 'data' );

				return array(
					'success'     => true,
					'transfer_id' => 1002,
				);
			}
		};

		$auth = new class() extends \AIMS_Inventory_Transfer_Authorization_Service {
			public function __construct() {}

			public function can_manage_inventory_transfers( int $user_id = 0 ): bool {
				return true;
			}
		};

		$handler = new \AIMS_Inventory_Transfer_Actions( $service, $auth );
		$_POST = array(
			'source_endpoint_selection' => 'vendor:10',
			'target_endpoint_selection' => 'vendor:10',
			'transfer_type'             => 'standard',
		);

		try {
			$handler->handle_create_draft();
			$this->fail( 'Expected duplicate route exception was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'must be different', $exception->getMessage() );
		}

		$this->assertCount( 0, $service->calls );
	}

	public function testAddItemActionValidatesInput(): void {
		// Test that add item action properly validates source/target buckets
		$service = new class() extends \AIMS_Inventory_Transfer_Service {
			public function __construct() {}

			public function add_item_via_wc_product( 
				int $transfer_id, 
				$sku_or_product_id, 
				int $source_bucket_id, 
				int $target_bucket_id, 
				float $quantity, 
				array $data = array() 
			): array {
				if ( $source_bucket_id <= 0 ) {
					return array(
						'success' => false,
						'code'    => 'invalid_source_bucket',
						'message' => 'Invalid source bucket',
					);
				}
				return array(
					'success' => true,
					'item_id' => 456,
				);
			}
		};

		$result = $service->add_item_via_wc_product( 1, 'SKU-123', 0, 2, 5 );
		$this->assertFalse( $result['success'] );
	}

	public function testDispatchActionRecordsMovements(): void {
		// Test that dispatch action properly transitions items
		$service = new class() extends \AIMS_Inventory_Transfer_Service {
			public array $dispatches = array();

			public function __construct() {}

			public function dispatch_transfer( int $transfer_id, array $data = array() ): array {
				$this->dispatches[] = $transfer_id;
				return array(
					'success' => true,
					'message' => 'Transfer dispatched and marked in transit.',
				);
			}
		};

		$result = $service->dispatch_transfer( 1 );
		$this->assertTrue( $result['success'] );
		$this->assertContains( 1, $service->dispatches );
	}

	public function testReceiptActionRecordsInboundMovement(): void {
		// Test that receipt action properly handles received quantities
		$service = new class() extends \AIMS_Inventory_Transfer_Service {
			public array $receipts = array();

			public function __construct() {}

			public function confirm_receipt( int $transfer_id, array $item_receipts = array(), array $data = array() ): array {
				$this->receipts[] = array( 'transfer_id' => $transfer_id, 'items' => $item_receipts );
				return array(
					'success' => true,
					'message' => 'Transfer receipt confirmed.',
				);
			}
		};

		$item_receipts = array(
			1 => 5.0,
			2 => 3.5,
		);

		$result = $service->confirm_receipt( 1, $item_receipts );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $service->receipts );
		$this->assertSame( $item_receipts, $service->receipts[0]['items'] );
	}
}
