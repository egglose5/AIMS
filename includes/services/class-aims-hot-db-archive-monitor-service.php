<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Hot_Db_Archive_Monitor_Service {
	public const CRON_HOOK = 'aims_hot_db_archive_monitor';
	public const CRON_INTERVAL = 'aims_every_fifteen_minutes';
	public const OPTION_LAST_RUN_STATUS = 'aims_hot_db_archive_monitor_status';
	public const OPTION_LOCK_UNTIL = 'aims_hot_db_archive_monitor_lock_until';

	private $client;
	private $health;

	public function __construct( AIMS_Headless_Api_Client $client = null, AIMS_Hot_Db_Health_Service $health = null ) {
		$this->client = $client ?: AIMS_Headless_Api_Client::from_plugin_options();
		$this->health = $health ?: new AIMS_Hot_Db_Health_Service();
	}

	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_monitor' ) );
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

	public function run_monitor(): array {
		$snapshot = is_object( $this->health ) && method_exists( $this->health, 'get_dashboard_snapshot' )
			? $this->health->get_dashboard_snapshot()
			: array();

		if ( empty( $snapshot['should_auto_archive'] ) ) {
			$status = array(
				'status' => 'not-needed',
				'checked_at' => current_time( 'mysql' ),
				'snapshot' => $snapshot,
				'message' => 'Hot data remains below the automatic archive threshold.',
			);
			$this->remember_status( $status );

			return array(
				'archive_triggered' => false,
				'snapshot' => $snapshot,
				'status' => 'not-needed',
				'message' => $status['message'],
			);
		}

		if ( $this->is_locked() ) {
			$status = array(
				'status' => 'locked',
				'checked_at' => current_time( 'mysql' ),
				'snapshot' => $snapshot,
				'message' => 'Automatic archive rotation is already in progress.',
			);
			$this->remember_status( $status );

			return array(
				'archive_triggered' => false,
				'snapshot' => $snapshot,
				'status' => 'locked',
				'message' => $status['message'],
			);
		}

		if ( ! is_object( $this->client ) || ! method_exists( $this->client, 'is_configured' ) || ! $this->client->is_configured() ) {
			$status = array(
				'status' => 'unconfigured',
				'checked_at' => current_time( 'mysql' ),
				'snapshot' => $snapshot,
				'message' => 'Hot data crossed the archive threshold, but the headless archive client is not configured.',
			);
			$this->remember_status( $status );

			return array(
				'archive_triggered' => false,
				'snapshot' => $snapshot,
				'status' => 'unconfigured',
				'message' => $status['message'],
			);
		}

		$this->set_lock( time() + ( 15 * MINUTE_IN_SECONDS ) );

		try {
			$response = $this->client->trigger_archive();
			$success  = ! empty( $response['success'] );
			$status   = array(
				'status' => $success ? 'triggered' : 'failed',
				'checked_at' => current_time( 'mysql' ),
				'snapshot' => $snapshot,
				'response' => $response,
				'message' => $success ? 'Automatic archive rotation was triggered.' : (string) ( $response['message'] ?? 'Automatic archive rotation failed.' ),
			);
			$this->remember_status( $status );

			return array(
				'archive_triggered' => $success,
				'snapshot' => $snapshot,
				'response' => $response,
				'status' => $status['status'],
				'message' => $status['message'],
			);
		} finally {
			$this->clear_lock();
		}
	}

	private function is_locked(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		return time() < (int) get_option( self::OPTION_LOCK_UNTIL, 0 );
	}

	private function set_lock( int $lock_until ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_LOCK_UNTIL, $lock_until, false );
		}
	}

	private function clear_lock(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_LOCK_UNTIL );
		}
	}

	private function remember_status( array $status ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_LAST_RUN_STATUS, $status, false );
		}
	}
}
