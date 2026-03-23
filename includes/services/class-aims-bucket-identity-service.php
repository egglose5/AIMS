<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Identity_Service {
	private $physical_buckets;

	public function __construct( $physical_buckets = null ) {
		$this->physical_buckets = $physical_buckets;
	}

	public function normalize_bucket_reference( array $data ): array {
		$bucket_id   = ! empty( $data['bucket_id'] ) ? (int) $data['bucket_id'] : 0;
		$bucket_code = sanitize_text_field( $data['bucket_code'] ?? '' );

		if ( $bucket_id > 0 && '' === $bucket_code ) {
			$bucket_code = $this->resolve_bucket_code( $bucket_id );
		}

		if ( '' !== $bucket_code && $bucket_id <= 0 ) {
			$bucket_id = $this->resolve_bucket_id( $bucket_code );
		}

		return array(
			'bucket_id'   => $bucket_id,
			'bucket_code' => $bucket_code,
		);
	}

	public function resolve_bucket_id( string $bucket_code ): int {
		$bucket_code = sanitize_text_field( $bucket_code );

		if ( '' === $bucket_code || ! is_object( $this->physical_buckets ) || ! method_exists( $this->physical_buckets, 'find_by_code' ) ) {
			return 0;
		}

		$bucket = $this->physical_buckets->find_by_code( $bucket_code );

		return ! empty( $bucket['id'] ) ? (int) $bucket['id'] : 0;
	}

	public function resolve_bucket_code( int $bucket_id ): string {
		if ( $bucket_id <= 0 || ! is_object( $this->physical_buckets ) || ! method_exists( $this->physical_buckets, 'find' ) ) {
			return '';
		}

		$bucket = $this->physical_buckets->find( $bucket_id );

		return ! empty( $bucket['bucket_code'] ) ? sanitize_text_field( (string) $bucket['bucket_code'] ) : '';
	}
}
