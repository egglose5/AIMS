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
		$this->assertCount( 2, $route_calls );
		$this->assertSame( 'aims/v1', $route_calls[0]['args'][0] );
		$this->assertSame( '/integrations/inventory', $route_calls[0]['args'][1] );
		$this->assertSame( '/integrations/updates', $route_calls[1]['args'][1] );
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
}