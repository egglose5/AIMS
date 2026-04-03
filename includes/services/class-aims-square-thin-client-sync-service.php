<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Thin_Client_Sync_Service {
	public const CRON_HOOK        = 'aims_square_thin_client_overlap_sync';
	public const CRON_INTERVAL    = 'aims_every_fifteen_minutes';
	public const SOURCE_SYSTEM    = 'square_thin_client';
	public const DEFAULT_OVERLAP  = 15;
	public const DEFAULT_BOOTSTRAP = 60;

	private $client;
	private $runs;
	private $import;
	private $buckets;
	private $event_locations;
	private $runtime_assignments;

	public function __construct(
		AIMS_Headless_Api_Client $client = null,
		AIMS_Sync_Run_Repository $runs = null,
		AIMS_Square_Import_Service $import = null,
		AIMS_Physical_Bucket_Repository $buckets = null,
		AIMS_Event_Square_Location_Repository $event_locations = null,
		AIMS_Runtime_Assignment_Repository $runtime_assignments = null
	) {
		$this->client              = $client ?: AIMS_Headless_Api_Client::from_plugin_options();
		$this->runs                = $runs ?: new AIMS_Sync_Run_Repository();
		$this->import              = $import ?: $this->make_import_service();
		$this->buckets             = $buckets ?: new AIMS_Physical_Bucket_Repository();
		$this->event_locations     = $event_locations ?: new AIMS_Event_Square_Location_Repository();
		$this->runtime_assignments = $runtime_assignments ?: new AIMS_Runtime_Assignment_Repository();
	}

	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_overlap_sync' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ) );
	}

	public function register_schedule( array $schedules ): array {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 Minutes',
		);

		return $schedules;
	}

	public function ensure_schedule(): void {
		if ( ! is_object( $this->client ) || ! method_exists( $this->client, 'is_configured' ) || ! $this->client->is_configured() ) {
			return;
		}

		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public function run_overlap_sync(): array {
		$latestRun  = $this->runs->find_latest_for_source( self::SOURCE_SYSTEM );
		$watermark  = is_array( $latestRun ) ? (string) ( $latestRun['sync_watermark'] ?? '' ) : '';
		$runId      = $this->runs->start_run(
			array(
				'source_system'  => self::SOURCE_SYSTEM,
				'sync_watermark' => $watermark,
				'message'        => 'Square thin-client overlap sync started.',
			)
		);
		$locationIds = $this->resolve_square_location_ids();
		$result      = array(
			'run_id'           => $runId,
			'pulled_count'     => 0,
			'processed_count'  => 0,
			'skipped_count'    => 0,
			'error_count'      => 0,
			'next_watermark'   => $watermark,
			'location_ids'     => $locationIds,
		);

		if ( empty( $locationIds ) ) {
			$this->runs->finish_run(
				$runId,
				array(
					'success'         => 1,
					'processed_records' => 0,
					'skipped_records' => 0,
					'error_count'     => 0,
					'sync_watermark'  => $watermark,
					'message'         => 'No Square locations are currently mapped for thin-client sync.',
				)
			);

			return $result;
		}

		$response = $this->client->pull_square_thin_client_window(
			array(
				'watermark'        => $watermark,
				'overlap_minutes'  => self::DEFAULT_OVERLAP,
				'bootstrap_minutes'=> self::DEFAULT_BOOTSTRAP,
				'location_ids'     => $locationIds,
				'limit'            => 200,
			)
		);

		if ( empty( $response['success'] ) || ! is_array( $response['json']['result'] ?? null ) ) {
			$this->runs->finish_run(
				$runId,
				array(
					'success'         => 0,
					'processed_records' => 0,
					'skipped_records' => 0,
					'error_count'     => 1,
					'sync_watermark'  => $watermark,
					'message'         => (string) ( $response['message'] ?? 'Square thin-client pull failed.' ),
				)
			);

			$result['error_count'] = 1;
			return $result;
		}

		$payloads = (array) ( $response['json']['result']['orders'] ?? array() );
		$result['pulled_count']   = count( $payloads );
		$result['next_watermark'] = (string) ( $response['json']['result']['next_watermark'] ?? $watermark );

		foreach ( $payloads as $payload ) {
			if ( ! is_array( $payload ) || empty( $payload['id'] ) ) {
				++$result['skipped_count'];
				continue;
			}

			$queueResult = $this->import->ingest_order_payload( $payload );
			$persisted   = $this->import->persist_queue_to_sales_flow( $payload, (int) ( $queueResult['queue_id'] ?? 0 ) );

			if ( ! empty( $persisted['sale_ids'] ) ) {
				++$result['processed_count'];
				continue;
			}

			++$result['error_count'];
		}

		$this->runs->finish_run(
			$runId,
			array(
				'success'           => 0 === $result['error_count'],
				'processed_records' => $result['processed_count'],
				'skipped_records'   => $result['skipped_count'],
				'error_count'       => $result['error_count'],
				'sync_watermark'    => $result['next_watermark'],
				'message'           => sprintf( 'Pulled %d Square order(s) across %d location(s).', $result['pulled_count'], count( $locationIds ) ),
			)
		);

		return $result;
	}

	private function resolve_square_location_ids(): array {
		$locationIds = array();

		if ( is_object( $this->buckets ) && method_exists( $this->buckets, 'get_all' ) ) {
			foreach ( (array) $this->buckets->get_all() as $bucket ) {
				if ( is_array( $bucket ) && ! empty( $bucket['square_location_id'] ) ) {
					$locationIds[] = sanitize_text_field( (string) $bucket['square_location_id'] );
				}
			}
		}

		if ( is_object( $this->runtime_assignments ) && method_exists( $this->runtime_assignments, 'get_for_event' ) && class_exists( 'AIMS_Event_Repository' ) ) {
			$events = $this->all_events();
			foreach ( (array) $events as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}

				foreach ( (array) $this->runtime_assignments->get_for_event( (int) ( $event['id'] ?? 0 ) ) as $assignment ) {
					if ( is_array( $assignment ) && ! empty( $assignment['square_location_id'] ) ) {
						$locationIds[] = sanitize_text_field( (string) $assignment['square_location_id'] );
					}
				}
			}
		}

		if ( is_object( $this->event_locations ) && method_exists( $this->event_locations, 'get_for_event' ) && class_exists( 'AIMS_Event_Repository' ) ) {
			$events = $this->all_events();
			foreach ( (array) $events as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}

				foreach ( (array) $this->event_locations->get_for_event( (int) ( $event['id'] ?? 0 ) ) as $mapping ) {
					if ( is_array( $mapping ) && ! empty( $mapping['square_location_id'] ) ) {
						$locationIds[] = sanitize_text_field( (string) $mapping['square_location_id'] );
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $locationIds ) ) );
	}

	private function all_events(): array {
		if ( ! class_exists( 'AIMS_Event_Repository' ) ) {
			return array();
		}

		$repository = new AIMS_Event_Repository();
		if ( method_exists( $repository, 'all' ) ) {
			return (array) $repository->all();
		}

		if ( method_exists( $repository, 'get_all' ) ) {
			return (array) $repository->get_all();
		}

		return array();
	}

	private function make_import_service(): AIMS_Square_Import_Service {
		$queue       = new AIMS_Square_Import_Queue_Repository();
		$rawService  = new AIMS_Square_Raw_Event_Service( new AIMS_Square_Raw_Event_Repository() );
		$intake      = new AIMS_Square_Webhook_Intake_Service( $queue, $rawService );
		$fulfillment = new AIMS_Fulfillment_Service( new AIMS_Sale_Fulfillment_Allocation_Repository() );

		return new AIMS_Square_Import_Service(
			$queue,
			new AIMS_Square_Sale_Repository(),
			new AIMS_Customer_Repository(),
			new AIMS_Customer_Address_Repository(),
			$fulfillment,
			new AIMS_Square_Normalization_Service(),
			$intake,
			new AIMS_Square_Assignment_Service()
		);
	}
}
