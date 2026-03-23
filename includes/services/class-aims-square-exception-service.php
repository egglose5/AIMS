<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Exception_Service {
	private $exceptions;

	public function __construct( AIMS_Sales_Exception_Repository $exceptions = null ) {
		$this->exceptions = $exceptions;
	}

	public function create_exception(
		array $sale,
		string $exception_type,
		string $severity = 'warning',
		string $message = '',
		array $context = array()
	): array {
		$record = $this->build_exception_record( $sale, $exception_type, $severity, $message, $context );

		if ( null === $this->exceptions || ! method_exists( $this->exceptions, 'save' ) ) {
			return array(
				'exception_id' => 0,
				'exception'    => $record,
				'created'      => false,
			);
		}

		$exception_id = $this->exceptions->save( $record );

		return array(
			'exception_id' => (int) $exception_id,
			'exception'    => $record,
			'created'      => true,
		);
	}

	public function flag_unmatched_sale( array $sale, array $context = array() ): array {
		return $this->create_exception(
			$sale,
			'unmatched_sale',
			'warning',
			'No event or runtime assignment matched the normalized sale.',
			$context
		);
	}

	public function flag_ambiguous_sale( array $sale, array $context = array() ): array {
		return $this->create_exception(
			$sale,
			'ambiguous_sale',
			'warning',
			'Multiple candidate runtime assignments matched the normalized sale.',
			$context
		);
	}

	public function flag_stale_assignment( array $sale, array $context = array() ): array {
		return $this->create_exception(
			$sale,
			'stale_assignment',
			'warning',
			'The runtime assignment window did not cover the sale timestamp.',
			$context
		);
	}

	public function flag_duplicate_sale( array $sale, array $context = array() ): array {
		return $this->create_exception(
			$sale,
			'duplicate_sale',
			'info',
			'The normalized sale matched an existing import record.',
			$context
		);
	}

	public function resolve_exception( int $exception_id, array $resolution = array() ): bool {
		if ( null === $this->exceptions || ! method_exists( $this->exceptions, 'update_resolution' ) ) {
			return false;
		}

		return (bool) $this->exceptions->update_resolution( $exception_id, $resolution );
	}

	public function build_exception_record(
		array $sale,
		string $exception_type,
		string $severity,
		string $message,
		array $context = array()
	): array {
		$created_at = current_time( 'mysql' );

		return array(
			'normalized_sale_id' => (int) ( $sale['normalized_sale_id'] ?? $sale['id'] ?? $sale['square_sale_id'] ?? 0 ),
			'exception_type'     => sanitize_key( $exception_type ),
			'severity'           => sanitize_key( $severity ),
			'message'            => sanitize_text_field( $message ),
			'resolution_status'  => sanitize_key( $context['resolution_status'] ?? 'open' ),
			'resolved_by'        => (int) ( $context['resolved_by'] ?? 0 ),
			'resolved_at'        => $context['resolved_at'] ?? null,
			'resolution_notes'   => isset( $context['resolution_notes'] ) ? wp_kses_post( (string) $context['resolution_notes'] ) : '',
			'payload'            => $context,
			'created_at'         => $created_at,
			'updated_at'         => $created_at,
		);
	}
}
