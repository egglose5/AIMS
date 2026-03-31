<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Service {
	private $bucket_movement_service;
	private $bucket_identity;

	public function __construct(
		AIMS_Bucket_Movement_Service $bucket_movement_service,
		AIMS_Bucket_Identity_Service $bucket_identity = null
	) {
		$this->bucket_movement_service = $bucket_movement_service;
		$this->bucket_identity         = $bucket_identity;
	}

	public function apply_movement( array $data ) {
		$bucket_ref = $this->normalize_bucket_reference( $data );
		$data       = array_merge( $data, $bucket_ref );

		return $this->bucket_movement_service->record_movement( $data );
	}

	private function normalize_bucket_reference( array $data ): array {
		$bucket_ref = array(
			'bucket_id'   => ! empty( $data['bucket_id'] ) ? (int) $data['bucket_id'] : 0,
			'bucket_code' => sanitize_text_field( $data['bucket_code'] ?? '' ),
		);

		if ( is_object( $this->bucket_identity ) ) {
			$bucket_ref = $this->bucket_identity->normalize_bucket_reference( $bucket_ref );
		}

		return $bucket_ref;
	}
}
