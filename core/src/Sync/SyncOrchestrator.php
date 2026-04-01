<?php

declare( strict_types=1 );

namespace AIMS\Core\Sync;

final class SyncOrchestrator {
	private ManifestGenerator $manifestGenerator;

	public function __construct( ManifestGenerator $manifestGenerator ) {
		$this->manifestGenerator = $manifestGenerator;
	}

	public function buildSingleClickManifest( array $context = array() ): array {
		$manifest = $this->manifestGenerator->generate( $context );
		$manifest['sync_mode'] = 'single_click';
		$manifest['consistency_model'] = 'all_or_nothing_manifest';

		return $manifest;
	}
}
