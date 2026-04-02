<?php

declare( strict_types=1 );

namespace AmesCore\Inventory;

use AmesCore\Contracts\BucketFifoStoreInterface;

final class BucketFifoService {
	private BucketFifoStoreInterface $store;

	public function __construct( BucketFifoStoreInterface $store ) {
		$this->store = $store;
	}

	public function initialize(): void {
		$this->store->initialize();
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function registerBucket( array $input ): array {
		$bucketCode = $this->stringValue( $input['bucket_code'] ?? '' );

		if ( '' === $bucketCode ) {
			throw new \InvalidArgumentException( 'Bucket registration requires bucket_code.' );
		}

		return $this->store->upsertBucket(
			array(
				'bucket_code'       => $bucketCode,
				'bucket_label'      => $this->stringValue( $input['bucket_label'] ?? $bucketCode ),
				'bucket_type'       => $this->stringValue( $input['bucket_type'] ?? 'physical' ),
				'status'            => $this->stringValue( $input['status'] ?? 'active' ),
				'show_id'           => $this->stringValue( $input['show_id'] ?? '' ),
				'current_location'  => $this->stringValue( $input['current_location'] ?? '' ),
				'current_custody'   => $this->stringValue( $input['current_custody'] ?? '' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function buckets( array $filters = array() ): array {
		return $this->store->listBuckets(
			array(
				'bucket_code'      => $this->stringValue( $filters['bucket_code'] ?? '' ),
				'current_location' => $this->stringValue( $filters['current_location'] ?? '' ),
				'current_custody'  => $this->stringValue( $filters['current_custody'] ?? '' ),
				'show_id'          => $this->stringValue( $filters['show_id'] ?? '' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function receive( array $input ): array {
		$bucketCode = $this->stringValue( $input['bucket_code'] ?? '' );
		$sku        = $this->stringValue( $input['sku'] ?? '' );
		$quantity   = $this->floatValue( $input['quantity'] ?? 0 );
		$unitCost   = $input['unit_cost'] ?? null;
		$unitCostCents = $input['unit_cost_cents'] ?? null;

		if ( '' === $bucketCode || '' === $sku ) {
			throw new \InvalidArgumentException( 'FIFO receipt requires bucket_code and sku.' );
		}

		if ( $quantity <= 0 ) {
			throw new \InvalidArgumentException( 'FIFO receipt quantity must be greater than zero.' );
		}

		if ( ! is_numeric( $unitCost ) && ! is_numeric( $unitCostCents ) ) {
			throw new \InvalidArgumentException( 'FIFO receipt requires unit_cost or unit_cost_cents.' );
		}

		$normalizedUnitCost = is_numeric( $unitCost )
			? round( (float) $unitCost, 4 )
			: round( ( (float) $unitCostCents ) / 100, 4 );

		$normalizedUnitCostCents = is_numeric( $unitCostCents )
			? (int) round( (float) $unitCostCents )
			: (int) round( $normalizedUnitCost * 100 );

		return $this->store->receiveIntoBucket(
			array(
				'bucket_code'       => $bucketCode,
				'sku'               => $sku,
				'show_id'           => $this->stringValue( $input['show_id'] ?? '' ),
				'quantity'          => $quantity,
				'unit_cost'         => $normalizedUnitCost,
				'unit_cost_cents'   => $normalizedUnitCostCents,
				'receipt_reference' => $this->stringValue( $input['receipt_reference'] ?? '' ),
				'source_reference'  => $this->stringValue( $input['source_reference'] ?? '' ),
				'received_at'       => $this->stringValue( $input['received_at'] ?? gmdate( 'c' ) ),
				'current_location'  => $this->stringValue( $input['current_location'] ?? '' ),
				'current_custody'   => $this->stringValue( $input['current_custody'] ?? '' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function moveCustody( array $input ): array {
		$bucketCode = $this->stringValue( $input['bucket_code'] ?? '' );
		$toLocation = $this->stringValue( $input['to_location'] ?? '' );
		$toCustody  = $this->stringValue( $input['to_custody'] ?? '' );

		if ( '' === $bucketCode ) {
			throw new \InvalidArgumentException( 'Custody move requires bucket_code.' );
		}

		if ( '' === $toLocation && '' === $toCustody ) {
			throw new \InvalidArgumentException( 'Custody move requires to_location or to_custody.' );
		}

		return $this->store->moveBucketCustody(
			array(
				'bucket_code'     => $bucketCode,
				'from_location'   => $this->stringValue( $input['from_location'] ?? '' ),
				'to_location'     => $toLocation,
				'from_custody'    => $this->stringValue( $input['from_custody'] ?? '' ),
				'to_custody'      => $toCustody,
				'reference_type'  => $this->stringValue( $input['reference_type'] ?? 'custody_transfer' ),
				'reference_id'    => $this->stringValue( $input['reference_id'] ?? '' ),
				'movement_type'   => $this->stringValue( $input['movement_type'] ?? 'custody_transfer' ),
				'note'            => $this->stringValue( $input['note'] ?? '' ),
				'occurred_at'     => $this->stringValue( $input['occurred_at'] ?? gmdate( 'c' ) ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function availability( array $filters = array() ): array {
		$sku = $this->stringValue( $filters['sku'] ?? '' );

		if ( '' === $sku ) {
			throw new \InvalidArgumentException( 'FIFO availability requires sku.' );
		}

		return $this->store->fifoAvailability(
			array(
				'sku'     => $sku,
				'show_id' => $this->stringValue( $filters['show_id'] ?? '' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function pick( array $input ): array {
		$sku      = $this->stringValue( $input['sku'] ?? '' );
		$quantity = $this->floatValue( $input['quantity'] ?? 0 );

		if ( '' === $sku ) {
			throw new \InvalidArgumentException( 'FIFO pick requires sku.' );
		}

		if ( $quantity <= 0 ) {
			throw new \InvalidArgumentException( 'FIFO pick quantity must be greater than zero.' );
		}

		return $this->store->pickFifo(
			array(
				'sku'               => $sku,
				'show_id'           => $this->stringValue( $input['show_id'] ?? '' ),
				'quantity'          => $quantity,
				'request_reference' => $this->stringValue( $input['request_reference'] ?? '' ),
				'movement_type'     => $this->stringValue( $input['movement_type'] ?? 'fifo_pick' ),
				'amount_paid'       => is_numeric( $input['amount_paid'] ?? null ) ? round( (float) $input['amount_paid'], 2 ) : null,
				'amount_paid_cents' => is_numeric( $input['amount_paid_cents'] ?? null ) ? (int) round( (float) $input['amount_paid_cents'] ) : null,
				'tax_amount'        => is_numeric( $input['tax_amount'] ?? null ) ? round( (float) $input['tax_amount'], 2 ) : null,
				'tax_amount_cents'  => is_numeric( $input['tax_amount_cents'] ?? null ) ? (int) round( (float) $input['tax_amount_cents'] ) : null,
			)
		);
	}

	private function stringValue( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	private function floatValue( mixed $value ): float {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}
}
