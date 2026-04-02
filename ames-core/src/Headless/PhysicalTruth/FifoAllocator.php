<?php

declare( strict_types=1 );

namespace AmesCore\Headless\PhysicalTruth;

final class FifoAllocator {
	/**
	 * @param array<int, array<string, mixed>> $lots
	 * @return array<string, mixed>
	 */
	public function allocate( array $lots, float $requestedQuantity ): array {
		if ( $requestedQuantity <= 0 ) {
			throw new \InvalidArgumentException( 'FIFO allocation requires a positive quantity.' );
		}

		usort(
			$lots,
			static function ( array $left, array $right ): int {
				$leftReceived  = (string) ( $left['received_at'] ?? '' );
				$rightReceived = (string) ( $right['received_at'] ?? '' );

				if ( $leftReceived === $rightReceived ) {
					return (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
				}

				return $leftReceived <=> $rightReceived;
			}
		);

		$allocations = array();
		$remaining   = $requestedQuantity;

		foreach ( $lots as $lot ) {
			$available = isset( $lot['quantity_available'] ) ? (float) $lot['quantity_available'] : 0.0;
			if ( $available <= 0.0001 ) {
				continue;
			}

			$take = min( $available, $remaining );
			$allocations[] = array(
				'id'                 => (int) ( $lot['id'] ?? 0 ),
				'lot_uuid'           => (string) ( $lot['lot_uuid'] ?? '' ),
				'root_lot_uuid'      => (string) ( $lot['root_lot_uuid'] ?? '' ),
				'parent_lot_uuid'    => (string) ( $lot['parent_lot_uuid'] ?? '' ),
				'bucket_code'        => (string) ( $lot['bucket_code'] ?? '' ),
				'sku'                => (string) ( $lot['sku'] ?? '' ),
				'quantity_taken'     => $take,
				'quantity_remaining' => max( 0.0, $available - $take ),
				'unit_cost_cents'    => (int) ( $lot['unit_cost_cents'] ?? 0 ),
				'received_at'        => (string) ( $lot['received_at'] ?? '' ),
				'location_ref'       => (string) ( $lot['location_ref'] ?? '' ),
				'custody_ref'        => (string) ( $lot['custody_ref'] ?? '' ),
				'intake_reference'   => (string) ( $lot['intake_reference'] ?? '' ),
			);

			$remaining -= $take;
			if ( $remaining <= 0.0001 ) {
				$remaining = 0.0;
				break;
			}
		}

		return array(
			'requested_quantity' => $requestedQuantity,
			'allocated_quantity' => $requestedQuantity - $remaining,
			'remaining_quantity' => $remaining,
			'satisfied'          => $remaining <= 0.0001,
			'allocations'        => $allocations,
		);
	}
}
