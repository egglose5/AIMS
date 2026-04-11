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
	private ?BinarySaleStreamWriter $binaryWriter = null;
	private ?BinarySaleStreamReader $binaryReader = null;
	/** @var array<string, mixed> */
	private array $binaryOptions = array();

	/**
	 * @param array<string, mixed> $options
	 */
	public function __construct( string $sqlitePath, array $options = array() ) {
		$mode = strtolower( trim( (string) ( $options['binary_stream_mode'] ?? 'shadow' ) ) );
		if ( ! in_array( $mode, array( 'off', 'shadow', 'primary' ), true ) ) {
			$mode = 'shadow';
		}

		$this->sqlitePath = $sqlitePath;
		$this->binaryOptions = array(
			'binary_stream_mode' => $mode,
			'flush_packet_limit' => max( 1, (int) ( $options['binary_flush_packet_limit'] ?? 1 ) ),
			'flush_byte_limit'   => max( BinarySaleStreamWriter::PACKET_BYTES, (int) ( $options['binary_flush_byte_limit'] ?? 65536 ) ),
		);
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

		if ( '' === $sku || ( '' === $fromLocation && '' === $toLocation ) ) {
			throw new \InvalidArgumentException( 'Movement requires sku and at least one location.' );
		}

		if ( $quantity <= 0 ) {
			throw new \InvalidArgumentException( 'Movement quantity must be greater than zero.' );
		}

		$fromEndpoint = $this->stringValue( $input['from_endpoint'] ?? $fromLocation );
		$toEndpoint   = $this->stringValue( $input['to_endpoint'] ?? $toLocation );

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
				'from_endpoint'  => $fromEndpoint,
				'to_endpoint'    => $toEndpoint,
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

		$binaryShadow = $this->recordBinaryShadow( $input, $row, $occurredAt, $movementType );

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
			'binary_shadow'       => $binaryShadow,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function findBinaryPointers( string $referenceType, string $referenceId ): array {
		return $this->binaryWriter()->findPointers( $referenceType, $referenceId );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function binaryShadowSummary(): array {
		return $this->binaryWriter()->summary();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function binaryShadowArchiveSummary( string $showId = '' ): array {
		$pointers   = $this->binaryWriter()->listPointers( $showId );
		$summary    = $this->binaryWriter()->summary();
		$segments   = array();
		$firstStamp = 0;
		$lastStamp  = 0;

		foreach ( $pointers as $pointer ) {
			$segmentName = (string) ( $pointer['segment_name'] ?? basename( (string) ( $pointer['segment_path'] ?? '' ) ) );
			if ( '' !== $segmentName ) {
				$segments[ $segmentName ] = true;
			}

			$timestamp = (int) ( $pointer['timestamp'] ?? 0 );
			if ( $timestamp > 0 && ( 0 === $firstStamp || $timestamp < $firstStamp ) ) {
				$firstStamp = $timestamp;
			}

			if ( $timestamp > 0 && $timestamp > $lastStamp ) {
				$lastStamp = $timestamp;
			}
		}

		return array(
			'show_id'         => $showId,
			'pointer_count'   => count( $pointers ),
			'packet_count'    => count( $pointers ),
			'segment_count'   => count( $segments ),
			'segments'        => array_values( array_keys( $segments ) ),
			'exception_count' => (int) ( $summary['exception_count'] ?? 0 ),
			'active_from'     => $firstStamp > 0 ? gmdate( 'c', $firstStamp ) : '',
			'active_to'       => $lastStamp > 0 ? gmdate( 'c', $lastStamp ) : '',
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function queryBinaryShadow( array $filters = array(), int $limit = 100 ): array {
		$showId        = $this->stringValue( $filters['show_id'] ?? '' );
		$referenceType = $this->stringValue( $filters['reference_type'] ?? '' );
		$referenceId   = $this->stringValue( $filters['reference_id'] ?? '' );
		$sku           = $this->stringValue( $filters['sku'] ?? '' );
		$eventId       = $this->intValue( $filters['event_id'] ?? 0 );
		$pointerId     = $this->intValue( $filters['reference_pointer_id'] ?? $filters['pointer_id'] ?? 0 );
		$limit         = max( 1, min( 1000, $limit ) );

		$pointers = '' !== $referenceType && '' !== $referenceId
			? $this->binaryWriter()->findPointers( $referenceType, $referenceId )
			: $this->binaryWriter()->listPointers( $showId );

		$matches = array();

		foreach ( $pointers as $pointer ) {
			if ( $pointerId > 0 && $pointerId !== (int) ( $pointer['reference_pointer_id'] ?? 0 ) ) {
				continue;
			}

			if ( '' !== $showId && $showId !== (string) ( $pointer['show_id'] ?? '' ) ) {
				continue;
			}

			if ( '' !== $referenceType && $referenceType !== (string) ( $pointer['reference_type'] ?? '' ) ) {
				continue;
			}

			if ( '' !== $referenceId && $referenceId !== (string) ( $pointer['reference_id'] ?? '' ) ) {
				continue;
			}

			if ( '' !== $sku && $sku !== (string) ( $pointer['sku'] ?? '' ) ) {
				continue;
			}

			if ( $eventId > 0 && $eventId !== (int) ( $pointer['event_id'] ?? 0 ) ) {
				continue;
			}

			$packet = $this->binaryReader()->readPacket( (string) ( $pointer['segment_path'] ?? '' ), (int) ( $pointer['byte_offset'] ?? 0 ) );
			$matches[] = array_merge(
				$pointer,
				$packet,
				array(
					'history_source' => 'binary_shadow',
				)
			);

			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return $matches;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function reconcileBinaryShadow( string $showId = '' ): array {
		$pointers            = $this->binaryWriter()->listPointers( $showId );
		$saleRows            = $this->saleMovementRows( $showId );
		$summary             = $this->binaryWriter()->summary();
		$driftRows           = array();
		$eventTotals         = array();
		$totalPriceCents     = 0;
		$totalTaxCents       = 0;
		$decodedPacketCount  = 0;

		foreach ( $pointers as $pointer ) {
			$packet = $this->binaryReader()->readPacket( (string) $pointer['segment_path'], (int) $pointer['byte_offset'] );
			++$decodedPacketCount;

			$totalPriceCents += (int) ( $packet['price_cents'] ?? 0 );
			$totalTaxCents   += (int) ( $packet['tax_cents'] ?? 0 );

			$eventId = (int) ( $packet['event_id'] ?? 0 );
			if ( ! isset( $eventTotals[ $eventId ] ) ) {
				$eventTotals[ $eventId ] = array(
					'packet_count' => 0,
					'price_cents'  => 0,
					'tax_cents'    => 0,
				);
			}

			++$eventTotals[ $eventId ]['packet_count'];
			$eventTotals[ $eventId ]['price_cents'] += (int) ( $packet['price_cents'] ?? 0 );
			$eventTotals[ $eventId ]['tax_cents']   += (int) ( $packet['tax_cents'] ?? 0 );

			$pointerMismatch = (string) ( $pointer['sku'] ?? '' ) !== (string) ( $packet['sku'] ?? '' )
				|| (int) ( $pointer['price_cents'] ?? 0 ) !== (int) ( $packet['price_cents'] ?? 0 )
				|| (int) ( $pointer['tax_cents'] ?? 0 ) !== (int) ( $packet['tax_cents'] ?? 0 )
				|| (int) ( $pointer['timestamp'] ?? 0 ) !== (int) ( $packet['timestamp'] ?? 0 )
				|| (int) ( $pointer['event_id'] ?? 0 ) !== (int) ( $packet['event_id'] ?? 0 );

			if ( $pointerMismatch ) {
				$driftRows[] = array(
					'reference_type' => (string) ( $pointer['reference_type'] ?? '' ),
					'reference_id'   => (string) ( $pointer['reference_id'] ?? '' ),
					'byte_offset'    => (int) ( $pointer['byte_offset'] ?? 0 ),
					'pointer'        => $pointer,
					'packet'         => $packet,
				);
			}
		}

		return array(
			'show_id'             => $showId,
			'sale_movement_count' => count( $saleRows ),
			'pointer_count'       => count( $pointers ),
			'packet_count'        => $decodedPacketCount,
			'count_match'         => count( $saleRows ) === count( $pointers ),
			'total_price_cents'   => $totalPriceCents,
			'total_tax_cents'     => $totalTaxCents,
			'drift_count'         => count( $driftRows ),
			'drift_rows'          => $driftRows,
			'exception_count'     => (int) ( $summary['exception_count'] ?? 0 ),
			'dictionary_count'    => (int) ( $summary['dictionary_count'] ?? 0 ),
			'event_totals'        => $eventTotals,
			'ok'                  => count( $saleRows ) === count( $pointers ) && 0 === count( $driftRows ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function inventorySummary( string $showId = '' ): array {
		$statement = $this->connection()->prepare(
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

		return $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();
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

		return $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();
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

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function recordBinaryShadow( array $input, array $row, string $occurredAt, string $movementType ): array {
		$priceFieldsPresent = array_key_exists( 'price_cents', $input ) || array_key_exists( 'amount_paid_cents', $input ) || array_key_exists( 'paid_amount_cents', $input );
		if ( ! $this->isBinaryShadowEnabled() ) {
			return array( 'status' => 'disabled' );
		}

		if ( ! $this->isSaleMovementType( $movementType ) && ! $priceFieldsPresent ) {
			return array( 'status' => 'skipped' );
		}

		return $this->binaryWriter()->appendPacket(
			array(
				'sku'            => (string) ( $row['sku'] ?? '' ),
				'price_cents'    => $this->resolveMoneyCents( $input, array( 'price_cents', 'amount_paid_cents', 'paid_amount_cents' ) ),
				'tax_cents'      => $this->resolveMoneyCents( $input, array( 'tax_cents', 'tax_amount_cents' ) ),
				'timestamp'      => $occurredAt,
				'event_id'       => $this->intValue( $input['event_id'] ?? 0 ),
				'show_id'        => $this->stringValue( $row['show_id'] ?? $input['show_id'] ?? '' ),
				'reference_type' => $this->stringValue( $input['reference_type'] ?? $movementType ),
				'reference_id'   => $this->stringValue( $input['reference_id'] ?? $input['square_order_id'] ?? $row['movement_uuid'] ?? '' ),
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function saleMovementRows( string $showId = '' ): array {
		$query = 'SELECT "movement_uuid", "show_id", "sku", "movement_type", "timestamp" FROM "' . self::HOT_TABLE . '" WHERE "movement_type" IN ("square_sale", "stock_out_sale", "show_consumption")';
		if ( '' !== $showId ) {
			$query .= ' AND "show_id" = :show_id';
		}

		$query .= ' ORDER BY "timestamp" ASC, "id" ASC';
		$statement = $this->connection()->prepare( $query );
		if ( '' !== $showId ) {
			$statement->bindValue( ':show_id', $showId );
		}

		$statement->execute();
		return $statement->fetchAll( PDO::FETCH_ASSOC ) ?: array();
	}

	private function binaryWriter(): BinarySaleStreamWriter {
		if ( null !== $this->binaryWriter ) {
			return $this->binaryWriter;
		}

		$rootPath = dirname( $this->sqlitePath ) . DIRECTORY_SEPARATOR . 'sink' . DIRECTORY_SEPARATOR . 'hot-binary';
		$this->binaryWriter = new BinarySaleStreamWriter( $rootPath, $this->binaryOptions );

		return $this->binaryWriter;
	}

	private function binaryReader(): BinarySaleStreamReader {
		if ( null !== $this->binaryReader ) {
			return $this->binaryReader;
		}

		$this->binaryReader = new BinarySaleStreamReader();

		return $this->binaryReader;
	}

	private function isBinaryShadowEnabled(): bool {
		return 'off' !== (string) ( $this->binaryOptions['binary_stream_mode'] ?? 'shadow' );
	}

	private function isSaleMovementType( string $movementType ): bool {
		return in_array( $movementType, array( 'square_sale', 'stock_out_sale', 'show_consumption' ), true );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<int, string> $keys
	 */
	private function resolveMoneyCents( array $input, array $keys ): ?int {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];
			if ( is_numeric( $value ) ) {
				return (int) round( (float) $value );
			}
		}

		return null;
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
