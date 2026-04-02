<?php

declare( strict_types=1 );

namespace AmesCore\Inventory;

use AmesCore\Contracts\ClockInterface;
use AmesCore\Contracts\InventoryAuthorizationInterface;
use AmesCore\Contracts\MovementLifecycleInterface;
use AmesCore\Contracts\MovementPolicyInterface;
use AmesCore\Contracts\MovementRepositoryInterface;
use AmesCore\Contracts\PersonIdentityInterface;
use AmesCore\Contracts\PositionRepositoryInterface;
use AmesCore\Contracts\UuidGeneratorInterface;

final class MovementLedgerService {
	private MovementRepositoryInterface $movements;
	private ?PositionRepositoryInterface $positions;
	private ?InventoryAuthorizationInterface $authorization;
	private ?PersonIdentityInterface $personIdentity;
	private ?MovementLifecycleInterface $movementLifecycle;
	private ?MovementPolicyInterface $movementPolicy;
	private ClockInterface $clock;
	private UuidGeneratorInterface $uuidGenerator;

	public function __construct(
		MovementRepositoryInterface $movements,
		?PositionRepositoryInterface $positions,
		?InventoryAuthorizationInterface $authorization,
		?PersonIdentityInterface $personIdentity,
		?MovementLifecycleInterface $movementLifecycle,
		?MovementPolicyInterface $movementPolicy,
		ClockInterface $clock,
		UuidGeneratorInterface $uuidGenerator
	) {
		$this->movements         = $movements;
		$this->positions         = $positions;
		$this->authorization     = $authorization;
		$this->personIdentity    = $personIdentity;
		$this->movementLifecycle = $movementLifecycle;
		$this->movementPolicy    = $movementPolicy;
		$this->clock             = $clock;
		$this->uuidGenerator     = $uuidGenerator;
	}

	public function recordMovement( array $data ): array {
		$actorUserId   = (int) ( $data['applied_by'] ?? 0 );
		$bucketId      = ! empty( $data['bucket_id'] ) ? (int) $data['bucket_id'] : 0;
		$vendorId      = (int) ( $data['vendor_id'] ?? 0 );
		$productId     = (int) ( $data['product_id'] ?? 0 );
		$referenceType = $this->sanitizeKey( $data['reference_type'] ?? '' );
		$referenceId   = $this->sanitizeText( $data['reference_id'] ?? '' );
		$movementType  = $this->sanitizeKey( $data['movement_type'] ?? '' );
		$quantityDelta = (float) ( $data['quantity_delta'] ?? 0 );

		if ( $bucketId <= 0 || $vendorId <= 0 || $productId <= 0 || '' === $referenceType || '' === $referenceId || '' === $movementType || 0.0 === $quantityDelta ) {
			throw new MovementException( 'aims_invalid_bucket_movement', 'Bucket movement is missing required fields.' );
		}

		if ( $actorUserId > 0 && null !== $this->personIdentity && ! $this->personIdentity->isAimsPerson( $actorUserId ) ) {
			throw new MovementException( 'aims_person_required', 'Only AIMS users can apply inventory movements.' );
		}

		if ( $actorUserId > 0 && null !== $this->authorization && ! $this->authorization->canManageVendorInventory( $actorUserId, $vendorId ) ) {
			throw new MovementException( 'aims_inventory_scope_denied', 'This user is not authorized to manage inventory for the specified vendor.' );
		}

		if ( null !== $this->movementPolicy && ! $this->movementPolicy->isAllowedMovement( $movementType ) ) {
			throw new MovementException( 'aims_invalid_bucket_movement_type', 'Bucket movement type is not allowed by policy.' );
		}

		if ( null !== $this->movementPolicy && ! $this->movementPolicy->isAllowedReferenceForMovement( $movementType, $referenceType ) ) {
			throw new MovementException( 'aims_invalid_bucket_reference_type', 'Reference type is not allowed for this bucket movement type.' );
		}

		if ( $this->movements->hasReferenceApplication( $referenceType, $referenceId, $productId, $bucketId, $movementType ) ) {
			throw new MovementException( 'aims_duplicate_bucket_movement', 'This bucket movement has already been applied.' );
		}

		$data = $this->normalizeMovementFinancialMetadata( $data, $movementType );

		$data['movement_uuid'] = $this->sanitizeText( $data['movement_uuid'] ?? '' );
		if ( '' === $data['movement_uuid'] ) {
			$data['movement_uuid'] = $this->uuidGenerator->generate();
		}

		if ( null !== $this->movementLifecycle ) {
			$batch = $this->movementLifecycle->ensureHotBatch( $data );
			if ( is_array( $batch ) ) {
				$data['movement_batch_id'] = (int) ( $batch['id'] ?? 0 );
			}
		}

		$data['movement_lifecycle'] = $this->sanitizeKey( (string) ( $data['movement_lifecycle'] ?? 'hot' ) );
		$data['line_meta_json']     = array(
			'product_id'     => $productId,
			'sku'            => $this->sanitizeText( $data['sku'] ?? '' ),
			'bucket_id'      => $bucketId,
			'bucket_code'    => $this->sanitizeText( $data['bucket_code'] ?? '' ),
			'vendor_id'      => $vendorId,
			'event_id'       => (int) ( $data['event_id'] ?? 0 ),
			'source_bucket_id' => (int) ( $data['source_bucket_id'] ?? 0 ),
			'target_bucket_id' => (int) ( $data['target_bucket_id'] ?? 0 ),
			'source_storage_location_id' => (int) ( $data['source_storage_location_id'] ?? 0 ),
			'target_storage_location_id' => (int) ( $data['target_storage_location_id'] ?? 0 ),
			'quantity_delta' => number_format( $quantityDelta, 4, '.', '' ),
			'movement_type'  => $movementType,
			'recorded_at'    => $this->clock->now(),
		);

		$movementId = $this->movements->create( $data );
		$currentQty = $this->movements->getBalanceForBucketProduct( $bucketId, $vendorId, $productId );

		if ( $movementId > 0 && ! empty( $data['movement_batch_id'] ) && null !== $this->movementLifecycle ) {
			$this->movementLifecycle->captureHotLine( (int) $data['movement_batch_id'], $movementId, $data );
		}

		if ( null !== $this->positions ) {
			if ( $this->positions->supportsSynchronization() ) {
				$this->positions->synchronizeFromMovements( $bucketId, $vendorId, $productId );
			} else {
				$this->positions->upsertPosition(
					array(
						'bucket_id'               => $bucketId,
						'vendor_id'               => $vendorId,
						'product_id'              => $productId,
						'quantity'                => $currentQty,
						'position_status'         => $this->sanitizeKey( $data['position_status'] ?? 'active' ),
						'last_bucket_movement_id' => $movementId,
					)
				);
			}
		}

		return array(
			'movement_id'       => $movementId,
			'movement_batch_id' => (int) ( $data['movement_batch_id'] ?? 0 ),
			'current_quantity'  => $currentQty,
		);
	}

	private function normalizeMovementFinancialMetadata( array $data, string $movementType ): array {
		$metadata = array();

		if ( isset( $data['metadata_json'] ) ) {
			$metadata = $this->normalizeMetadataMap( $data['metadata_json'] );
		}

		if ( $this->isInboundMovementType( $movementType ) ) {
			$data['metadata_json'] = $this->filterInboundMetadata( $data, $metadata );
			return $data;
		}

		if ( $this->isSaleMovementType( $movementType ) ) {
			$data['metadata_json'] = $this->filterSaleMetadata( $data, $metadata );
			return $data;
		}

		$data['metadata_json'] = $this->stripFinancialMetadata( $metadata );

		return $data;
	}

	private function filterInboundMetadata( array $data, array $metadata ): array {
		$normalized = array();
		$numericKeys = array(
			'unit_cost',
			'unit_cost_cents',
			'extended_cost',
			'extended_cost_cents',
		);
		$textKeys = array(
			'currency',
			'cost_source',
			'supplier_reference',
			'receipt_reference',
		);

		foreach ( $numericKeys as $key ) {
			$value = $data[ $key ] ?? $metadata[ $key ] ?? null;
			if ( is_numeric( $value ) ) {
				$normalized[ $key ] = false !== strpos( $key, '_cents' )
					? (int) round( (float) $value )
					: round( (float) $value, 4 );
			}
		}

		if ( ! isset( $normalized['extended_cost'] ) && isset( $normalized['unit_cost'] ) && isset( $data['quantity_delta'] ) && is_numeric( $data['quantity_delta'] ) ) {
			$normalized['extended_cost'] = round( abs( (float) $data['quantity_delta'] ) * (float) $normalized['unit_cost'], 4 );
		}

		if ( ! isset( $normalized['extended_cost_cents'] ) && isset( $normalized['extended_cost'] ) ) {
			$normalized['extended_cost_cents'] = (int) round( (float) $normalized['extended_cost'] * 100 );
		}

		if ( ! isset( $normalized['unit_cost_cents'] ) && isset( $normalized['unit_cost'] ) ) {
			$normalized['unit_cost_cents'] = (int) round( (float) $normalized['unit_cost'] * 100 );
		}

		foreach ( $textKeys as $key ) {
			$value = $data[ $key ] ?? $metadata[ $key ] ?? null;
			$value = $this->sanitizeText( $value );
			if ( '' !== $value ) {
				$normalized[ $key ] = $value;
			}
		}

		if ( empty( $normalized['unit_cost'] ) && empty( $normalized['unit_cost_cents'] ) && empty( $normalized['extended_cost'] ) && empty( $normalized['extended_cost_cents'] ) ) {
			throw new MovementException( 'aims_inbound_cost_required', 'Inbound inventory must include cost values.' );
		}

		return $normalized;
	}

	private function filterSaleMetadata( array $data, array $metadata ): array {
		$normalized = array();
		$amountPaid = $data['amount_paid'] ?? $metadata['amount_paid'] ?? $data['paid_amount'] ?? $metadata['paid_amount'] ?? $data['net_amount'] ?? $metadata['net_amount'] ?? $data['gross_amount'] ?? $metadata['gross_amount'] ?? null;
		$amountPaidCents = $data['amount_paid_cents'] ?? $metadata['amount_paid_cents'] ?? $data['paid_amount_cents'] ?? $metadata['paid_amount_cents'] ?? null;
		$taxAmount = $data['tax_amount'] ?? $metadata['tax_amount'] ?? null;
		$taxAmountCents = $data['tax_amount_cents'] ?? $metadata['tax_amount_cents'] ?? null;

		if ( is_numeric( $amountPaid ) ) {
			$normalized['amount_paid'] = round( (float) $amountPaid, 2 );
		}

		if ( is_numeric( $amountPaidCents ) ) {
			$normalized['amount_paid_cents'] = (int) round( (float) $amountPaidCents );
		} elseif ( isset( $normalized['amount_paid'] ) ) {
			$normalized['amount_paid_cents'] = (int) round( $normalized['amount_paid'] * 100 );
		}

		if ( is_numeric( $taxAmount ) ) {
			$normalized['tax_amount'] = round( (float) $taxAmount, 2 );
		}

		if ( is_numeric( $taxAmountCents ) ) {
			$normalized['tax_amount_cents'] = (int) round( (float) $taxAmountCents );
		} elseif ( isset( $normalized['tax_amount'] ) ) {
			$normalized['tax_amount_cents'] = (int) round( $normalized['tax_amount'] * 100 );
		}

		foreach ( array( 'currency', 'square_order_id', 'square_line_item_uid', 'square_payment_id' ) as $key ) {
			$value = $this->sanitizeText( $data[ $key ] ?? $metadata[ $key ] ?? '' );
			if ( '' !== $value ) {
				$normalized[ $key ] = $value;
			}
		}

		if ( empty( $normalized['amount_paid'] ) && empty( $normalized['amount_paid_cents'] ) ) {
			throw new MovementException( 'aims_sale_amount_required', 'Sale-side outbound inventory must include the amount paid.' );
		}

		return $normalized;
	}

	private function stripFinancialMetadata( array $metadata ): array {
		$financialKeys = array(
			'amount_paid',
			'amount_paid_cents',
			'paid_amount',
			'paid_amount_cents',
			'gross_amount',
			'gross_amount_cents',
			'net_amount',
			'net_amount_cents',
			'tax_amount',
			'tax_amount_cents',
			'discount_amount',
			'discount_amount_cents',
			'tip_amount',
			'tip_amount_cents',
			'unit_cost',
			'unit_cost_cents',
			'extended_cost',
			'extended_cost_cents',
			'currency',
			'square_sale_id',
			'square_order_id',
			'square_line_item_uid',
			'square_payment_id',
		);

		foreach ( $financialKeys as $key ) {
			unset( $metadata[ $key ] );
		}

		return $metadata;
	}

	private function normalizeMetadataMap( $metadata ): array {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $metadata as $key => $value ) {
			$cleanKey = $this->sanitizeKey( (string) $key );
			if ( '' === $cleanKey ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$normalized[ $cleanKey ] = is_string( $value ) ? $this->sanitizeText( $value ) : $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$normalized[ $cleanKey ] = $this->normalizeMetadataMap( $value );
			}
		}

		return $normalized;
	}

	private function isInboundMovementType( string $movementType ): bool {
		return 'origin_inbound' === $movementType || 'stock_in' === $movementType;
	}

	private function isSaleMovementType( string $movementType ): bool {
		return 'show_consumption' === $movementType || 'square_sale' === $movementType || 'stock_out_sale' === $movementType;
	}

	private function sanitizeKey( $value ): string {
		if ( is_array( $value ) ) {
			return '';
		}

		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', trim( (string) $value ) ) );
	}

	private function sanitizeText( $value ): string {
		if ( is_array( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}
}
