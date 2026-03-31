<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Tests\Support\TestState;

final class VendorPortalCheckInServiceTest extends \AIMS\Tests\TestCase {
	public function testFirstCheckInCreatesOperationalRecordExecutesArrivalAndPublishesUpdate(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_current_time( '2026-03-23 12:00:00' );
		TestState::set_user(
			77,
			(object) array(
				'ID'    => 77,
				'roles' => array( 'aims_vendor_user' ),
			)
		);

		$service = new \AIMS_Vendor_Event_Checkin_Portal_Service(
			new class() extends \AIMS_Event_Planning_Access_Service {
				public function __construct() {}

				public function get_authorized_vendor_ids( int $user_id = 0 ): array {
					return 77 === $user_id ? array( 5 ) : array();
				}
			},
			new class() extends \AIMS_Event_Repository {
				public function find( int $event_id ): ?array {
					return 10 === $event_id ? array(
						'id'            => 10,
						'event_name'    => 'Spring Show',
						'start_date'    => '2026-03-25',
						'end_date'      => '2026-03-27',
						'location_name' => 'Main Hall',
						'status'        => 'published',
					) : null;
				}

				public function all(): array {
					return array( $this->find( 10 ) );
				}
			},
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {
				public function get_for_event( int $event_id ): array {
					return 10 === $event_id ? array(
						array(
							'id'       => 91,
							'event_id' => 10,
							'vendor_id' => 5,
						),
					) : array();
				}
			},
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {
				public function get_active_for_event( int $event_id ): array {
					return 10 === $event_id ? array(
						array(
							'id'                 => 400,
							'event_id'           => 10,
							'physical_bucket_id' => 200,
							'assignment_status'  => self::STATUS_IN_TRANSIT,
							'is_active'          => 1,
						),
					) : array();
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Repository {
				public array $saved = array();
				public array $movement_marks = array();
				public array $public_marks = array();

				public function is_first_checkin( int $event_id, int $vendor_id, int $bucket_id = 0 ): bool {
					return true;
				}

				public function save( array $data, int $checkin_id = 0 ): int {
					$this->saved[] = $data;
					return 701;
				}

				public function mark_movement_applied( int $checkin_id, array $data = array() ): bool {
					$this->movement_marks[] = array(
						'checkin_id' => $checkin_id,
						'data'       => $data,
					);
					return true;
				}

				public function mark_public_update_created( int $checkin_id, int $public_event_update_id ): bool {
					$this->public_marks[] = array(
						'checkin_id'             => $checkin_id,
						'public_event_update_id' => $public_event_update_id,
					);
					return true;
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Media_Repository {
				public array $saved = array();
				public array $attached = array();
				public array $primary = array();
				private int $next_id = 1;

				public function save( array $data, int $media_id = 0 ): int {
					$this->saved[] = $data;
					return $this->next_id++;
				}

				public function attach_to_public_event_update( int $media_id, int $public_event_update_id ): bool {
					$this->attached[] = array(
						'media_id'              => $media_id,
						'public_event_update_id' => $public_event_update_id,
					);
					return true;
				}

				public function mark_primary( int $media_id ): bool {
					$this->primary[] = $media_id;
					return true;
				}
			},
			new class() extends \AIMS_Event_Execution_Service {
				public array $calls = array();

				public function __construct() {}

				public function vendor_event_checkin( array $data ): array {
					$this->calls[] = $data;
					return array(
						'success'       => true,
						'message'       => 'Vendor event check-in recorded.',
						'assignment_id' => (int) ( $data['assignment_id'] ?? 0 ),
						'event_id'      => 10,
						'status'        => 'at_event',
						'movement_triggered' => true,
					);
				}
			},
			new class() extends \AIMS_Public_Event_Projection_Service {
				public array $payloads = array();

				public function __construct() {}

				public function save_public_event_update( array $payload ): array {
					$this->payloads[] = $payload;
					return array(
						'success'   => true,
						'update_id' => 901,
					);
				}

				public function get_public_event_updates( int $event_id, array $args = array() ): array {
					return array();
				}
			},
			static function ( array $file, string $field_name ): array {
				return array(
					'file_url'      => 'https://cdn.example.test/' . $field_name . '/' . rawurlencode( (string) $file['name'] ),
					'attachment_id' => 0,
				);
			}
		);

		$result = $service->submit_checkin(
			array(
				'event_id'             => 10,
				'bucket_assignment_id' => 400,
				'checkin_notes'        => 'Arrived and set up.',
				'location_notes'       => 'Parking is around the back.',
			),
			$this->buildUploadFiles()
		);

		/** @var object $checkins */
		$checkins = $this->readProperty( $service, 'checkins' );
		/** @var object $media */
		$media = $this->readProperty( $service, 'checkin_media' );
		/** @var object $execution */
		$execution = $this->readProperty( $service, 'execution_service' );
		/** @var object $projection */
		$projection = $this->readProperty( $service, 'public_projection' );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['is_first_checkin'] );
		$this->assertSame( 701, $result['checkin_id'] );
		$this->assertSame( 901, $result['update_id'] );
		$this->assertCount( 1, $checkins->saved );
		$this->assertCount( 1, $execution->calls );
		$this->assertSame( 400, $execution->calls[0]['assignment_id'] );
		$this->assertCount( 1, $checkins->movement_marks );
		$this->assertCount( 1, $checkins->public_marks );
		$this->assertCount( 2, $media->saved );
		$this->assertSame( 901, $media->attached[0]['public_event_update_id'] );
		$this->assertCount( 1, $projection->payloads );
		$this->assertSame( 'Spring Show check-in update', $projection->payloads[0]['update_title'] );
		$this->assertSame( 'Vendor Check-In', $projection->payloads[0]['source_label'] );
		$this->assertSame( 'Parking is around the back.', $projection->payloads[0]['update_summary'] );
		$this->assertStringContainsString( 'Arrived and set up.', (string) $projection->payloads[0]['update_body'] );
		$this->assertStringContainsString( 'vendor-checkin-701', (string) $projection->payloads[0]['source_reference'] );
	}

	public function testLaterCheckInPublishesUpdateOnly(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_current_time( '2026-03-23 12:00:00' );
		TestState::set_user(
			77,
			(object) array(
				'ID'    => 77,
				'roles' => array( 'aims_vendor_user' ),
			)
		);

		$service = new \AIMS_Vendor_Event_Checkin_Portal_Service(
			new class() extends \AIMS_Event_Planning_Access_Service {
				public function __construct() {}

				public function get_authorized_vendor_ids( int $user_id = 0 ): array {
					return array( 5 );
				}
			},
			new class() extends \AIMS_Event_Repository {
				public function find( int $event_id ): ?array {
					return array(
						'id'            => 10,
						'event_name'    => 'Spring Show',
						'start_date'    => '2026-03-25',
						'end_date'      => '2026-03-27',
						'location_name' => 'Main Hall',
						'status'        => 'published',
					);
				}

				public function all(): array {
					return array( $this->find( 10 ) );
				}
			},
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {
				public function get_for_event( int $event_id ): array {
					return array(
						array(
							'id'       => 91,
							'event_id' => 10,
							'vendor_id' => 5,
						),
					);
				}
			},
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {
				public function get_active_for_event( int $event_id ): array {
					return array(
						array(
							'id'                 => 400,
							'event_id'           => 10,
							'physical_bucket_id' => 200,
							'assignment_status'  => self::STATUS_AT_EVENT,
							'is_active'          => 1,
						),
					);
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Repository {
				public int $save_count = 0;
				public int $public_mark_count = 0;

				public function is_first_checkin( int $event_id, int $vendor_id, int $bucket_id = 0 ): bool {
					return false;
				}

				public function save( array $data, int $checkin_id = 0 ): int {
					++$this->save_count;
					return 999;
				}

				public function mark_public_update_created( int $checkin_id, int $public_event_update_id ): bool {
					++$this->public_mark_count;
					return true;
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Media_Repository {
				public array $saved = array();
				public array $attached = array();

				public function save( array $data, int $media_id = 0 ): int {
					$this->saved[] = $data;
					return count( $this->saved );
				}

				public function attach_to_public_event_update( int $media_id, int $public_event_update_id ): bool {
					$this->attached[] = array( $media_id, $public_event_update_id );
					return true;
				}

				public function mark_primary( int $media_id ): bool {
					return true;
				}
			},
			new class() extends \AIMS_Event_Execution_Service {
				public int $call_count = 0;

				public function __construct() {}

				public function vendor_event_checkin( array $data ): array {
					++$this->call_count;
					return array(
						'success' => true,
						'movement_triggered' => true,
					);
				}
			},
			new class() extends \AIMS_Public_Event_Projection_Service {
				public array $payloads = array();

				public function __construct() {}

				public function save_public_event_update( array $payload ): array {
					$this->payloads[] = $payload;
					return array(
						'success'   => true,
						'update_id' => 333,
					);
				}

				public function get_public_event_updates( int $event_id, array $args = array() ): array {
					return array();
				}
			},
			static function ( array $file, string $field_name ): array {
				return array(
					'file_url'      => 'https://cdn.example.test/' . rawurlencode( (string) $file['name'] ),
					'attachment_id' => 0,
				);
			}
		);

		$result = $service->submit_checkin(
			array(
				'event_id'             => 10,
				'bucket_assignment_id' => 400,
				'checkin_notes'        => 'Crowd is building.',
				'location_notes'       => 'Booth is fully open.',
			),
			$this->buildUploadFiles()
		);

		/** @var object $checkins */
		$checkins = $this->readProperty( $service, 'checkins' );
		/** @var object $execution */
		$execution = $this->readProperty( $service, 'execution_service' );
		/** @var object $projection */
		$projection = $this->readProperty( $service, 'public_projection' );
		/** @var object $media */
		$media = $this->readProperty( $service, 'checkin_media' );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['is_first_checkin'] );
		$this->assertSame( 0, $result['checkin_id'] );
		$this->assertSame( 0, $execution->call_count );
		$this->assertSame( 0, $checkins->save_count );
		$this->assertSame( 0, $checkins->public_mark_count );
		$this->assertCount( 1, $projection->payloads );
		$this->assertSame( 'Spring Show live update', $projection->payloads[0]['update_title'] );
		$this->assertSame( 'Vendor Live Update', $projection->payloads[0]['source_label'] );
		$this->assertCount( 2, $media->saved );
		$this->assertCount( 2, $media->attached );
	}

	public function testCheckInWindowIsEnforcedBeforeUploadsRun(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_current_time( '2026-03-20 08:00:00' );
		TestState::set_user(
			77,
			(object) array(
				'ID'    => 77,
				'roles' => array( 'aims_vendor_user' ),
			)
		);

		$service = new \AIMS_Vendor_Event_Checkin_Portal_Service(
			new class() extends \AIMS_Event_Planning_Access_Service {
				public function __construct() {}

				public function get_authorized_vendor_ids( int $user_id = 0 ): array {
					return array( 5 );
				}
			},
			new class() extends \AIMS_Event_Repository {
				public function find( int $event_id ): ?array {
					return array(
						'id'         => 10,
						'event_name' => 'Spring Show',
						'start_date' => '2026-03-25',
						'end_date'   => '2026-03-27',
					);
				}

				public function all(): array {
					return array( $this->find( 10 ) );
				}
			},
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {
				public function get_for_event( int $event_id ): array {
					return array(
						array(
							'id'       => 91,
							'event_id' => 10,
							'vendor_id' => 5,
						),
					);
				}
			},
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {
				public function get_active_for_event( int $event_id ): array {
					return array(
						array(
							'id'                 => 400,
							'event_id'           => 10,
							'physical_bucket_id' => 200,
							'assignment_status'  => self::STATUS_IN_TRANSIT,
							'is_active'          => 1,
						),
					);
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Repository {
				public function is_first_checkin( int $event_id, int $vendor_id, int $bucket_id = 0 ): bool {
					return true;
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Media_Repository {},
			new class() extends \AIMS_Event_Execution_Service {
				public function __construct() {}
			},
			new class() extends \AIMS_Public_Event_Projection_Service {
				public function __construct() {}
			},
			static function ( array $file, string $field_name ): array {
				throw new \RuntimeException( 'Uploader should not be reached when the check-in window is closed.' );
			}
		);

		$result = $service->submit_checkin(
			array(
				'event_id'             => 10,
				'bucket_assignment_id' => 400,
			),
			$this->buildUploadFiles()
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Vendor check-in is available starting three days before event start.', $result['message'] );
	}

	public function testFirstCheckInRejectsBucketAssignedToDifferentVendor(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_current_time( '2026-03-23 12:00:00' );
		TestState::set_user(
			77,
			(object) array(
				'ID'    => 77,
				'roles' => array( 'aims_vendor_user' ),
			)
		);

		$service = new \AIMS_Vendor_Event_Checkin_Portal_Service(
			new class() extends \AIMS_Event_Planning_Access_Service {
				public function __construct() {}

				public function get_authorized_vendor_ids( int $user_id = 0 ): array {
					return array( 5 );
				}
			},
			new class() extends \AIMS_Event_Repository {
				public function find( int $event_id ): ?array {
					return array(
						'id'         => 10,
						'event_name' => 'Spring Show',
						'start_date' => '2026-03-25',
						'end_date'   => '2026-03-27',
					);
				}

				public function all(): array {
					return array( $this->find( 10 ) );
				}
			},
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {
				public function get_for_event( int $event_id ): array {
					return array(
						array( 'id' => 91, 'event_id' => 10, 'vendor_id' => 5 ),
						array( 'id' => 92, 'event_id' => 10, 'vendor_id' => 9 ),
					);
				}
			},
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {
				public function get_active_for_event( int $event_id ): array {
					return array(
						array(
							'id'                 => 400,
							'event_id'           => 10,
							'physical_bucket_id' => 200,
							'assignment_status'  => self::STATUS_IN_TRANSIT,
							'is_active'          => 1,
						),
					);
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Repository {
				public function is_first_checkin( int $event_id, int $vendor_id, int $bucket_id = 0 ): bool {
					return true;
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Media_Repository {},
			new class() extends \AIMS_Event_Execution_Service {
				public function __construct() {}
			},
			new class() extends \AIMS_Public_Event_Projection_Service {
				public function __construct() {}
			},
			static function ( array $file, string $field_name ): array {
				throw new \RuntimeException( 'Uploader should not be reached for invalid vendor bucket selection.' );
			},
			new class() extends \AIMS_Physical_Bucket_Repository {
				public function find( int $bucket_id ): ?array {
					if ( 200 !== $bucket_id ) {
						return null;
					}

					return array(
						'id'        => 200,
						'vendor_id' => 9,
					);
				}
			}
		);

		$result = $service->submit_checkin(
			array(
				'event_id'             => 10,
				'bucket_assignment_id' => 400,
			),
			$this->buildUploadFiles()
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Select an assigned event bucket before the first vendor check-in.', $result['message'] );
	}

	public function testPageModelFiltersBucketOptionsToCurrentVendor(): void {
		TestState::set_current_user_id( 77 );
		TestState::set_current_time( '2026-03-23 12:00:00' );
		TestState::set_user(
			77,
			(object) array(
				'ID'    => 77,
				'roles' => array( 'aims_vendor_user' ),
			)
		);

		$service = new \AIMS_Vendor_Event_Checkin_Portal_Service(
			new class() extends \AIMS_Event_Planning_Access_Service {
				public function __construct() {}

				public function get_authorized_vendor_ids( int $user_id = 0 ): array {
					return array( 5 );
				}
			},
			new class() extends \AIMS_Event_Repository {
				public function find( int $event_id ): ?array {
					return array(
						'id'            => 10,
						'event_name'    => 'Spring Show',
						'start_date'    => '2026-03-25',
						'end_date'      => '2026-03-27',
						'location_name' => 'Main Hall',
						'status'        => 'published',
					);
				}

				public function all(): array {
					return array( $this->find( 10 ) );
				}
			},
			new class() extends \AIMS_Vendor_Event_Assignment_Repository {
				public function get_for_event( int $event_id ): array {
					return array(
						array( 'id' => 91, 'event_id' => 10, 'vendor_id' => 5 ),
						array( 'id' => 92, 'event_id' => 10, 'vendor_id' => 9 ),
					);
				}
			},
			new class() extends \AIMS_Event_Bucket_Assignment_Repository {
				public function get_active_for_event( int $event_id ): array {
					return array(
						array( 'id' => 400, 'event_id' => 10, 'physical_bucket_id' => 200, 'assignment_status' => self::STATUS_IN_TRANSIT, 'is_active' => 1 ),
						array( 'id' => 401, 'event_id' => 10, 'physical_bucket_id' => 201, 'assignment_status' => self::STATUS_IN_TRANSIT, 'is_active' => 1 ),
					);
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Repository {
				public function is_first_checkin( int $event_id, int $vendor_id, int $bucket_id = 0 ): bool {
					return true;
				}
			},
			new class() extends \AIMS_Vendor_Event_Checkin_Media_Repository {},
			new class() extends \AIMS_Event_Execution_Service {
				public function __construct() {}
			},
			new class() extends \AIMS_Public_Event_Projection_Service {
				public function __construct() {}

				public function get_public_event_updates( int $event_id, array $args = array() ): array {
					return array();
				}
			},
			null,
			new class() extends \AIMS_Physical_Bucket_Repository {
				public function find( int $bucket_id ): ?array {
					if ( 200 === $bucket_id ) {
						return array( 'id' => 200, 'vendor_id' => 5, 'bucket_label' => 'Vendor 5 Bucket', 'bucket_code' => 'V5-01' );
					}

					if ( 201 === $bucket_id ) {
						return array( 'id' => 201, 'vendor_id' => 9, 'bucket_label' => 'Vendor 9 Bucket', 'bucket_code' => 'V9-01' );
					}

					return null;
				}
			}
		);

		$model = $service->get_page_model( array( 'event_id' => 10 ) );

		$this->assertCount( 1, $model['bucket_options'] );
		$this->assertSame( 400, $model['bucket_options'][0]['assignment_id'] );
	}

	private function buildUploadFiles(): array {
		return array(
			'selfie_photo' => array(
				'name'     => 'selfie.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => 'C:\\temp\\selfie.jpg',
				'error'    => 0,
				'size'     => 100,
			),
			'booth_setup_photos' => array(
				'name'     => array( 'booth.jpg' ),
				'type'     => array( 'image/jpeg' ),
				'tmp_name' => array( 'C:\\temp\\booth.jpg' ),
				'error'    => array( 0 ),
				'size'     => array( 100 ),
			),
		);
	}

	private function readProperty( object $object, string $property ) {
		$reflection = new \ReflectionObject( $object );
		$prop = $reflection->getProperty( $property );
		$prop->setAccessible( true );

		return $prop->getValue( $object );
	}
}
