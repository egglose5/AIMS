<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Movement_Archive_Manifest_Repository;
use AIMS_Movement_Batch_Repository;
use AIMS_Movement_Lifecycle_Service;

final class MovementLifecycleServiceTest extends \AIMS\Tests\TestCase {
	public function testPrepareArchiveManifestBuildsLocalArchivePayload(): void {
		$batches = new class() extends AIMS_Movement_Batch_Repository {
			public array $bound = array();

			public function find( int $batch_id ): ?array {
				return array(
					'id'                 => $batch_id,
					'batch_uuid'         => 'batch-123',
					'batch_type'         => 'bucket_line_meta',
					'reference_type'     => 'inventory_transfer',
					'reference_id'       => 'transfer-5-2',
					'movement_type'      => 'transfer',
					'line_meta_json'     => wp_json_encode(
						array(
							array(
								'movement_id'    => 77,
								'product_id'     => 501,
								'quantity_delta' => '-4.0000',
							),
						)
					),
				);
			}

			public function bind_archive_manifest( int $batch_id, int $archive_manifest_id, string $lifecycle_status = 'archived' ): bool {
				$this->bound[] = compact( 'batch_id', 'archive_manifest_id', 'lifecycle_status' );
				return true;
			}
		};

		$archives = new class() extends AIMS_Movement_Archive_Manifest_Repository {
			public array $created = array();

			public function create( array $data ): int {
				$this->created[] = $data;
				return 901;
			}

			public function find_for_batch( int $movement_batch_id ): ?array {
				return array(
					'id' => 901,
					'movement_batch_id' => $movement_batch_id,
					'archive_status' => 'prepared',
				);
			}
		};

		$service = new AIMS_Movement_Lifecycle_Service( $batches, $archives );
		$result  = $service->prepare_archive_manifest( 42 );

		$this->assertSame( 901, $result['id'] );
		$this->assertCount( 1, $archives->created );
		$this->assertSame( 'local_wp', $archives->created[0]['storage_backend'] );
		$this->assertSame( 'gzip', $archives->created[0]['compression_codec'] );
		$this->assertSame( 1, $archives->created[0]['line_count'] );
		$this->assertSame( 42, $batches->bound[0]['batch_id'] );
		$this->assertSame( 'archivable', $batches->bound[0]['lifecycle_status'] );
	}
}
