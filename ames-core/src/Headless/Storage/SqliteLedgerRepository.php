<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Storage;

use AmesCore\Archive\ArchiveSinkInterface;
use AmesCore\Schema\MovementLedgerSchema;
use PDO;

final class SqliteLedgerRepository implements ArchiveSinkInterface {
	private const HOT_TABLE = 'aims_ledger_hot';
	private const POSITION_TABLE = 'aims_stock_positions';

	private string $sqlitePath;
	private ?PDO $connection = null;

	public function __construct( string $sqlitePath ) {
		$this->sqlitePath = $sqlitePath;
	}

	public function initialize(): void {
		$pdo = $this->connection();

		foreach ( MovementLedgerSchema::migrationSql( self::HOT_TABLE ) as $statement ) {
			$pdo->exec( $statement );
		}

		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS "' . self::POSITION_TABLE . '" (
				"id" INTEGER PRIMARY KEY AUTOINCREMENT,
				"show_id" TEXT NOT NULL DEFAULT "",
				"sku" TEXT NOT NULL,
				"location" TEXT NOT NULL,
				"quantity" REAL NOT NULL DEFAULT 0,
				"last_movement_uuid" TEXT NOT NULL DEFAULT "",
				"updated_at" TEXT NOT NULL
			)'
		);

		$pdo->exec( 'CREATE UNIQUE INDEX IF NOT EXISTS "idx_aims_stock_positions_scope" ON "' . self::POSITION_TABLE . '" ("show_id", "sku", "location")' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS "idx_aims_stock_positions_sku" ON "' . self::POSITION_TABLE . '" ("sku")' );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function recordMove( array $input ): array {
		$sku          = $this->stringValue( $input['sku'] ?? '' );
		$fromLocation = $this->stringValue( $input['from_location'] ?? '' );
		$toLocation   = $this->stringValue( $input['to_location'] ?? '' );
		$showId       = $this->stringValue( $input['show_id'] ?? 'default' );
		$quantity     = $this->floatValue( $input['quantity'] ?? 0 );
		$userId       = $this->intValue( $input['user_id'] ?? 0 );
		$movementType = $this->stringValue( $input['movement_type'] ?? 'move' );
		$occurredAt   = $this->stringValue( $input['occurred_at'] ?? gmdate( 'c' ) );

		if ( '' === $sku || '' === $fromLocation && '' === $toLocation ) {
			throw new \InvalidArgumentException( 'Movement requires sku and at least one location.' );
		}

		if ( $quantity <= 0 ) {
			throw new \InvalidArgumentException( 'Movement quantity must be greater than zero.' );
		}

		$row = MovementLedgerSchema::normalizeRow(
			array(
				'sku'            => $sku,
				'from_loc'       => $fromLocation,
				'to_loc'         => $toLocation,
				'timestamp'      => $occurredAt,
				'show_id'        => $showId,
				'user_id'        => $userId,
				'quantity_delta' => $quantity,
				'movement_type'  => $movementType,
				'from_endpoint'  => $fromLocation,
				'to_endpoint'    => $toLocation,
				'created_at'     => $occurredAt,
				'updated_at'     => $occurredAt,
			)
		);

		$pdo = $this->connection();
		$pdo->beginTransaction();

		try {
			$columns      = array_keys( $row );
			$placeholders = array_map( static fn( string $column ): string => ':' . $column, $columns );
			$statement    = $pdo->prepare(
				'INSERT INTO "' . self::HOT_TABLE . '" ("' . implode( '", "', $columns ) . '") VALUES (' . implode( ', ', $placeholders ) . ')'
			);

			foreach ( $row as $column => $value ) {
				$statement->bindValue( ':' . $column, $value );
			}

			$statement->execute();

			if ( '' !== $fromLocation ) {
				$this->adjustPosition( $pdo, $showId, $sku, $fromLocation, -1 * $quantity, (string) $row['movement_uuid'], $occurredAt );
			}

			if ( '' !== $toLocation ) {
				$this->adjustPosition( $pdo, $showId, $sku, $toLocation, $quantity, (string) $row['movement_uuid'], $occurredAt );
			}

			$pdo->commit();
		} catch ( \Throwable $exception ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			throw $exception;
		}

		return array(
			'movement_uuid'       => $row['movement_uuid'],
			'sku'                 => $sku,
			'show_id'             => $showId,
			'from_location'       => $fromLocation,
			'to_location'         => $toLocation,
			'quantity'            => $quantity,
			'position_snapshot'   => $this->positionsForSku( $sku, $showId ),
			'positional_quantity' => $this->totalForSku( $sku, $showId ),
			'occurred_at'         => $occurredAt,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function inventorySummary( string $showId = '' ): array {
		$pdo       = $this->connection();
		$condition = '';

		$statement = $pdo->prepare(
			'SELECT "show_id", "sku", SUM("quantity") AS "quantity", MAX("updated_at") AS "updated_at", MAX("last_movement_uuid") AS "last_movement_uuid"
			FROM "' . self::POSITION_TABLE . '"
			' . ( '' !== $showId ? 'WHERE "show_id" = :show_id' : '' ) . '
			GROUP BY "show_id", "sku"
			HAVING SUM("quantity") > 0
			ORDER BY "sku" ASC'
		);

		if ( '' !== $showId ) {
			$statement->bindValue( ':show_id', $showId );
		}

		$statement->execute();
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();

		return array_map(
			function ( array $row ): array {
				$positions = $this->positionsForSku( (string) $row['sku'], (string) $row['show_id'] );

				return array(
					'show_id'            => (string) $row['show_id'],
					'sku'                => (string) $row['sku'],
					'quantity'           => (float) $row['quantity'],
					'location'           => isset( $positions[0]['location'] ) ? (string) $positions[0]['location'] : '',
					'locations'          => $positions,
					'last_movement_uuid' => (string) $row['last_movement_uuid'],
					'updated_at'         => (string) $row['updated_at'],
				);
			},
			$rows
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function movementHistory( string $showId = '', int $limit = 500 ): array {
		$statement = $this->connection()->prepare(
			'SELECT * FROM "' . self::HOT_TABLE . '" ' . ( '' !== $showId ? 'WHERE "show_id" = :show_id ' : '' ) . 'ORDER BY "timestamp" DESC LIMIT :limit'
		);

		if ( '' !== $showId ) {
			$statement->bindValue( ':show_id', $showId );
		}

		$statement->bindValue( ':limit', $limit, PDO::PARAM_INT );
		$statement->execute();

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $catalogProducts
	 * @return array<string, mixed>
	 */
	public function reconcileAgainstCatalog( array $catalogProducts, string $showId = '' ): array {
		$positions       = $this->inventorySummary( $showId );
		$positionIndex   = array();
		$catalogIndex    = array();
		$missingPhysical = array();
		$extraPhysical   = array();
		$discrepancies   = array();

		foreach ( $positions as $position ) {
			$positionIndex[ (string) $position['sku'] ] = $position;
		}

		foreach ( $catalogProducts as $product ) {
			$sku = trim( (string) ( $product['sku'] ?? '' ) );
			if ( '' === $sku ) {
				continue;
			}

			$catalogIndex[ $sku ] = $product;

			if ( ! isset( $positionIndex[ $sku ] ) ) {
				$missingPhysical[] = array( 'sku' => $sku, 'woo' => $product );
				continue;
			}

			$catalogQty = isset( $product['stock_quantity'] ) ? (float) $product['stock_quantity'] : 0.0;
			$aimsQty    = (float) $positionIndex[ $sku ]['quantity'];

			if ( abs( $catalogQty - $aimsQty ) >= 0.0001 ) {
				$discrepancies[] = array(
					'sku'           => $sku,
					'woo_quantity'  => $catalogQty,
					'aims_quantity' => $aimsQty,
				);
			}
		}

		foreach ( $positionIndex as $sku => $position ) {
			if ( ! isset( $catalogIndex[ $sku ] ) ) {
				$extraPhysical[] = $position;
			}
		}

		return array(
			'missing_physical' => $missingPhysical,
			'extra_physical'   => $extraPhysical,
			'discrepancies'    => $discrepancies,
			'ok'               => 0 === count( $missingPhysical ) && 0 === count( $discrepancies ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function archiveTargets( string $showId = '' ): array {
		$statement = $this->connection()->prepare(
			'SELECT "show_id", CAST(SUBSTR("timestamp", 1, 4) AS INTEGER) AS "year", COUNT(*) AS "row_count"
			FROM "' . self::HOT_TABLE . '"
			' . ( '' !== $showId ? 'WHERE "show_id" = :show_id' : '' ) . '
			GROUP BY "show_id", SUBSTR("timestamp", 1, 4)
			ORDER BY "show_id" ASC'
		);

		if ( '' !== $showId ) {
			$statement->bindValue( ':show_id', $showId );
		}

		$statement->execute();

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();

		return array_map(
			static function ( array $row ): array {
				return array(
					'show_id'   => (string) $row['show_id'],
					'year'      => max( 1970, (int) $row['year'] ),
					'row_count' => (int) $row['row_count'],
				);
			},
			$rows
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchHotRows( string $showId ): array {
		$statement = $this->connection()->prepare( 'SELECT * FROM "' . self::HOT_TABLE . '" WHERE "show_id" = :show_id ORDER BY "timestamp" ASC, "id" ASC' );
		$statement->bindValue( ':show_id', $showId );
		$statement->execute();

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();

		return $rows;
	}

	public function truncateHotRows( string $showId ): void {
		$statement = $this->connection()->prepare( 'DELETE FROM "' . self::HOT_TABLE . '" WHERE "show_id" = :show_id' );
		$statement->bindValue( ':show_id', $showId );
		$statement->execute();
	}

	private function connection(): PDO {
		if ( null !== $this->connection ) {
			return $this->connection;
		}

		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			throw new \RuntimeException( 'The pdo_sqlite extension is required to use the AIMS sink.' );
		}

		$connection = new PDO( 'sqlite:' . $this->sqlitePath );
		$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$connection->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$this->connection = $connection;

		return $this->connection;
	}

	private function adjustPosition( PDO $pdo, string $showId, string $sku, string $location, float $delta, string $movementUuid, string $updatedAt ): void {
		$statement = $pdo->prepare( 'SELECT "quantity" FROM "' . self::POSITION_TABLE . '" WHERE "show_id" = :show_id AND "sku" = :sku AND "location" = :location LIMIT 1' );
		$statement->bindValue( ':show_id', $showId );
		$statement->bindValue( ':sku', $sku );
		$statement->bindValue( ':location', $location );
		$statement->execute();
		$currentRow = $statement->fetch( PDO::FETCH_ASSOC );

		$currentQuantity = is_array( $currentRow ) ? (float) ( $currentRow['quantity'] ?? 0 ) : 0.0;
		$newQuantity     = $currentQuantity + $delta;

		if ( $newQuantity < -0.0001 ) {
			throw new \InvalidArgumentException( 'Movement would make source inventory negative for SKU ' . $sku . ' at ' . $location . '.' );
		}

		if ( abs( $newQuantity ) < 0.0001 ) {
			$delete = $pdo->prepare( 'DELETE FROM "' . self::POSITION_TABLE . '" WHERE "show_id" = :show_id AND "sku" = :sku AND "location" = :location' );
			$delete->bindValue( ':show_id', $showId );
			$delete->bindValue( ':sku', $sku );
			$delete->bindValue( ':location', $location );
			$delete->execute();
			return;
		}

		$upsert = $pdo->prepare(
			'INSERT INTO "' . self::POSITION_TABLE . '" ("show_id", "sku", "location", "quantity", "last_movement_uuid", "updated_at")
			VALUES (:show_id, :sku, :location, :quantity, :last_movement_uuid, :updated_at)
			ON CONFLICT("show_id", "sku", "location")
			DO UPDATE SET "quantity" = excluded."quantity", "last_movement_uuid" = excluded."last_movement_uuid", "updated_at" = excluded."updated_at"'
		);
		$upsert->bindValue( ':show_id', $showId );
		$upsert->bindValue( ':sku', $sku );
		$upsert->bindValue( ':location', $location );
		$upsert->bindValue( ':quantity', $newQuantity );
		$upsert->bindValue( ':last_movement_uuid', $movementUuid );
		$upsert->bindValue( ':updated_at', $updatedAt );
		$upsert->execute();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function positionsForSku( string $sku, string $showId ): array {
		$statement = $this->connection()->prepare(
			'SELECT "location", "quantity", "last_movement_uuid", "updated_at"
			FROM "' . self::POSITION_TABLE . '"
			WHERE "show_id" = :show_id AND "sku" = :sku
			ORDER BY "quantity" DESC, "location" ASC'
		);
		$statement->bindValue( ':show_id', $showId );
		$statement->bindValue( ':sku', $sku );
		$statement->execute();

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();

		return array_map(
			static function ( array $row ): array {
				return array(
					'location'           => (string) $row['location'],
					'quantity'           => (float) $row['quantity'],
					'last_movement_uuid' => (string) $row['last_movement_uuid'],
					'updated_at'         => (string) $row['updated_at'],
				);
			},
			$rows
		);
	}

	private function totalForSku( string $sku, string $showId ): float {
		$statement = $this->connection()->prepare( 'SELECT COALESCE(SUM("quantity"), 0) AS "quantity" FROM "' . self::POSITION_TABLE . '" WHERE "show_id" = :show_id AND "sku" = :sku' );
		$statement->bindValue( ':show_id', $showId );
		$statement->bindValue( ':sku', $sku );
		$statement->execute();

		return (float) $statement->fetchColumn();
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
}
