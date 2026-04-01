<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class StitchPortalServiceTest extends \AIMS\Tests\TestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->registerRuntimeRoleFromTemplate(
			'aims_test_stitch_portal_user',
			\AIMS_Capabilities::ROLE_STITCH_USER,
			array(
				\AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL => true,
				\AIMS_Capabilities::CAP_RESP_STITCH_ORDER_MANAGEMENT => true,
			),
			'Test Stitch Portal User'
		);
	}

	public function testPageModelShowsStitchCustodyBucketsAndOpenJobs(): void {
		TestState::set_current_user_id( 88 );
		TestState::set_user(
			88,
			(object) array(
				'ID'          => 88,
				'roles'       => array( 'aims_test_stitch_portal_user' ),
				'display_name' => 'Stitcher One',
			)
		);
		TestState::set_user_capabilities( 88, array( \AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL ) );

		$service = new \AIMS_Stitch_Portal_Service(
			new class() extends \AIMS_Stitch_Job_Repository {
				public function get_open_for_user( int $user_id ): array {
					return array(
						array(
							'id'               => 701,
							'job_code'         => 'ST-701',
							'vendor_id'        => 0,
							'event_id'         => 900,
							'assigned_user_id' => $user_id,
							'status'           => self::STATUS_IN_PROGRESS,
							'priority'         => 'high',
							'due_at'           => '2026-04-01 12:00:00',
							'notes'            => 'Add trim to the front panel.',
						),
					);
				}
			},
			new class() extends \AIMS_Physical_Bucket_Repository {
				public function get_for_vendor( int $vendor_id ): array {
					return 88 === $vendor_id ? array(
						array(
							'id'          => 301,
							'bucket_code' => 'ST-301',
							'bucket_label' => 'Stitch Rack 1',
							'bucket_type' => \AIMS_Physical_Bucket_Types::STITCHER,
							'status'      => 'available',
						),
					) : array();
				}
			},
			new class() extends \AIMS_Bucket_Inventory_Position_Repository {
				public function get_bucket_contents_summary( int $bucket_id ): array {
					return 301 === $bucket_id ? array(
						array(
							'product_id'        => 501,
							'quantity'          => 12,
							'reserved_quantity'  => 2,
						),
						array(
							'product_id'        => 502,
							'quantity'          => 4,
							'reserved_quantity'  => 0,
						),
					) : array();
				}
			},
			new class() extends \AIMS_Event_Repository {
				public function find( int $event_id ): ?array {
					return 900 === $event_id ? array(
						'id'         => 900,
						'event_name' => 'Custom Work Batch',
					) : null;
				}
			}
		);

		$model = $service->get_page_model();

		$this->assertTrue( $model['logged_in'] );
		$this->assertTrue( $model['can_view'] );
		$this->assertSame( 'Stitcher One', $model['stitcher_name'] );
		$this->assertCount( 1, $model['stitcher_buckets'] );
		$this->assertSame( 'Stitch Rack 1 (ST-301)', $model['stitcher_buckets'][0]['display_label'] );
		$this->assertCount( 1, $model['open_jobs'] );
		$this->assertSame( 'Custom Work Batch', $model['open_jobs'][0]['event_name'] );
		$this->assertSame( 'In Progress', $model['open_jobs'][0]['job_status_label'] );
	}

	public function testPageModelSupportsCapabilityOnlyExternalStitchRole(): void {
		TestState::add_role(
			'site_stitch_operator',
			'Site Stitch Operator',
			array(
				\AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL => true,
				\AIMS_Capabilities::CAP_RESP_STITCH_ORDER_MANAGEMENT => true,
			)
		);

		TestState::set_current_user_id( 89 );
		TestState::set_user(
			89,
			(object) array(
				'ID'           => 89,
				'roles'        => array( 'site_stitch_operator' ),
				'display_name' => 'External Stitcher',
			)
		);

		$service = new \AIMS_Stitch_Portal_Service(
			new class() extends \AIMS_Stitch_Job_Repository {
				public function get_open_for_user( int $user_id ): array {
					return array(
						array(
							'id'               => 801,
							'job_code'         => 'ST-801',
							'event_id'         => 901,
							'assigned_user_id' => $user_id,
							'status'           => self::STATUS_IN_PROGRESS,
						),
					);
				}
			},
			new class() extends \AIMS_Physical_Bucket_Repository {
				public function get_for_vendor( int $vendor_id ): array {
					return 89 === $vendor_id ? array(
						array(
							'id'           => 302,
							'bucket_code'  => 'ST-302',
							'bucket_label' => 'Stitch Rack 2',
							'bucket_type'  => \AIMS_Physical_Bucket_Types::STITCHER,
							'status'       => 'available',
						),
					) : array();
				}
			},
			new class() extends \AIMS_Bucket_Inventory_Position_Repository {
				public function get_bucket_contents_summary( int $bucket_id ): array {
					return 302 === $bucket_id ? array(
						array(
							'product_id'        => 503,
							'quantity'          => 3,
							'reserved_quantity' => 0,
						),
					) : array();
				}
			},
			new class() extends \AIMS_Event_Repository {
				public function find( int $event_id ): ?array {
					return 901 === $event_id ? array(
						'id'         => 901,
						'event_name' => 'External Stitch Batch',
					) : null;
				}
			}
		);

		$model = $service->get_page_model();

		$this->assertTrue( $model['logged_in'] );
		$this->assertTrue( $model['can_view'] );
		$this->assertSame( 'External Stitcher', $model['stitcher_name'] );
		$this->assertCount( 1, $model['stitcher_buckets'] );
		$this->assertCount( 1, $model['open_jobs'] );
		$this->assertSame( 'External Stitch Batch', $model['open_jobs'][0]['event_name'] );
	}

	public function testCompleteJobMarksWorkInTransitBackWithoutReceiptClaim(): void {
		TestState::set_current_user_id( 88 );
		TestState::set_user(
			88,
			(object) array(
				'ID'          => 88,
				'roles'       => array( 'aims_test_stitch_portal_user' ),
				'display_name' => 'Stitcher One',
			)
		);
		TestState::set_user_capabilities( 88, array( \AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL ) );

		$job_repo = new class() extends \AIMS_Stitch_Job_Repository {
			public array $calls = array();

			public function find( int $job_id ): ?array {
				return 701 === $job_id ? array(
					'id'               => 701,
					'job_code'         => 'ST-701',
					'vendor_id'        => 0,
					'event_id'         => 900,
					'assigned_user_id' => 88,
					'status'           => self::STATUS_STITCHING,
				) : null;
			}

			public function mark_complete_and_in_transit_back( int $job_id, int $user_id = 0, string $notes = '' ): bool {
				$this->calls[] = array(
					'job_id' => $job_id,
					'user_id' => $user_id,
					'notes'   => $notes,
				);

				return true;
			}
		};

		$service = new \AIMS_Stitch_Portal_Service(
			$job_repo,
			new class() extends \AIMS_Physical_Bucket_Repository {},
			new class() extends \AIMS_Bucket_Inventory_Position_Repository {},
			new class() extends \AIMS_Event_Repository {}
		);

		$result = $service->complete_job(
			array(
				'stitch_job_id'     => 701,
				'completion_notes'  => 'Line complete, heading back to warehouse.',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Stitch work item marked complete and in transit back.', $result['message'] );
		$this->assertSame( \AIMS_Stitch_Job_Repository::STATUS_IN_TRANSIT_BACK, $result['status'] );
		$this->assertCount( 1, $job_repo->calls );
		$this->assertSame( 701, $job_repo->calls[0]['job_id'] );
		$this->assertSame( 88, $job_repo->calls[0]['user_id'] );
		$this->assertStringContainsString( 'Line complete', $job_repo->calls[0]['notes'] );
	}

	public function testCompleteJobRejectsWorkNotAssignedToCurrentStitcher(): void {
		TestState::set_current_user_id( 88 );
		TestState::set_user(
			88,
			(object) array(
				'ID'          => 88,
				'roles'       => array( 'aims_test_stitch_portal_user' ),
			)
		);
		TestState::set_user_capabilities( 88, array( \AIMS_Capabilities::CAP_VIEW_STITCH_PORTAL ) );

		$service = new \AIMS_Stitch_Portal_Service(
			new class() extends \AIMS_Stitch_Job_Repository {
				public function find( int $job_id ): ?array {
					return array(
						'id'               => 701,
						'job_code'         => 'ST-701',
						'vendor_id'        => 0,
						'event_id'         => 900,
						'assigned_user_id' => 99,
						'status'           => self::STATUS_STITCHING,
					);
				}
			}
		);

		$result = $service->complete_job(
			array(
				'stitch_job_id' => 701,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'This stitch work item is not assigned to your account.', $result['message'] );
	}
}
