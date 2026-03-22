<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Catalog_Service {
	/**
	 * Catalog publishing remains downstream of AIMS catalog state.
	 * Implement Woo-to-Square publish intents and location sync orchestration here later.
	 */

	public function build_publish_intent( array $product, array $context = array() ): array {
		return array(
			'product_id'   => (int) ( $product['id'] ?? 0 ),
			'sku'          => sanitize_text_field( $product['sku'] ?? '' ),
			'context'      => $context,
			'publishable'  => false,
			'locations'    => array(),
		);
	}

	public function orchestrate_location_sync( array $intent ): array {
		return array(
			'publish_intent' => $intent,
			'synced'         => false,
			'reason'         => 'Square catalog publishing is not implemented yet.',
		);
	}
}
