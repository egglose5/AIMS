<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Storage;

use AmesCore\Archive\ParquetWriterInterface;

final class FlowParquetArchiveWriter implements ParquetWriterInterface {
	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	public function write( array $rows, string $targetPath ): void {
		if ( ! class_exists( '\Flow\Parquet\Writer' ) ) {
			throw new \RuntimeException( 'flow-php/parquet is required to archive the AIMS hot sink.' );
		}

		$writerClass = '\Flow\Parquet\Writer';
		$schemaClass = '\Flow\Parquet\ParquetFile\Schema';
		$columnClass = '\Flow\Parquet\ParquetFile\Schema\FlatColumn';

		$schema = $schemaClass::with(
			$columnClass::int64( 'id' ),
			$columnClass::string( 'movement_uuid' ),
			$columnClass::string( 'lineage_root_uuid' ),
			$columnClass::string( 'previous_movement_uuid' ),
			$columnClass::int64( 'chain_sequence' ),
			$columnClass::string( 'sku' ),
			$columnClass::string( 'from_loc' ),
			$columnClass::string( 'to_loc' ),
			$columnClass::string( 'timestamp' ),
			$columnClass::string( 'show_id' ),
			$columnClass::int64( 'user_id' ),
			$columnClass::float( 'quantity_delta' ),
			$columnClass::string( 'movement_type' ),
			$columnClass::string( 'manifest_uuid' ),
			$columnClass::string( 'from_endpoint' ),
			$columnClass::string( 'to_endpoint' ),
			$columnClass::string( 'previous_row_hash' ),
			$columnClass::string( 'row_hash' ),
			$columnClass::string( 'created_at' ),
			$columnClass::string( 'updated_at' )
		);

		$writer = method_exists( $writerClass, 'php' ) ? $writerClass::php() : new $writerClass();
		$writer->write( $targetPath, $schema, array_map( array( $this, 'normalizeRow' ), $rows ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalizeRow( array $row ): array {
		return array(
			'id'                     => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'movement_uuid'          => (string) ( $row['movement_uuid'] ?? '' ),
			'lineage_root_uuid'      => (string) ( $row['lineage_root_uuid'] ?? '' ),
			'previous_movement_uuid' => (string) ( $row['previous_movement_uuid'] ?? '' ),
			'chain_sequence'         => isset( $row['chain_sequence'] ) ? (int) $row['chain_sequence'] : 0,
			'sku'                    => (string) ( $row['sku'] ?? '' ),
			'from_loc'               => (string) ( $row['from_loc'] ?? '' ),
			'to_loc'                 => (string) ( $row['to_loc'] ?? '' ),
			'timestamp'              => (string) ( $row['timestamp'] ?? '' ),
			'show_id'                => (string) ( $row['show_id'] ?? '' ),
			'user_id'                => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'quantity_delta'         => isset( $row['quantity_delta'] ) ? (float) $row['quantity_delta'] : 0.0,
			'movement_type'          => (string) ( $row['movement_type'] ?? '' ),
			'manifest_uuid'          => (string) ( $row['manifest_uuid'] ?? '' ),
			'from_endpoint'          => (string) ( $row['from_endpoint'] ?? '' ),
			'to_endpoint'            => (string) ( $row['to_endpoint'] ?? '' ),
			'previous_row_hash'      => (string) ( $row['previous_row_hash'] ?? '' ),
			'row_hash'               => (string) ( $row['row_hash'] ?? '' ),
			'created_at'             => (string) ( $row['created_at'] ?? '' ),
			'updated_at'             => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
