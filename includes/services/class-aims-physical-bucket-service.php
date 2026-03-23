<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Physical_Bucket_Service {
	private $buckets;

	public function __construct( $buckets ) {
		$this->buckets = $buckets;
	}

	public function create_bucket( array $data ): int {
		return $this->save_bucket( $data );
	}

	public function update_bucket( int $bucket_id, array $data ): int {
		return $this->save_bucket( $data, $bucket_id );
	}

	public function move_bucket_to_location( int $bucket_id, int $location_id ): bool {
		if ( $bucket_id <= 0 || ! method_exists( $this->buckets, 'update_current_location' ) ) {
			return false;
		}

		return (bool) $this->buckets->update_current_location( $bucket_id, $location_id );
	}

	public function mark_bucket_status( int $bucket_id, string $status ): bool {
		if ( $bucket_id <= 0 || ! method_exists( $this->buckets, 'update_status' ) ) {
			return false;
		}

		return (bool) $this->buckets->update_status( $bucket_id, sanitize_key( $status ) );
	}

	public function find_bucket_identity( array $data ): array {
		$bucket_id   = ! empty( $data['bucket_id'] ) ? (int) $data['bucket_id'] : 0;
		$bucket_code = sanitize_text_field( $data['bucket_code'] ?? '' );

		if ( $bucket_id > 0 && method_exists( $this->buckets, 'find' ) ) {
			$bucket = $this->buckets->find( $bucket_id );

			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		if ( '' !== $bucket_code && method_exists( $this->buckets, 'find_by_code' ) ) {
			$bucket = $this->buckets->find_by_code( $bucket_code );

			if ( is_array( $bucket ) ) {
				return $bucket;
			}
		}

		return array();
	}

	private function save_bucket( array $data, int $bucket_id = 0 ): int {
		if ( ! method_exists( $this->buckets, 'save' ) ) {
			return 0;
		}

		$record = array(
			'bucket_code'                => sanitize_text_field( $data['bucket_code'] ?? '' ),
			'bucket_label'               => sanitize_text_field( $data['bucket_label'] ?? $data['bucket_code'] ?? '' ),
			'bucket_type'                => sanitize_key( $data['bucket_type'] ?? 'standard' ),
			'status'                     => sanitize_key( $data['status'] ?? 'available' ),
			'current_storage_location_id' => (int) ( $data['current_storage_location_id'] ?? 0 ),
			'home_storage_location_id'   => (int) ( $data['home_storage_location_id'] ?? 0 ),
			'vendor_id'                  => (int) ( $data['vendor_id'] ?? 0 ),
			'barcode_value'              => sanitize_text_field( $data['barcode_value'] ?? '' ),
			'tare_weight'                => (float) ( $data['tare_weight'] ?? 0 ),
			'notes'                      => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
		);

		return (int) $this->buckets->save( $record, $bucket_id );
	}
}
