<?php

declare( strict_types=1 );

namespace AmesCore\Schema;

final class MovementLedgerSchema {
	public const DEFAULT_TABLE_NAME = 'ames_movement_ledger';
	public const DEFAULT_INDEX_PREFIX = 'idx_ames_movement_ledger';

	/**
	 * Return the canonical ledger columns in order.
	 *
	 * The base business fields stay close to the user's requested shape:
	 * id, sku, from_loc, to_loc, timestamp, show_id, user_id.
	 * Chain-of-custody integrity is added with immutable lineage and digest fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function columns(): array {
		return array(
			'id' => array(
				'type' => 'INTEGER',
				'constraints' => 'PRIMARY KEY AUTOINCREMENT',
			),
			'movement_uuid' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL UNIQUE',
			),
			'lineage_root_uuid' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'previous_movement_uuid' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'chain_sequence' => array(
				'type' => 'INTEGER',
				'constraints' => 'NOT NULL DEFAULT 1',
			),
			'sku' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL',
			),
			'from_loc' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'to_loc' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'timestamp' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL',
			),
			'show_id' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'user_id' => array(
				'type' => 'INTEGER',
				'constraints' => 'NOT NULL DEFAULT 0',
			),
			'quantity_delta' => array(
				'type' => 'REAL',
				'constraints' => 'NOT NULL DEFAULT 0',
			),
			'movement_type' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'transfer\'',
			),
			'manifest_uuid' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'from_endpoint' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'to_endpoint' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'previous_row_hash' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL DEFAULT \'\'',
			),
			'row_hash' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL',
			),
			'created_at' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL',
			),
			'updated_at' => array(
				'type' => 'TEXT',
				'constraints' => 'NOT NULL',
			),
		);
	}

	public static function createTableSql( string $tableName = self::DEFAULT_TABLE_NAME ): string {
		$tableName = self::quoteIdentifier( $tableName );
		$parts     = array();

		foreach ( self::columns() as $columnName => $definition ) {
			$parts[] = sprintf(
				'%s %s %s',
				self::quoteIdentifier( $columnName ),
				$definition['type'],
				$definition['constraints']
			);
		}

		return sprintf(
			'CREATE TABLE IF NOT EXISTS %s (%s);',
			$tableName,
			implode( ', ', $parts )
		);
	}

	/**
	 * SQLite index statements that support common custody, show, and SKU lookups.
	 *
	 * @return array<int, string>
	 */
	public static function createIndexSql( string $tableName = self::DEFAULT_TABLE_NAME ): array {
		$tableName = self::quoteIdentifier( $tableName );

		return array(
			sprintf(
				'CREATE INDEX IF NOT EXISTS %s ON %s (%s);',
				self::quoteIdentifier( self::DEFAULT_INDEX_PREFIX . '_sku_timestamp' ),
				$tableName,
				self::quoteIdentifierList( array( 'sku', 'timestamp' ) )
			),
			sprintf(
				'CREATE INDEX IF NOT EXISTS %s ON %s (%s);',
				self::quoteIdentifier( self::DEFAULT_INDEX_PREFIX . '_show_timestamp' ),
				$tableName,
				self::quoteIdentifierList( array( 'show_id', 'timestamp' ) )
			),
			sprintf(
				'CREATE INDEX IF NOT EXISTS %s ON %s (%s);',
				self::quoteIdentifier( self::DEFAULT_INDEX_PREFIX . '_lineage' ),
				$tableName,
				self::quoteIdentifierList( array( 'lineage_root_uuid', 'chain_sequence' ) )
			),
			sprintf(
				'CREATE INDEX IF NOT EXISTS %s ON %s (%s);',
				self::quoteIdentifier( self::DEFAULT_INDEX_PREFIX . '_manifest' ),
				$tableName,
				self::quoteIdentifierList( array( 'manifest_uuid' ) )
			),
			sprintf(
				'CREATE INDEX IF NOT EXISTS %s ON %s (%s);',
				self::quoteIdentifier( self::DEFAULT_INDEX_PREFIX . '_movement_uuid' ),
				$tableName,
				self::quoteIdentifierList( array( 'movement_uuid' ) )
			),
		);
	}

	/**
	 * A full migration bundle for initializing the sink.
	 *
	 * @return array<int, string>
	 */
	public static function migrationSql( string $tableName = self::DEFAULT_TABLE_NAME ): array {
		return array_merge(
			array( self::createTableSql( $tableName ) ),
			self::createIndexSql( $tableName )
		);
	}

	/**
	 * Normalize a movement row for insertion and derive custody digests.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public static function normalizeRow( array $row, array $context = array() ): array {
		$movementUuid      = self::stringValue( $row['movement_uuid'] ?? self::generateUuid( $row ) );
		$lineageRootUuid   = self::stringValue( $row['lineage_root_uuid'] ?? $movementUuid );
		$previousMovement  = self::stringValue( $row['previous_movement_uuid'] ?? '' );
		$sequence          = self::intValue( $row['chain_sequence'] ?? 1, 1 );
		$sku               = self::stringValue( $row['sku'] ?? '' );
		$fromLoc           = self::stringValue( $row['from_loc'] ?? '' );
		$toLoc             = self::stringValue( $row['to_loc'] ?? '' );
		$timestamp         = self::stringValue( $row['timestamp'] ?? self::timestampNow() );
		$showId            = self::stringValue( $row['show_id'] ?? '' );
		$userId            = self::intValue( $row['user_id'] ?? 0, 0 );
		$quantityDelta     = self::floatValue( $row['quantity_delta'] ?? 0 );
		$movementType      = self::stringValue( $row['movement_type'] ?? 'transfer' );
		$manifestUuid      = self::stringValue( $row['manifest_uuid'] ?? (string) ( $context['manifest_uuid'] ?? '' ) );
		$fromEndpoint      = self::stringValue( $row['from_endpoint'] ?? $fromLoc );
		$toEndpoint        = self::stringValue( $row['to_endpoint'] ?? $toLoc );
		$previousRowHash   = self::stringValue( $row['previous_row_hash'] ?? '' );
		$createdAt         = self::stringValue( $row['created_at'] ?? self::timestampNow() );
		$updatedAt         = self::stringValue( $row['updated_at'] ?? $createdAt );

		$normalized = array(
			'movement_uuid' => $movementUuid,
			'lineage_root_uuid' => $lineageRootUuid,
			'previous_movement_uuid' => $previousMovement,
			'chain_sequence' => $sequence,
			'sku' => $sku,
			'from_loc' => $fromLoc,
			'to_loc' => $toLoc,
			'timestamp' => $timestamp,
			'show_id' => $showId,
			'user_id' => $userId,
			'quantity_delta' => $quantityDelta,
			'movement_type' => $movementType,
			'manifest_uuid' => $manifestUuid,
			'from_endpoint' => $fromEndpoint,
			'to_endpoint' => $toEndpoint,
			'previous_row_hash' => $previousRowHash,
			'created_at' => $createdAt,
			'updated_at' => $updatedAt,
		);

		$normalized['row_hash'] = self::digestRow( $normalized );

		return $normalized;
	}

	/**
	 * Compute the custody digest used to validate the movement chain.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function digestRow( array $row ): string {
		$payload = array(
			'movement_uuid' => self::stringValue( $row['movement_uuid'] ?? '' ),
			'lineage_root_uuid' => self::stringValue( $row['lineage_root_uuid'] ?? '' ),
			'previous_movement_uuid' => self::stringValue( $row['previous_movement_uuid'] ?? '' ),
			'chain_sequence' => self::intValue( $row['chain_sequence'] ?? 0, 0 ),
			'sku' => self::stringValue( $row['sku'] ?? '' ),
			'from_loc' => self::stringValue( $row['from_loc'] ?? '' ),
			'to_loc' => self::stringValue( $row['to_loc'] ?? '' ),
			'timestamp' => self::stringValue( $row['timestamp'] ?? '' ),
			'show_id' => self::stringValue( $row['show_id'] ?? '' ),
			'user_id' => self::intValue( $row['user_id'] ?? 0, 0 ),
			'quantity_delta' => self::floatValue( $row['quantity_delta'] ?? 0 ),
			'movement_type' => self::stringValue( $row['movement_type'] ?? '' ),
			'manifest_uuid' => self::stringValue( $row['manifest_uuid'] ?? '' ),
			'from_endpoint' => self::stringValue( $row['from_endpoint'] ?? '' ),
			'to_endpoint' => self::stringValue( $row['to_endpoint'] ?? '' ),
			'previous_row_hash' => self::stringValue( $row['previous_row_hash'] ?? '' ),
			'created_at' => self::stringValue( $row['created_at'] ?? '' ),
		);

		return hash( 'sha256', json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' );
	}

	private static function timestampNow(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	private static function generateUuid( array $seed = array() ): string {
		$entropy = json_encode( $seed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '';
		$hash    = hash( 'sha256', $entropy . '|' . microtime( true ) . '|' . random_int( 0, PHP_INT_MAX ) );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 12, 4 ),
			substr( $hash, 16, 4 ),
			substr( $hash, 20, 12 )
		);
	}

	private static function stringValue( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	private static function intValue( mixed $value, int $default = 0 ): int {
		return is_numeric( $value ) ? (int) $value : $default;
	}

	private static function floatValue( mixed $value, float $default = 0.0 ): float {
		return is_numeric( $value ) ? (float) $value : $default;
	}

	private static function quoteIdentifier( string $identifier ): string {
		$identifier = str_replace( '"', '""', $identifier );

		return '"' . $identifier . '"';
	}

	/**
	 * @param array<int, string> $identifiers
	 */
	private static function quoteIdentifierList( array $identifiers ): string {
		$quoted = array_map(
			static function ( string $identifier ): string {
				return self::quoteIdentifier( $identifier );
			},
			$identifiers
		);

		return implode( ', ', $quoted );
	}
}
