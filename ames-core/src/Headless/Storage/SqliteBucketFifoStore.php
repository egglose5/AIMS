<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Storage;

use AmesCore\Contracts\BucketFifoStoreInterface;
use AmesCore\Schema\BucketFifoSchema;
use PDO;

final class SqliteBucketFifoStore implements BucketFifoStoreInterface {
	private string $sqlitePath;
	private ?PDO $connection = null;

	public function __construct( string $sqlitePath ) {
		$this->sqlitePath = $sqlitePath;
	}

	public function initialize(): void {
		$pdo = $this->connection();

		foreach ( BucketFifoSchema::migrationSql() as $statement ) {
			$pdo->exec( $statement );
		}
	}

	public function upsertBucket( array $bucket ): array {
		$now = $this->stringValue( $bucket['updated_at'] ?? gmdate( 'c' ) );
		$row = array(
			'bucket_code'       => $this->stringValue( $bucket['bucket_code'] ?? '' ),
			'bucket_label'      => $this->stringValue( $bucket['bucket_label'] ?? '' ),
			'bucket_type'       => $this->stringValue( $bucket['bucket_type'] ?? 'physical' ),
			'status'            => $this->stringValue( $bucket['status'] ?? 'active' ),
			'show_id'           => $this->stringValue( $bucket['show_id'] ?? '' ),
			'current_location'  => $this->stringValue( $bucket['current_location'] ?? '' ),
			'current_custody'   => $this->stringValue( $bucket['current_custody'] ?? '' ),
			'created_at'        => $this->stringValue( $bucket['created_at'] ?? $now ),
			'updated_at'        => $now,
		);

		$statement = $this->connection()->prepare(
			'INSERT INTO "' . BucketFifoSchema::BUCKET_TABLE . '" (
				"bucket_code","bucket_label","bucket_type","status","show_id","current_location","current_custody","created_at","updated_at"
			) VALUES (
				:bucket_code,:bucket_label,:bucket_type,:status,:show_id,:current_location,:current_custody,:created_at,:updated_at
			)
			ON CONFLICT("bucket_code")
			DO UPDATE SET
				"bucket_label" = excluded."bucket_label",
				"bucket_type" = excluded."bucket_type",
				"status" = excluded."status",
				"show_id" = excluded."show_id",
				"current_location" = excluded."current_location",
				"current_custody" = excluded."current_custody",
				"updated_at" = excluded."updated_at"'
		);

		foreach ( $row as $column => $value ) {
			$statement->bindValue( ':' . $column, $value );
		}

		$statement->execute();

		return $this->findBucket( $row['bucket_code'] );
	}

	public function listBuckets( array $filters = array() ): array {
		$where = array();
		$args  = array();

		foreach ( array( 'bucket_code', 'current_location', 'current_custody', 'show_id' ) as $column ) {
			$value = $this->stringValue( $filters[ $column ] ?? '' );
			if ( '' === $value ) {
				continue;
			}

			$where[] = '"' . $column . '" = :' . $column;
			$args[ $column ] = $value;
		}

		$sql = 'SELECT b.*, COALESCE(SUM(l."remaining_quantity"), 0) AS "on_hand_quantity"
			FROM "' . BucketFifoSchema::BUCKET_TABLE . '" b
			LEFT JOIN "' . BucketFifoSchema::LOT_TABLE . '" l ON l."bucket_code" = b."bucket_code"';
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' GROUP BY b."bucket_code" ORDER BY b."bucket_code" ASC';

		$statement = $this->connection()->prepare( $sql );
		foreach ( $args as $column => $value ) {
			$statement->bindValue( ':' . $column, $value );
		}

		$statement->execute();

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();

		return array_map( fn( array $row ): array => $this->normalizeBucketRow( $row ), $rows );
	}

	public function receiveIntoBucket( array $receipt ): array {
		$bucketCode = $this->stringValue( $receipt['bucket_code'] ?? '' );
		$sku        = $this->stringValue( $receipt['sku'] ?? '' );
		$receivedAt = $this->stringValue( $receipt['received_at'] ?? gmdate( 'c' ) );
		$quantity   = $this->floatValue( $receipt['quantity'] ?? 0 );
		$unitCost   = $this->floatValue( $receipt['unit_cost'] ?? 0 );
		$unitCostCents = $this->intValue( $receipt['unit_cost_cents'] ?? 0 );
		$lotUuid    = $this->uuid();

		$this->upsertBucket(
			array(
				'bucket_code'      => $bucketCode,
				'bucket_label'     => $bucketCode,
				'current_location' => $this->stringValue( $receipt['current_location'] ?? '' ),
				'current_custody'  => $this->stringValue( $receipt['current_custody'] ?? '' ),
				'show_id'          => $this->stringValue( $receipt['show_id'] ?? '' ),
			)
		);

		$statement = $this->connection()->prepare(
			'INSERT INTO "' . BucketFifoSchema::LOT_TABLE . '" (
				"lot_uuid","bucket_code","sku","show_id","receipt_reference","source_reference","original_quantity","remaining_quantity","unit_cost","unit_cost_cents","received_at","status","created_at","updated_at"
			) VALUES (
				:lot_uuid,:bucket_code,:sku,:show_id,:receipt_reference,:source_reference,:original_quantity,:remaining_quantity,:unit_cost,:unit_cost_cents,:received_at,:status,:created_at,:updated_at
			)'
		);

		$row = array(
			'lot_uuid'          => $lotUuid,
			'bucket_code'       => $bucketCode,
			'sku'               => $sku,
			'show_id'           => $this->stringValue( $receipt['show_id'] ?? '' ),
			'receipt_reference' => $this->stringValue( $receipt['receipt_reference'] ?? '' ),
			'source_reference'  => $this->stringValue( $receipt['source_reference'] ?? '' ),
			'original_quantity' => $quantity,
			'remaining_quantity'=> $quantity,
			'unit_cost'         => $unitCost,
			'unit_cost_cents'   => $unitCostCents,
			'received_at'       => $receivedAt,
			'status'            => 'active',
			'created_at'        => $receivedAt,
			'updated_at'        => $receivedAt,
		);

		foreach ( $row as $column => $value ) {
			$statement->bindValue( ':' . $column, $value );
		}

		$statement->execute();

		return $this->findLot( $lotUuid );
	}

	public function moveBucketCustody( array $movement ): array {
		$bucketCode  = $this->stringValue( $movement['bucket_code'] ?? '' );
		$occurredAt  = $this->stringValue( $movement['occurred_at'] ?? gmdate( 'c' ) );
		$movementUuid = $this->uuid();
		$current     = $this->findBucket( $bucketCode );

		if ( empty( $current ) ) {
			throw new \InvalidArgumentException( 'Unknown bucket_code for custody move.' );
		}

		$fromLocation = $this->stringValue( $movement['from_location'] ?? ( $current['current_location'] ?? '' ) );
		$fromCustody  = $this->stringValue( $movement['from_custody'] ?? ( $current['current_custody'] ?? '' ) );
		$toLocation   = $this->stringValue( $movement['to_location'] ?? '' );
		$toCustody    = $this->stringValue( $movement['to_custody'] ?? '' );

		$pdo = $this->connection();
		$pdo->beginTransaction();

		try {
			$update = $pdo->prepare(
				'UPDATE "' . BucketFifoSchema::BUCKET_TABLE . '"
				SET "current_location" = :current_location, "current_custody" = :current_custody, "updated_at" = :updated_at
				WHERE "bucket_code" = :bucket_code'
			);
			$update->bindValue( ':current_location', '' !== $toLocation ? $toLocation : $fromLocation );
			$update->bindValue( ':current_custody', '' !== $toCustody ? $toCustody : $fromCustody );
			$update->bindValue( ':updated_at', $occurredAt );
			$update->bindValue( ':bucket_code', $bucketCode );
			$update->execute();

			$insert = $pdo->prepare(
				'INSERT INTO "' . BucketFifoSchema::CUSTODY_TABLE . '" (
					"movement_uuid","bucket_code","from_location","to_location","from_custody","to_custody","reference_type","reference_id","movement_type","note","occurred_at","created_at"
				) VALUES (
					:movement_uuid,:bucket_code,:from_location,:to_location,:from_custody,:to_custody,:reference_type,:reference_id,:movement_type,:note,:occurred_at,:created_at
				)'
			);

			$row = array(
				'movement_uuid' => $movementUuid,
				'bucket_code'   => $bucketCode,
				'from_location' => $fromLocation,
				'to_location'   => $toLocation,
				'from_custody'  => $fromCustody,
				'to_custody'    => $toCustody,
				'reference_type'=> $this->stringValue( $movement['reference_type'] ?? '' ),
				'reference_id'  => $this->stringValue( $movement['reference_id'] ?? '' ),
				'movement_type' => $this->stringValue( $movement['movement_type'] ?? 'custody_transfer' ),
				'note'          => $this->stringValue( $movement['note'] ?? '' ),
				'occurred_at'   => $occurredAt,
				'created_at'    => $occurredAt,
			);

			foreach ( $row as $column => $value ) {
				$insert->bindValue( ':' . $column, $value );
			}

			$insert->execute();
			$pdo->commit();
		} catch ( \Throwable $exception ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			throw $exception;
		}

		return array(
			'movement_uuid' => $movementUuid,
			'bucket'        => $this->findBucket( $bucketCode ),
			'occurred_at'   => $occurredAt,
		);
	}

	public function fifoAvailability( array $filters = array() ): array {
		$sku = $this->stringValue( $filters['sku'] ?? '' );
		$showId = $this->stringValue( $filters['show_id'] ?? '' );

		$sql = 'SELECT l.*, b."current_location", b."current_custody"
			FROM "' . BucketFifoSchema::LOT_TABLE . '" l
			INNER JOIN "' . BucketFifoSchema::BUCKET_TABLE . '" b ON b."bucket_code" = l."bucket_code"
			WHERE l."sku" = :sku AND l."remaining_quantity" > 0';
		if ( '' !== $showId ) {
			$sql .= ' AND l."show_id" = :show_id';
		}
		$sql .= ' ORDER BY l."received_at" ASC, l."id" ASC';

		$statement = $this->connection()->prepare( $sql );
		$statement->bindValue( ':sku', $sku );
		if ( '' !== $showId ) {
			$statement->bindValue( ':show_id', $showId );
		}
		$statement->execute();

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();

		return array_map(
			function ( array $row ): array {
				return array(
					'lot_uuid'           => (string) $row['lot_uuid'],
					'bucket_code'        => (string) $row['bucket_code'],
					'sku'                => (string) $row['sku'],
					'show_id'            => (string) $row['show_id'],
					'remaining_quantity' => (float) $row['remaining_quantity'],
					'unit_cost'          => (float) $row['unit_cost'],
					'unit_cost_cents'    => (int) $row['unit_cost_cents'],
					'received_at'        => (string) $row['received_at'],
					'current_location'   => (string) $row['current_location'],
					'current_custody'    => (string) $row['current_custody'],
				);
			},
			$rows
		);
	}

	public function pickFifo( array $request ): array {
		$sku = $this->stringValue( $request['sku'] ?? '' );
		$showId = $this->stringValue( $request['show_id'] ?? '' );
		$requestedQuantity = $this->floatValue( $request['quantity'] ?? 0 );
		$amountPaid = $this->floatValue( $request['amount_paid'] ?? 0 );
		$amountPaidCents = $this->intValue( $request['amount_paid_cents'] ?? ( $amountPaid > 0 ? (int) round( $amountPaid * 100 ) : 0 ) );
		$taxAmount = $this->floatValue( $request['tax_amount'] ?? 0 );
		$taxAmountCents = $this->intValue( $request['tax_amount_cents'] ?? ( $taxAmount > 0 ? (int) round( $taxAmount * 100 ) : 0 ) );
		$availableLots = $this->fifoAvailability( array( 'sku' => $sku, 'show_id' => $showId ) );
		$totalAvailable = array_reduce(
			$availableLots,
			static fn( float $carry, array $lot ): float => $carry + (float) ( $lot['remaining_quantity'] ?? 0 ),
			0.0
		);

		if ( $totalAvailable + 0.0001 < $requestedQuantity ) {
			throw new \RuntimeException( 'FIFO pick exceeds available quantity for SKU ' . $sku . '.' );
		}

		$remaining = $requestedQuantity;
		$allocations = array();
		$pdo = $this->connection();
		$pdo->beginTransaction();

		try {
			$amountPaidSplits = $this->prorateCents( $amountPaidCents, $availableLots, $requestedQuantity );
			$taxSplits = $this->prorateCents( $taxAmountCents, $availableLots, $requestedQuantity );
			$allocationIndex = 0;

			foreach ( $availableLots as $lot ) {
				if ( $remaining <= 0 ) {
					break;
				}

				$allocatable = min( $remaining, (float) $lot['remaining_quantity'] );
				if ( $allocatable <= 0 ) {
					continue;
				}

				$newRemaining = (float) $lot['remaining_quantity'] - $allocatable;

				$update = $pdo->prepare(
					'UPDATE "' . BucketFifoSchema::LOT_TABLE . '"
					SET "remaining_quantity" = :remaining_quantity, "status" = :status, "updated_at" = :updated_at
					WHERE "lot_uuid" = :lot_uuid'
				);
				$update->bindValue( ':remaining_quantity', $newRemaining );
				$update->bindValue( ':status', $newRemaining > 0 ? 'active' : 'depleted' );
				$update->bindValue( ':updated_at', gmdate( 'c' ) );
				$update->bindValue( ':lot_uuid', (string) $lot['lot_uuid'] );
				$update->execute();

				$allocationUuid = $this->uuid();
				$insert = $pdo->prepare(
					'INSERT INTO "' . BucketFifoSchema::ALLOCATION_TABLE . '" (
						"allocation_uuid","request_reference","sku","show_id","bucket_code","lot_uuid","quantity","movement_type","amount_paid","amount_paid_cents","tax_amount","tax_amount_cents","created_at"
					) VALUES (
						:allocation_uuid,:request_reference,:sku,:show_id,:bucket_code,:lot_uuid,:quantity,:movement_type,:amount_paid,:amount_paid_cents,:tax_amount,:tax_amount_cents,:created_at
					)'
				);

				$allocatedAmountPaidCents = $amountPaidSplits[ $allocationIndex ] ?? 0;
				$allocatedTaxCents = $taxSplits[ $allocationIndex ] ?? 0;

				$row = array(
					'allocation_uuid'  => $allocationUuid,
					'request_reference'=> $this->stringValue( $request['request_reference'] ?? '' ),
					'sku'              => $sku,
					'show_id'          => $showId,
					'bucket_code'      => (string) $lot['bucket_code'],
					'lot_uuid'         => (string) $lot['lot_uuid'],
					'quantity'         => $allocatable,
					'movement_type'    => $this->stringValue( $request['movement_type'] ?? 'fifo_pick' ),
					'amount_paid'      => $allocatedAmountPaidCents / 100,
					'amount_paid_cents'=> $allocatedAmountPaidCents,
					'tax_amount'       => $allocatedTaxCents / 100,
					'tax_amount_cents' => $allocatedTaxCents,
					'created_at'       => gmdate( 'c' ),
				);

				foreach ( $row as $column => $value ) {
					$insert->bindValue( ':' . $column, $value );
				}

				$insert->execute();

				$allocations[] = array(
					'allocation_uuid'   => $allocationUuid,
					'bucket_code'       => (string) $lot['bucket_code'],
					'lot_uuid'          => (string) $lot['lot_uuid'],
					'quantity'          => $allocatable,
					'unit_cost'         => (float) $lot['unit_cost'],
					'unit_cost_cents'   => (int) $lot['unit_cost_cents'],
					'amount_paid'       => $allocatedAmountPaidCents / 100,
					'amount_paid_cents' => $allocatedAmountPaidCents,
					'tax_amount'        => $allocatedTaxCents / 100,
					'tax_amount_cents'  => $allocatedTaxCents,
					'received_at'       => (string) $lot['received_at'],
					'current_location'  => (string) $lot['current_location'],
					'current_custody'   => (string) $lot['current_custody'],
				);

				$remaining -= $allocatable;
				$allocationIndex++;
			}

			$pdo->commit();
		} catch ( \Throwable $exception ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			throw $exception;
		}

		return array(
			'sku'                => $sku,
			'show_id'            => $showId,
			'requested_quantity' => $requestedQuantity,
			'allocated_quantity' => $requestedQuantity - $remaining,
			'amount_paid'        => $amountPaid,
			'amount_paid_cents'  => $amountPaidCents,
			'tax_amount'         => $taxAmount,
			'tax_amount_cents'   => $taxAmountCents,
			'allocations'        => $allocations,
			'remaining_available'=> max( 0.0, $totalAvailable - $requestedQuantity ),
		);
	}

	private function findBucket( string $bucketCode ): array {
		$statement = $this->connection()->prepare( 'SELECT * FROM "' . BucketFifoSchema::BUCKET_TABLE . '" WHERE "bucket_code" = :bucket_code LIMIT 1' );
		$statement->bindValue( ':bucket_code', $bucketCode );
		$statement->execute();
		$row = $statement->fetch( PDO::FETCH_ASSOC );

		return is_array( $row ) ? $this->normalizeBucketRow( $row ) : array();
	}

	private function findLot( string $lotUuid ): array {
		$statement = $this->connection()->prepare( 'SELECT * FROM "' . BucketFifoSchema::LOT_TABLE . '" WHERE "lot_uuid" = :lot_uuid LIMIT 1' );
		$statement->bindValue( ':lot_uuid', $lotUuid );
		$statement->execute();
		$row = $statement->fetch( PDO::FETCH_ASSOC );

		if ( ! is_array( $row ) ) {
			return array();
		}

		return array(
			'lot_uuid'           => (string) $row['lot_uuid'],
			'bucket_code'        => (string) $row['bucket_code'],
			'sku'                => (string) $row['sku'],
			'show_id'            => (string) $row['show_id'],
			'receipt_reference'  => (string) $row['receipt_reference'],
			'source_reference'   => (string) $row['source_reference'],
			'original_quantity'  => (float) $row['original_quantity'],
			'remaining_quantity' => (float) $row['remaining_quantity'],
			'unit_cost'          => (float) $row['unit_cost'],
			'unit_cost_cents'    => (int) $row['unit_cost_cents'],
			'received_at'        => (string) $row['received_at'],
			'status'             => (string) $row['status'],
		);
	}

	private function normalizeBucketRow( array $row ): array {
		return array(
			'bucket_code'       => (string) $row['bucket_code'],
			'bucket_label'      => (string) $row['bucket_label'],
			'bucket_type'       => (string) $row['bucket_type'],
			'status'            => (string) $row['status'],
			'show_id'           => (string) $row['show_id'],
			'current_location'  => (string) $row['current_location'],
			'current_custody'   => (string) $row['current_custody'],
			'on_hand_quantity'  => isset( $row['on_hand_quantity'] ) ? (float) $row['on_hand_quantity'] : 0.0,
			'created_at'        => (string) $row['created_at'],
			'updated_at'        => (string) $row['updated_at'],
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $availableLots
	 * @return array<int, int>
	 */
	private function prorateCents( int $totalCents, array $availableLots, float $requestedQuantity ): array {
		if ( $totalCents <= 0 || $requestedQuantity <= 0 ) {
			return array();
		}

		$remaining = $requestedQuantity;
		$allocatableQuantities = array();

		foreach ( $availableLots as $lot ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$allocatable = min( $remaining, (float) ( $lot['remaining_quantity'] ?? 0 ) );
			if ( $allocatable <= 0 ) {
				continue;
			}

			$allocatableQuantities[] = $allocatable;
			$remaining -= $allocatable;
		}

		if ( empty( $allocatableQuantities ) ) {
			return array();
		}

		$splits = array();
		$allocated = 0;
		$lastIndex = count( $allocatableQuantities ) - 1;

		foreach ( $allocatableQuantities as $index => $quantity ) {
			if ( $index === $lastIndex ) {
				$splits[] = $totalCents - $allocated;
				break;
			}

			$share = (int) round( $totalCents * ( $quantity / $requestedQuantity ) );
			$splits[] = $share;
			$allocated += $share;
		}

		return $splits;
	}

	private function connection(): PDO {
		if ( null !== $this->connection ) {
			return $this->connection;
		}

		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			throw new \RuntimeException( 'The pdo_sqlite extension is required to use the standalone AIMS bucket store.' );
		}

		$connection = new PDO( 'sqlite:' . $this->sqlitePath );
		$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$connection->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$this->connection = $connection;

		return $this->connection;
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

	private function intValue( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function uuid(): string {
		$hash = hash( 'sha256', microtime( true ) . '|' . random_int( 0, PHP_INT_MAX ) );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 12, 4 ),
			substr( $hash, 16, 4 ),
			substr( $hash, 20, 12 )
		);
	}
}
