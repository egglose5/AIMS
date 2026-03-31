<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Event_Bucket_Assignment_Service;
use AIMS\Tests\Support\TestState;

final class EventBucketAssignmentServiceTest extends \AIMS\Tests\TestCase {
	public function testAssignBucketToEventPersistsNormalizedRecord(): void {
		$repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public array $saved = array();

			public function save( array $data, int $assignment_id = 0 ): int {
				$this->saved[] = array(
					'data'          => $data,
					'assignment_id' => $assignment_id,
				);

				return 77;
			}
		};

		$service = new AIMS_Event_Bucket_Assignment_Service( $repo );
		$result  = $service->assign_bucket_to_event(
			array(
				'event_id'           => 10,
				'physical_bucket_id' => 200,
				'assignment_status'  => 'assigned',
				'assignment_type'    => 'event_stock',
				'assigned_at'        => '2026-03-20 10:00:00',
				'assigned_by'        => 42,
				'display_order'      => 3,
				'is_active'          => 1,
				'notes'              => '<strong>planner note</strong>',
			)
		);

		$this->assertSame( 77, $result );
		$this->assertCount( 1, $repo->saved );
		$this->assertSame( 10, $repo->saved[0]['data']['event_id'] );
		$this->assertSame( 200, $repo->saved[0]['data']['physical_bucket_id'] );
		$this->assertSame( 'assigned', $repo->saved[0]['data']['assignment_status'] );
		$this->assertSame( 'event_stock', $repo->saved[0]['data']['assignment_type'] );
		$this->assertSame( '<strong>planner note</strong>', $repo->saved[0]['data']['notes'] );
	}

	public function testReleaseBucketFromEventDelegatesToRepositoryRelease(): void {
		$repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public array $released = array();

			public function release( int $assignment_id, array $data = array() ): bool {
				$this->released[] = array(
					'assignment_id' => $assignment_id,
					'data'          => $data,
				);

				return true;
			}
		};

		$service = new AIMS_Event_Bucket_Assignment_Service( $repo );
		$result  = $service->release_bucket_from_event(
			88,
			array(
				'assignment_status' => 'released',
				'released_at'       => '2026-03-21 14:00:00',
				'released_by'       => 42,
				'notes'             => '<em>released</em>',
			)
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $repo->released );
		$this->assertSame( 88, $repo->released[0]['assignment_id'] );
		$this->assertSame( 'released', $repo->released[0]['data']['assignment_status'] );
		$this->assertSame( '<em>released</em>', $repo->released[0]['data']['notes'] );
	}

	public function testAssignBucketToEventRejectsAmbiguousUnownedBucketForMultiVendorEvent(): void {
		$repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public int $save_calls = 0;

			public function save( array $data, int $assignment_id = 0 ): int {
				++$this->save_calls;

				return 77;
			}
		};

		$vendor_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function get_for_event( int $event_id ): array {
				if ( 10 !== $event_id ) {
					return array();
				}

				return array(
					array( 'vendor_id' => 5 ),
					array( 'vendor_id' => 9 ),
				);
			}
		};

		$buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function find( int $bucket_id ): ?array {
				if ( 200 !== $bucket_id ) {
					return null;
				}

				return array(
					'id'        => 200,
					'vendor_id' => 0,
				);
			}
		};

		$service = new AIMS_Event_Bucket_Assignment_Service( $repo, $vendor_assignments, $buckets );
		$result  = $service->assign_bucket_to_event(
			array(
				'event_id'           => 10,
				'physical_bucket_id' => 200,
			)
		);

		$this->assertSame( 0, $result );
		$this->assertSame( 0, $repo->save_calls );
	}

	public function testAssignBucketToEventRejectsBucketOwnedByDifferentVendor(): void {
		$repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public int $save_calls = 0;

			public function save( array $data, int $assignment_id = 0 ): int {
				++$this->save_calls;

				return 77;
			}
		};

		$vendor_assignments = new class() extends \AIMS_Vendor_Event_Assignment_Repository {
			public function get_for_event( int $event_id ): array {
				if ( 10 !== $event_id ) {
					return array();
				}

				return array(
					array( 'vendor_id' => 5 ),
					array( 'vendor_id' => 9 ),
				);
			}
		};

		$buckets = new class() extends \AIMS_Physical_Bucket_Repository {
			public function find( int $bucket_id ): ?array {
				if ( 200 !== $bucket_id ) {
					return null;
				}

				return array(
					'id'        => 200,
					'vendor_id' => 77,
				);
			}
		};

		$service = new AIMS_Event_Bucket_Assignment_Service( $repo, $vendor_assignments, $buckets );
		$result  = $service->assign_bucket_to_event(
			array(
				'event_id'           => 10,
				'physical_bucket_id' => 200,
			)
		);

		$this->assertSame( 0, $result );
		$this->assertSame( 0, $repo->save_calls );
	}

	public function testTransitionAssignmentStatusPreservesExistingTransitTimestamps(): void {
		TestState::set_current_time( '2026-03-27 18:00:00' );

		$repo = new class() extends \AIMS_Event_Bucket_Assignment_Repository {
			public array $saved = array();

			public function find( int $assignment_id ): ?array {
				return array(
					'id'                 => $assignment_id,
					'event_id'           => 10,
					'physical_bucket_id' => 200,
					'assignment_status'  => self::STATUS_STAGED,
					'loaded_at'          => '2026-03-27 07:15:00',
					'in_transit_at'      => '2026-03-27 07:20:00',
					'is_active'          => 1,
				);
			}

			public function save( array $data, int $assignment_id = 0 ): int {
				$this->saved[] = array(
					'data'          => $data,
					'assignment_id' => $assignment_id,
				);

				return $assignment_id;
			}
		};

		$service = new AIMS_Event_Bucket_Assignment_Service( $repo );
		$result  = $service->transition_assignment_status( 88, \AIMS_Event_Bucket_Assignment_Repository::STATUS_IN_TRANSIT );

		$this->assertTrue( $result );
		$this->assertCount( 1, $repo->saved );
		$this->assertSame( '2026-03-27 07:15:00', $repo->saved[0]['data']['loaded_at'] );
		$this->assertSame( '2026-03-27 07:20:00', $repo->saved[0]['data']['in_transit_at'] );
	}
}
