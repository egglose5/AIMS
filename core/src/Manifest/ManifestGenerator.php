<?php

declare( strict_types=1 );

namespace AmesCore\Manifest;

final class ManifestGenerator {
	public const DEFAULT_SCHEMA_VERSION = '1.0.0';

	/** @var callable|null */
	private $catalogProvider;
	/** @var callable|null */
	private $positionalProvider;

	public function __construct( ?callable $catalogProvider = null, ?callable $positionalProvider = null ) {
		$this->catalogProvider    = $catalogProvider;
		$this->positionalProvider = $positionalProvider;
	}

	/**
	 * Build a single JSON-ready manifest that merges catalog truth and positional truth.
	 *
	 * @param array<int|string, mixed> $catalogTruth
	 * @param array<int|string, mixed> $positionalTruth
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public function generate( array $catalogTruth, array $positionalTruth, array $context = array() ): array {
		$catalogRows    = $this->normalizeRows( $catalogTruth, 'sku' );
		$positionRows   = $this->normalizeRows( $positionalTruth, 'sku' );
		$mergedRows     = $this->mergeRowsBySku( $catalogRows, $positionRows );
		$manifestUuid   = $this->stringValue( $context['manifest_uuid'] ?? $this->generateUuid() );
		$generatedAt    = $this->stringValue( $context['generated_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$showId         = $this->stringValue( $context['show_id'] ?? '' );
		$sourceSystem   = $this->stringValue( $context['source_system'] ?? 'ames' );
		$releaseMode    = $this->stringValue( $context['release_mode'] ?? 'single_click' );

		$manifest = array(
			'manifest_uuid' => $manifestUuid,
			'schema_version' => self::DEFAULT_SCHEMA_VERSION,
			'generated_at' => $generatedAt,
			'source_system' => $sourceSystem,
			'release_mode' => $releaseMode,
			'show_id' => $showId,
			'truth_hierarchy' => array(
				'catalog' => 'wp_woo',
				'positional' => 'aims',
			),
			'resolution_policy' => array(
				'product_data' => 'wp_woo',
				'stock_levels' => 'aims',
			),
			'catalog_truth' => array_values( $catalogRows ),
			'positional_truth' => array_values( $positionRows ),
			'items' => array_values( $mergedRows ),
			'summary' => array(
				'catalog_items' => count( $catalogRows ),
				'positional_items' => count( $positionRows ),
				'merged_items' => count( $mergedRows ),
				'conflict_count' => $this->countConflicts( $mergedRows ),
			),
		);

		$manifest['manifest_hash'] = $this->hashManifest( $manifest );

		return $manifest;
	}

	/**
	 * Generate from injected providers, if configured.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public function generateFromProviders( array $context = array() ): array {
		$catalogTruth = is_callable( $this->catalogProvider ) ? call_user_func( $this->catalogProvider, $context ) : array();
		$positional   = is_callable( $this->positionalProvider ) ? call_user_func( $this->positionalProvider, $context ) : array();

		return $this->generate(
			is_array( $catalogTruth ) ? $catalogTruth : array(),
			is_array( $positional ) ? $positional : array(),
			$context
		);
	}

	/**
	 * Encode a manifest as JSON for transport or persistence.
	 *
	 * @param array<string, mixed> $manifest
	 */
	public function toJson( array $manifest, int $flags = 0 ): string {
		$flags = $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		return json_encode( $manifest, $flags | JSON_THROW_ON_ERROR );
	}

	/**
	 * @param array<int|string, mixed> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeRows( array $rows, string $keyField ): array {
		$normalized = array();

		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$resolvedKey = $this->stringValue( $row[ $keyField ] ?? ( is_string( $key ) ? $key : '' ) );
			if ( '' === $resolvedKey ) {
				continue;
			}

			$row[ $keyField ] = $resolvedKey;
			$normalized[ $resolvedKey ] = $row;
		}

		ksort( $normalized, SORT_NATURAL | SORT_FLAG_CASE );

		return array_values( $normalized );
	}

	/**
	 * @param array<int, array<string, mixed>> $catalogRows
	 * @param array<int, array<string, mixed>> $positionRows
	 * @return array<string, array<string, mixed>>
	 */
	private function mergeRowsBySku( array $catalogRows, array $positionRows ): array {
		$catalogIndex  = $this->indexBySku( $catalogRows );
		$positionIndex = $this->indexBySku( $positionRows );
		$skuList       = array_values( array_unique( array_merge( array_keys( $catalogIndex ), array_keys( $positionIndex ) ) ) );

		sort( $skuList, SORT_NATURAL | SORT_FLAG_CASE );

		$merged = array();

		foreach ( $skuList as $sku ) {
			$catalog  = $catalogIndex[ $sku ] ?? array();
			$position = $positionIndex[ $sku ] ?? array();
			$conflicts = $this->detectConflicts( $catalog, $position );

			$merged[ $sku ] = array(
				'sku' => $sku,
				'catalog' => $catalog,
				'position' => $position,
				'resolved' => array(
					'product_data_source' => 'wp_woo',
					'stock_source' => 'aims',
					'catalog_summary' => $this->catalogSummary( $catalog ),
					'position_summary' => $this->positionSummary( $position ),
				),
				'conflicts' => $conflicts,
				'has_conflict' => ! empty( $conflicts ),
			);
		}

		return $merged;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<string, array<string, mixed>>
	 */
	private function indexBySku( array $rows ): array {
		$indexed = array();

		foreach ( $rows as $row ) {
			$sku = $this->stringValue( $row['sku'] ?? '' );
			if ( '' === $sku ) {
				continue;
			}

			$indexed[ $sku ] = $row;
		}

		return $indexed;
	}

	/**
	 * @param array<string, mixed> $catalog
	 * @param array<string, mixed> $position
	 * @return array<int, array<string, mixed>>
	 */
	private function detectConflicts( array $catalog, array $position ): array {
		$conflicts = array();

		if ( ! empty( $catalog ) && ! empty( $position ) ) {
			if ( array_key_exists( 'name', $catalog ) && array_key_exists( 'description', $position ) && $catalog['name'] !== $position['description'] ) {
				$conflicts[] = array(
					'field' => 'product_data',
					'policy' => 'wp_woo_wins',
					'catalog_value' => $catalog['name'],
					'position_value' => $position['description'],
				);
			}

			if ( array_key_exists( 'stock_qty', $catalog ) && array_key_exists( 'stock_qty', $position ) && $catalog['stock_qty'] !== $position['stock_qty'] ) {
				$conflicts[] = array(
					'field' => 'stock_qty',
					'policy' => 'aims_wins',
					'catalog_value' => $catalog['stock_qty'],
					'position_value' => $position['stock_qty'],
				);
			}
		}

		return $conflicts;
	}

	/**
	 * @param array<string, mixed> $catalog
	 */
	private function catalogSummary( array $catalog ): array {
		return array(
			'product_id' => $catalog['product_id'] ?? null,
			'name' => $catalog['name'] ?? '',
			'sku' => $catalog['sku'] ?? '',
			'price' => $catalog['price'] ?? null,
			'currency' => $catalog['currency'] ?? '',
			'updated_at' => $catalog['updated_at'] ?? '',
		);
	}

	/**
	 * @param array<string, mixed> $position
	 */
	private function positionSummary( array $position ): array {
		return array(
			'show_id' => $position['show_id'] ?? '',
			'location' => $position['location'] ?? ( $position['from_loc'] ?? '' ),
			'quantity' => $position['quantity'] ?? ( $position['stock_qty'] ?? null ),
			'last_movement_uuid' => $position['last_movement_uuid'] ?? '',
			'updated_at' => $position['updated_at'] ?? '',
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $mergedRows
	 */
	private function countConflicts( array $mergedRows ): int {
		$count = 0;

		foreach ( $mergedRows as $row ) {
			if ( ! empty( $row['has_conflict'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Build a stable digest for the manifest payload.
	 *
	 * @param array<string, mixed> $manifest
	 */
	private function hashManifest( array $manifest ): string {
		$payload = $manifest;
		unset( $payload['manifest_hash'] );

		return hash( 'sha256', json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' );
	}

	private function generateUuid(): string {
		$bytes = random_bytes( 16 );
		$hex   = bin2hex( $bytes );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	private function stringValue( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}
}
