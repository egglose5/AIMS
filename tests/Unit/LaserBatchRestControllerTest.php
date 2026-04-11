<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class LaserBatchRestControllerTest extends \AIMS\Tests\TestCase {
	public function testRegisterRoutesExposesWooCommerceLaserBatchEndpoint(): void {
		$controller = new \AIMS_Laser_Batch_Rest_Controller( new \AIMS_Headless_Api_Client( 'https://aims-core.test', 'secret-token' ) );
		$controller->register();
		$controller->register_routes();

		$rest_hooks = TestState::get_hook_calls( 'rest_api_init' );
		$route_calls = TestState::get_hook_calls( 'register_rest_route' );

		$this->assertNotEmpty( $rest_hooks );
		$this->assertCount( 2, $route_calls );
		$this->assertSame( 'wc/v3', $route_calls[0]['args'][0] );
		$this->assertSame( '/aims/laser-batches', $route_calls[0]['args'][1] );
	}

	public function testPushBatchProxiesPayloadToHeadlessLaserIngress(): void {
		TestState::set_current_user_id( 88 );
		TestState::set_user_capabilities(
			88,
			array(
				' manage_woocommerce ',
				\AIMS_Capabilities::CAP_MANAGE,
			)
		);

		$client = new class() extends \AIMS_Headless_Api_Client {
			public array $pushed = array();

			public function __construct() {
				parent::__construct( 'https://aims-core.test', 'secret-token' );
			}

			public function push_laser_batch( array $payload ): array {
				$this->pushed[] = $payload;

				return array(
					'success' => true,
					'json'    => array(
						'batch' => array(
							'batch_id' => (string) ( $payload['batch_id'] ?? '' ),
							'status'   => 'accepted',
						),
					),
					'message' => 'Laser batch accepted into the AIMS sink.',
				);
			}
		};

		$controller = new \AIMS_Laser_Batch_Rest_Controller( $client );
		$response   = $controller->push_batch(
			new \WP_REST_Request(
				'POST',
				'/wc/v3/aims/laser-batches',
				array(
					'batch_id'      => 'laser-run-77',
					'stitch_job_id' => 991,
					'items'         => array(
						array(
							'sku'      => 'PATCH-RED',
							'quantity' => 12,
						),
					),
				)
			)
		);

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;

		$this->assertCount( 1, $client->pushed );
		$this->assertSame( 'laser-run-77', $client->pushed[0]['batch_id'] );
		$this->assertSame( 'accepted', $data['status'] );
		$this->assertSame( 'wc/v3/aims/laser-batches', $data['route'] );
	}
}
