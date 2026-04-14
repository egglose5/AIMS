<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class IntegrationRestControllerTest extends \AIMS\Tests\TestCase {
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_AMES_TOKEN'] );
		unset( $_SERVER['HTTP_X_AIMS_TOKEN'] );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tearDown();
	}

	public function testRegisterRoutesRegistersIngestAndFeedRoutes(): void {
		$controller = new \AIMS_Integration_Rest_Controller();
		$controller->register();
		$controller->register_routes();

		$rest_hooks  = TestState::get_hook_calls( 'rest_api_init' );
		$route_calls = TestState::get_hook_calls( 'register_rest_route' );

		$this->assertNotEmpty( $rest_hooks );
		$this->assertCount( 5, $route_calls );
		$this->assertSame( 'aims/v1', $route_calls[0]['args'][0] );
		$this->assertSame( '/integrations/inventory', $route_calls[0]['args'][1] );
		$this->assertSame( '/integrations/updates', $route_calls[1]['args'][1] );
		$this->assertSame( '/integrations/roles', $route_calls[2]['args'][1] );
		$this->assertSame( '/integrations/accounts', $route_calls[3]['args'][1] );
		$this->assertSame( '/integrations/role-accounts', $route_calls[4]['args'][1] );
	}

	public function testIngestRejectsInvalidToken(): void {
		update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token', false );

		$controller = new \AIMS_Integration_Rest_Controller();
		$response   = $controller->ingest_inventory_updates(
			new \WP_REST_Request(
				'POST',
				'/aims/v1/integrations/inventory',
				array(
					'updates' => array(
						array(
							'sku' => 'A-1',
						),
					),
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 401, $response->get_status() );
	}

	public function testIngestAndFeedSucceedWithValidToken(): void {
		update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token', false );

		$service = new class() extends \AIMS_Integration_Update_Service {
			public array $ingested = array();

			public function __construct() {
			}

			public function ingest_updates( array $payload, array $context = array() ): array {
				$this->ingested[] = array(
					'payload' => $payload,
					'context' => $context,
				);

				return array(
					'accepted'       => 1,
					'skipped'        => 0,
					'total_received' => 1,
					'latest_cursor'  => '2026-04-11 10:30:00',
				);
			}

			public function get_feed_snapshot( string $since = '', int $limit = 50 ): array {
				return array(
					'generated_at'      => '2026-04-11 11:00:00',
					'latest_cursor'     => '2026-04-11 10:30:00',
					'updates_count'     => 1,
					'updates'           => array(
						array(
							'sku' => 'AUTO-1',
						),
					),
					'low_stock_summary' => array(
						'low_stock_products' => 1,
					),
					'low_stock_alerts'  => array(),
				);
			}
		};

		$controller = new \AIMS_Integration_Rest_Controller( $service );
		$ingest     = $controller->ingest_inventory_updates(
			new \WP_REST_Request(
				'POST',
				'/aims/v1/integrations/inventory',
				array(
					'token'   => 'secret-token',
					'updates' => array(
						array(
							'sku' => 'AUTO-1',
						),
					),
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $ingest );
		$this->assertSame( 202, $ingest->get_status() );
		$this->assertCount( 1, $service->ingested );

		$feed = $controller->get_updates_feed(
			new \WP_REST_Request(
				'GET',
				'/aims/v1/integrations/updates',
				array(
					'token' => 'secret-token',
					'limit' => 25,
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $feed );
		$this->assertSame( 200, $feed->get_status() );

		$data = $feed->get_data();
		$this->assertSame( 1, $data['updates_count'] );
		$this->assertSame( 'AUTO-1', $data['updates'][0]['sku'] );
	}

	public function testRolesCatalogReturnsTemplatesAndRuntimeRoles(): void {
		update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token', false );
		$this->registerRuntimeRoleFromTemplate(
			'aims_api_vendor',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
			),
			'AIMS API Vendor'
		);

		$controller = new \AIMS_Integration_Rest_Controller();
		$response   = $controller->get_roles_catalog(
			new \WP_REST_Request(
				'GET',
				'/aims/v1/integrations/roles',
				array(
					'token' => 'secret-token',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'ready', $data['status'] );
		$this->assertNotEmpty( $data['templates'] );
		$this->assertSame( 'aims_vendor_user', $data['templates'][0]['role_slug'] );

		$runtime_slugs = array_column( $data['runtime_roles'], 'role_slug' );
		$this->assertContains( 'aims_api_vendor', $runtime_slugs );
	}

	public function testAccountSnapshotReturnsRolesAndAimsCapabilities(): void {
		update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token', false );
		$this->registerRuntimeRoleFromTemplate(
			'aims_api_vendor',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL         => true,
				\AIMS_Capabilities::CAP_RESP_VENDOR_SUBMIT_CHECKIN => true,
			),
			'AIMS API Vendor'
		);

		TestState::set_user(
			41,
			(object) array(
				'ID'           => 41,
				'user_login'   => 'vendor-api',
				'user_email'   => 'vendor@example.test',
				'display_name' => 'Vendor API',
				'roles'        => array( 'aims_api_vendor' ),
			)
		);
		TestState::set_user_capabilities(
			41,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL,
				\AIMS_Capabilities::CAP_RESP_VENDOR_SUBMIT_CHECKIN,
			)
		);

		$controller = new \AIMS_Integration_Rest_Controller();
		$response   = $controller->get_account_snapshot(
			new \WP_REST_Request(
				'GET',
				'/aims/v1/integrations/accounts',
				array(
					'token'    => 'secret-token',
					'username' => 'vendor-api',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'ready', $data['status'] );
		$this->assertSame( 41, $data['account']['user_id'] );
		$this->assertSame( 'vendor-api', $data['account']['username'] );
		$this->assertSame( 'aims-vendor-api-41', $data['account']['local_key'] );
		$this->assertSame( 'Local App Key', $data['account']['local_key_meta']['title'] );
		$this->assertSame( 'aims-vendor-api-41', $data['account']['local_key_meta']['value'] );
		$this->assertSame( 'aims-vendor-api-41', $data['account']['local_key_meta']['copy_value'] );
		$this->assertCount( 4, $data['account']['local_key_meta']['segments'] );
		$this->assertSame( 'prefix', $data['account']['local_key_meta']['segments'][0]['label'] );
		$this->assertSame( 'aims', $data['account']['local_key_meta']['segments'][0]['value'] );
		$this->assertContains( 'aims_api_vendor', $data['account']['roles'] );
		$this->assertContains( 'vendor', $data['account']['person_subtypes'] );
		$this->assertContains( \AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL, $data['account']['aims_capabilities'] );
		$this->assertSame( 'aims_api_vendor', $data['account']['role_details'][0]['role_slug'] );
	}

	public function testAccountSnapshotRequiresLookupParameter(): void {
		update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token', false );

		$controller = new \AIMS_Integration_Rest_Controller();
		$response   = $controller->get_account_snapshot(
			new \WP_REST_Request(
				'GET',
				'/aims/v1/integrations/accounts',
				array(
					'token' => 'secret-token',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status() );
	}

	public function testRoleAccountsDirectoryReturnsOnlyRequestedAimsRolesWithEmailsAndLocalKeys(): void {
		update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token', false );
		$this->registerRuntimeRoleFromTemplate(
			'aims_api_vendor',
			\AIMS_Capabilities::ROLE_VENDOR_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_VENDOR_PORTAL => true,
			),
			'AIMS API Vendor'
		);
		$this->registerRuntimeRoleFromTemplate(
			'aims_api_stitch',
			\AIMS_Capabilities::ROLE_STITCH_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL => true,
			),
			'AIMS API Stitch'
		);

		TestState::set_user(
			41,
			(object) array(
				'ID'           => 41,
				'user_login'   => 'vendor-api',
				'user_email'   => 'vendor@example.test',
				'display_name' => 'Vendor API',
				'roles'        => array( 'aims_api_vendor' ),
			)
		);
		TestState::set_user(
			42,
			(object) array(
				'ID'           => 42,
				'user_login'   => 'stitch-api',
				'user_email'   => 'stitch@example.test',
				'display_name' => 'Stitch API',
				'roles'        => array( 'aims_api_stitch' ),
			)
		);
		TestState::set_user(
			43,
			(object) array(
				'ID'           => 43,
				'user_login'   => 'subscriber-user',
				'user_email'   => 'subscriber@example.test',
				'display_name' => 'Subscriber User',
				'roles'        => array( 'subscriber' ),
			)
		);

		$controller = new \AIMS_Integration_Rest_Controller();
		$response   = $controller->get_role_accounts(
			new \WP_REST_Request(
				'GET',
				'/aims/v1/integrations/role-accounts',
				array(
					'token' => 'secret-token',
					'roles' => 'aims_api_vendor',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( array( 'aims_api_vendor' ), $data['requested_roles'] );
		$this->assertCount( 1, $data['accounts'] );
		$this->assertSame( 'vendor@example.test', $data['accounts'][0]['email'] );
		$this->assertSame( 'aims-vendor-api-41', $data['accounts'][0]['local_key'] );
		$this->assertSame( 'credentials', $data['accounts'][0]['local_key_meta']['category'] );
		$this->assertSame( 'aims_api_vendor', $data['accounts'][0]['local_key_meta']['role_slug'] );
		$this->assertSame( 'account', $data['accounts'][0]['local_key_meta']['segments'][1]['label'] );
		$this->assertSame( array( 'aims_api_vendor' ), $data['accounts'][0]['roles'] );
	}

	public function testRoleAccountsDirectoryRequiresRolesParameter(): void {
		update_option( \AIMS_Plugin::OPTION_API_TOKEN, 'secret-token', false );

		$controller = new \AIMS_Integration_Rest_Controller();
		$response   = $controller->get_role_accounts(
			new \WP_REST_Request(
				'GET',
				'/aims/v1/integrations/role-accounts',
				array(
					'token' => 'secret-token',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status() );
	}
}
