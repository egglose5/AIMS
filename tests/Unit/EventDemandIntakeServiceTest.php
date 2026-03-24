<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Demand_Intake_Service;
use AIMS\Tests\Support\TestState;
use WP_Error;

final class EventDemandIntakeServiceTest extends \AIMS\Tests\TestCase {
	public function testNormalizeRequestRecordUsesAuthenticatedUserContext(): void {
		TestState::set_current_user_id( 42 );
		TestState::set_user(
			42,
			(object) array(
				'ID'           => 42,
				'display_name' => 'Jane Vendor',
				'user_login'   => 'jane.vendor',
				'user_email'   => 'jane@example.com',
			)
		);
		TestState::set_user_meta( 42, 'billing_phone', '(317) 555-0199' );

		$service = new AIMS_Event_Demand_Intake_Service(
			new class() {
				public function find( int $event_id ): array {
					return array( 'id' => $event_id, 'event_name' => 'Spring Show' );
				}
			}
		);

		$record = $service->normalize_request_record(
			array(
				'event_id'           => 10,
				'vendor_id'          => 5,
				'woo_product_id'     => 77,
				'product_sku'        => ' sku-1 ',
				'product_name'       => 'Demo Product',
				'quantity_requested' => '3',
			)
		);

		$this->assertSame( 42, $record['wp_user_id'] );
		$this->assertSame( 'Jane Vendor', $record['customer_name'] );
		$this->assertSame( 'jane@example.com', $record['customer_email'] );
		$this->assertSame( '(317) 555-0199', $record['customer_phone'] );
		$this->assertSame( 'SKU-1', $record['product_sku'] );
		$this->assertSame( 3.0, $record['quantity_requested'] );
		$this->assertSame( 'auto_accepted', $record['request_status'] );
		$this->assertSame( 'planning_signal', $record['item_status'] );
		$this->assertSame( 'planning_only', $record['demand_signal_type'] );
	}

	public function testValidateRequestRecordRejectsMissingLogin(): void {
		$service = new AIMS_Event_Demand_Intake_Service(
			new class() {
				public function find( int $event_id ): array {
					return array( 'id' => $event_id, 'event_name' => 'Spring Show' );
				}
			}
		);

		$result = $service->validate_request_record(
			array(
				'event_id'           => 10,
				'wp_user_id'         => 0,
				'woo_product_id'     => 77,
				'product_sku'        => 'SKU-1',
				'quantity_requested' => 2,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aims_login_required', $result->get_error_code() );
	}

	public function testValidateRequestRecordRejectsSkuMismatch(): void {
		TestState::set_user(
			42,
			(object) array(
				'ID'           => 42,
				'display_name' => 'Jane Vendor',
				'user_login'   => 'jane.vendor',
				'user_email'   => 'jane@example.com',
			)
		);

		TestState::set_product(
			77,
			new class() {
				public function is_virtual(): bool {
					return false;
				}

				public function get_sku(): string {
					return 'SKU-OTHER';
				}
			}
		);

		$service = new AIMS_Event_Demand_Intake_Service(
			new class() {
				public function find( int $event_id ): array {
					return array( 'id' => $event_id, 'event_name' => 'Spring Show' );
				}
			}
		);

		$result = $service->validate_request_record(
			array(
				'event_id'           => 10,
				'wp_user_id'         => 42,
				'woo_product_id'     => 77,
				'product_sku'        => 'SKU-1',
				'quantity_requested' => 2,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aims_woo_product_sku_mismatch', $result->get_error_code() );
	}
}
