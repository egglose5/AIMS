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
			'bucket_id'      => $bucketId,
			'bucket_code'    => $this->sanitizeText( $data['bucket_code'] ?? '' ),
			'vendor_id'      => $vendorId,
			'event_id'       => (int) ( $data['event_id'] ?? 0 ),
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
