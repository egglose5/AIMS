<?php

declare( strict_types=1 );

namespace AIMS\Core\Sync;

use AIMS\Core\Clients\SquareClient;
use AIMS\Core\Clients\WooCommerceClient;

final class ManifestGenerator {
	private WooCommerceClient $wooClient;
	private SquareClient $squareClient;
	private ConflictResolver $resolver;

	public function __construct( WooCommerceClient $wooClient, SquareClient $squareClient, ?ConflictResolver $resolver = null ) {
		$this->wooClient = $wooClient;
		$this->squareClient = $squareClient;
		$this->resolver = $resolver ?: new ConflictResolver();
	}

	public function generate( array $context = array() ): array {
		$catalogTruth      = $context['catalog_truth'] ?? $this->wooClient->fetchCatalogTruth( $context['catalog_filters'] ?? array() );
		$transactionalTruth = $context['transactional_truth'] ?? $this->squareClient->fetchTransactionalTruth( $context['transaction_filters'] ?? array() );
		$positionalTruth    = $context['positional_truth'] ?? array( 'ledger' => $context['ledger_rows'] ?? array() );
		$resolvedTruth      = $this->resolver->resolveManifestTruth( $catalogTruth, $transactionalTruth, $positionalTruth );

		return array(
			'manifest_id'      => $context['manifest_id'] ?? $this->buildManifestId(),
			'generated_at'     => $context['generated_at'] ?? gmdate( 'c' ),
			'show_id'          => $context['show_id'] ?? null,
			'source_hierarchy'  => array( 'wp_woo', 'square', 'aims' ),
			'catalog_truth'    => $catalogTruth,
			'transactional_truth' => $transactionalTruth,
			'positional_truth' => $positionalTruth,
			'resolved_truth'   => $resolvedTruth,
			'checksums'        => $this->buildChecksums( $catalogTruth, $transactionalTruth, $positionalTruth ),
		);
	}

	public function generateJson( array $context = array() ): string {
		return json_encode( $this->generate( $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	private function buildManifestId(): string {
		return 'manifest-' . gmdate( 'YmdHis' ) . '-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
	}

	private function buildChecksums( array $catalogTruth, array $transactionalTruth, array $positionalTruth ): array {
		return array(
			'catalog'       => hash( 'sha256', json_encode( $catalogTruth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' ),
			'transactional' => hash( 'sha256', json_encode( $transactionalTruth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' ),
			'positional'    => hash( 'sha256', json_encode( $positionalTruth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' ),
		);
	}
}
