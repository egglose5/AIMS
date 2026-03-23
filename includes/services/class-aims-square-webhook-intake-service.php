<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Webhook_Intake_Service {
	private $queue;
	private $raw_events;
	private $normalization;

	public function __construct(
		AIMS_Square_Import_Queue_Repository $queue,
		AIMS_Square_Raw_Event_Service $raw_events = null,
		AIMS_Square_Normalization_Service $normalization = null
	) {
		$this->queue         = $queue;
		$this->raw_events    = $raw_events;
		$this->normalization = $normalization ? $normalization : new AIMS_Square_Normalization_Service();
	}

	public function ingest_order_payload( array $payload, array $context = array() ): array {
		$raw_event_result = $this->ingest_raw_event( $payload, $context );
		$queue_record     = $this->normalization->normalize_queue_record( $payload );
		$queue_id         = $this->queue->save( $queue_record );

		return array(
			'queue_id'     => (int) $queue_id,
			'queue_record' => $queue_record,
			'raw_event_id' => (int) ( $raw_event_result['raw_event_id'] ?? 0 ),
			'raw_event'    => $raw_event_result['raw_event'] ?? array(),
			'dedupe_key'   => (string) ( $raw_event_result['dedupe_key'] ?? '' ),
			'created'      => ! empty( $raw_event_result['created'] ),
		);
	}

	public function ingest_raw_event( array $payload, array $context = array() ): array {
		if ( null === $this->raw_events ) {
			return array(
				'raw_event_id' => 0,
				'raw_event'    => array(),
				'created'      => false,
				'dedupe_key'   => '',
			);
		}

		return $this->raw_events->save_raw_event( $payload, wp_parse_args( $context, array( 'source_type' => 'webhook' ) ) );
	}
}
