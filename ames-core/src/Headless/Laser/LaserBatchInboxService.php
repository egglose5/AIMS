<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Laser;

final class LaserBatchInboxService {
	private string $sinkRoot;

	public function __construct( string $sinkRoot ) {
		$this->sinkRoot = rtrim( $sinkRoot, DIRECTORY_SEPARATOR );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public function acceptBatch( array $payload, array $context = array() ): array {
		$rows = $this->extractBatchRows( $payload );
		if ( array() === $rows ) {
			throw new \InvalidArgumentException( 'Laser batch payload must include items, operations, or commands.' );
		}

		$batchId    = $this->resolveBatchId( $payload );
		$receivedAt = gmdate( 'c' );
		$directory  = $this->ensureInboxDirectory();
		$filename   = gmdate( 'Ymd_His' ) . '-' . $batchId . '.json';
		$targetPath = $directory . DIRECTORY_SEPARATOR . $filename;

		$record = array(
			'batch_id'       => $batchId,
			'status'         => 'accepted',
			'source'         => $this->resolveSource( $payload, $context ),
			'machine_id'     => trim( (string) ( $payload['machine_id'] ?? $payload['laser_id'] ?? '' ) ),
			'stitch_job_id'  => (int) ( $payload['stitch_job_id'] ?? 0 ),
			'event_id'       => (int) ( $payload['event_id'] ?? 0 ),
			'line_count'     => count( $rows ),
			'received_at'    => $receivedAt,
			'received_from'  => trim( (string) ( $context['received_from'] ?? '' ) ),
			'user_agent'     => trim( (string) ( $context['user_agent'] ?? '' ) ),
			'payload'        => $payload,
		);

		$json = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			throw new \RuntimeException( 'Unable to encode the laser batch payload.' );
		}

		if ( false === @file_put_contents( $targetPath, $json . PHP_EOL, LOCK_EX ) ) {
			throw new \RuntimeException( 'Unable to write the laser batch inbox file.' );
		}

		$record['target_path'] = $targetPath;

		return $record;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listRecentBatches( int $limit = 20 ): array {
		$directory = $this->ensureInboxDirectory();
		$files     = glob( $directory . DIRECTORY_SEPARATOR . '*.json' );
		if ( false === $files ) {
			return array();
		}

		rsort( $files, SORT_NATURAL | SORT_FLAG_CASE );
		$files   = array_slice( $files, 0, max( 1, min( 100, $limit ) ) );
		$result  = array();

		foreach ( $files as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$result[] = array(
				'batch_id'      => (string) ( $decoded['batch_id'] ?? '' ),
				'status'        => (string) ( $decoded['status'] ?? 'accepted' ),
				'source'        => (string) ( $decoded['source'] ?? '' ),
				'machine_id'    => (string) ( $decoded['machine_id'] ?? '' ),
				'stitch_job_id' => (int) ( $decoded['stitch_job_id'] ?? 0 ),
				'event_id'      => (int) ( $decoded['event_id'] ?? 0 ),
				'line_count'    => (int) ( $decoded['line_count'] ?? 0 ),
				'received_at'   => (string) ( $decoded['received_at'] ?? '' ),
				'target_path'   => $file,
			);
		}

		return $result;
	}

	private function ensureInboxDirectory(): string {
		$path = $this->sinkRoot . DIRECTORY_SEPARATOR . 'laser-batches';
		if ( is_dir( $path ) ) {
			return $path;
		}

		if ( ! @mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
			throw new \RuntimeException( 'Unable to create the laser batch inbox directory.' );
		}

		return $path;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<int, array<string, mixed>>
	 */
	private function extractBatchRows( array $payload ): array {
		foreach ( array( 'items', 'operations', 'commands' ) as $key ) {
			$value = $payload[ $key ] ?? null;
			if ( ! is_array( $value ) ) {
				continue;
			}

			return array_values(
				array_filter(
					$value,
					static fn( $row ): bool => is_array( $row )
				)
			);
		}

		return array();
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function resolveBatchId( array $payload ): string {
		$candidate = trim( (string) ( $payload['batch_id'] ?? $payload['batch_uuid'] ?? $payload['job_id'] ?? '' ) );
		if ( '' === $candidate ) {
			$candidate = 'laser-batch-' . gmdate( 'YmdHis' );
		}

		$candidate = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $candidate );
		$candidate = trim( (string) $candidate, '-._' );

		return '' !== $candidate ? $candidate : 'laser-batch-' . gmdate( 'YmdHis' );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 */
	private function resolveSource( array $payload, array $context ): string {
		$source = trim( (string) ( $payload['source'] ?? $context['source'] ?? 'laser_control_docker' ) );
		return '' !== $source ? $source : 'laser_control_docker';
	}
}
