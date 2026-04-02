<?php

declare( strict_types=1 );

namespace AmesCore\Contracts;

interface BucketFifoStoreInterface {
	public function initialize(): void;

	/**
	 * @param array<string, mixed> $bucket
	 * @return array<string, mixed>
	 */
	public function upsertBucket( array $bucket ): array;

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function listBuckets( array $filters = array() ): array;

	/**
	 * @param array<string, mixed> $receipt
	 * @return array<string, mixed>
	 */
	public function receiveIntoBucket( array $receipt ): array;

	/**
	 * @param array<string, mixed> $movement
	 * @return array<string, mixed>
	 */
	public function moveBucketCustody( array $movement ): array;

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function fifoAvailability( array $filters = array() ): array;

	/**
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 */
	public function pickFifo( array $request ): array;
}
