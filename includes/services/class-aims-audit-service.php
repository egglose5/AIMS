<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Audit_Service {
	public const EVENT_ASSIGNMENT_OVERRIDE = 'assignment_override';
	public const EVENT_COMMISSION_CHANGE = 'commission_change';
	public const EVENT_TRANSFER = 'transfer';
	public const EVENT_ACCESS_CHANGE = 'access_change';

	public function record( string $event_type, array $context = array() ): array {
		$entry = $this->normalize_entry( $event_type, $context );

		/**
		 * Lightweight audit hook for future persistence and reporting.
		 */
		do_action( 'aims_audit_event', $entry );

		return $entry;
	}

	public function record_assignment_override( array $context = array() ): array {
		return $this->record( self::EVENT_ASSIGNMENT_OVERRIDE, $context );
	}

	public function record_commission_change( array $context = array() ): array {
		return $this->record( self::EVENT_COMMISSION_CHANGE, $context );
	}

	public function record_transfer( array $context = array() ): array {
		return $this->record( self::EVENT_TRANSFER, $context );
	}

	public function record_access_change( array $context = array() ): array {
		return $this->record( self::EVENT_ACCESS_CHANGE, $context );
	}

	private function normalize_entry( string $event_type, array $context ): array {
		return array(
			'event_type'   => sanitize_key( $event_type ),
			'actor_id'     => (int) ( $context['actor_id'] ?? 0 ),
			'scope_type'   => sanitize_key( $context['scope_type'] ?? '' ),
			'scope_id'     => (int) ( $context['scope_id'] ?? 0 ),
			'entity_type'   => sanitize_key( $context['entity_type'] ?? '' ),
			'entity_id'     => (int) ( $context['entity_id'] ?? 0 ),
			'reason'       => sanitize_text_field( $context['reason'] ?? '' ),
			'details'      => $this->normalize_details( $context['details'] ?? array() ),
			'created_at'   => current_time( 'mysql' ),
		);
	}

	private function normalize_details( $details ): array {
		if ( is_array( $details ) ) {
			return $details;
		}

		if ( is_object( $details ) ) {
			return get_object_vars( $details );
		}

		return array();
	}
}
