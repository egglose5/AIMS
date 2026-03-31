<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Module implements AIMS_Module {
	private $webhook_controller;
	private $sync_run_controller;
	private $responsibility_auth;
	private const ADMIN_PAGE = 'aims-square-sync';

	public function __construct(
		?AIMS_Square_Webhook_Controller $webhook_controller = null,
		?AIMS_Square_Sync_Run_Controller $sync_run_controller = null,
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null
	) {
		$this->webhook_controller = $webhook_controller ? $webhook_controller : new AIMS_Square_Webhook_Controller();
		$this->sync_run_controller = $sync_run_controller ? $sync_run_controller : new AIMS_Square_Sync_Run_Controller();
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function register(): void {
		$this->webhook_controller->register();
		$this->sync_run_controller->register();
		add_action( 'admin_post_aims_square_sync_ingest', array( $this, 'handle_admin_ingest' ) );
	}

	public function render_shell(): void {
		if ( ! $this->can_manage_square_sync() ) {
			wp_die( esc_html__( 'You do not have permission to manage Square sync.', 'ai-man-sys' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>Square Sync</h1>';
		echo '<p>Run manual ingestion safely from admin, then review runs and replay/undo with capability-gated controls.</p>';
		$this->render_status_notice();
		$this->render_ingest_form();
		$this->render_runtime_links();
		echo '</div>';
	}

	public function handle_admin_ingest(): void {
		if ( ! $this->can_manage_square_sync() ) {
			wp_die( esc_html__( 'You do not have permission to manage Square sync.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_square_sync_ingest' );

		$payload_json = (string) wp_unslash( $_POST['payload_json'] ?? '' );
		$mode         = sanitize_key( wp_unslash( $_POST['ingest_mode'] ?? 'analyze_only' ) );
		$payload      = json_decode( $payload_json, true );

		if ( ! is_array( $payload ) ) {
			$this->redirect_with_message( 'error', 'Payload must be valid JSON.' );
		}

		$sync_runs = new AIMS_Sync_Run_Repository();
		$actions   = new AIMS_Sync_Action_Repository();
		$run_id    = $sync_runs->start_run(
			array(
				'source_system'  => 'square',
				'sync_watermark' => gmdate( 'c' ),
				'message'        => 'Manual admin ingestion started.',
			)
		);

		$import_service = new AIMS_Square_Import_Service(
			new AIMS_Square_Import_Queue_Repository(),
			new AIMS_Square_Sale_Repository(),
			new AIMS_Customer_Repository(),
			new AIMS_Customer_Address_Repository(),
			new AIMS_Fulfillment_Service( new AIMS_Sale_Fulfillment_Allocation_Repository() )
		);

		$result = 'persist_flow' === $mode
			? $import_service->persist_queue_to_sales_flow( $payload )
			: $import_service->ingest_order_payload( $payload );

		$processed_records = 1;
		$error_count       = 0;
		$message           = 'Manual ingest completed.';

		if ( 'persist_flow' === $mode && empty( $result['sale_ids'] ) ) {
			$error_count = 1;
			$message     = 'Ingest completed but no sale rows were persisted.';
		}

		$sync_runs->finish_run(
			$run_id,
			array(
				'success'           => 0 === $error_count,
				'processed_records' => $processed_records,
				'skipped_records'   => 0,
				'error_count'       => $error_count,
				'sync_watermark'    => gmdate( 'c' ),
				'message'           => $message,
			)
		);

		$actions->save(
			array(
				'run_id'             => $run_id,
				'external_record_id' => (string) ( $payload['id'] ?? '' ),
				'action_type'        => 'persist_flow' === $mode ? 'import' : 'analyze',
				'entity_type'        => 'square_order',
				'entity_id'          => 0,
				'status'             => 0 === $error_count ? 'success' : 'error',
				'quantity_delta'     => 1,
				'message'            => $message,
				'occurred_at'        => current_time( 'mysql' ),
			)
		);

		$this->redirect_with_message(
			0 === $error_count ? 'success' : 'error',
			$message . ' Run #' . $run_id . '.'
		);
	}

	private function render_status_notice(): void {
		$status  = isset( $_GET['aims_square_sync_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_square_sync_status'] ) ) : '';
		$message = isset( $_GET['aims_square_sync_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_square_sync_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_ingest_form(): void {
		$sample_payload = wp_json_encode(
			array(
				'id'         => 'sample-order-' . gmdate( 'YmdHis' ),
				'location_id'=> 'sample-location',
				'created_at' => current_time( 'mysql' ),
				'line_items' => array(
					array(
						'uid'           => 'line-1',
						'name'          => 'Sample Item',
						'quantity'      => '1',
						'gross_amount'  => 10.00,
						'net_amount'    => 10.00,
					),
				),
			),
			JSON_PRETTY_PRINT
		);

		echo '<h2>Manual Ingestion</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aims_square_sync_ingest' );
		echo '<input type="hidden" name="action" value="aims_square_sync_ingest" />';
		echo '<p><label for="aims-square-payload"><strong>Order payload JSON</strong></label></p>';
		echo '<textarea id="aims-square-payload" name="payload_json" rows="14" style="width:100%;font-family:monospace;">' . esc_textarea( $sample_payload ) . '</textarea>';
		echo '<p><label for="aims-ingest-mode"><strong>Mode</strong></label> ';
		echo '<select id="aims-ingest-mode" name="ingest_mode">';
		echo '<option value="analyze_only">Analyze + queue only (safe)</option>';
		echo '<option value="persist_flow">Persist through sales flow</option>';
		echo '</select></p>';
		echo '<p><button type="submit" class="button button-primary">Run manual ingestion</button></p>';
		echo '</form>';
	}

	private function render_runtime_links(): void {
		$runs_url      = add_query_arg( array( 'page' => 'aims-square-sync-runs' ), admin_url( 'admin.php' ) );
		$exceptions_url = add_query_arg( array( 'page' => 'aims-square-exceptions' ), admin_url( 'admin.php' ) );
		$review_url    = add_query_arg( array( 'page' => 'aims-vendor-sync-review' ), admin_url( 'admin.php' ) );

		echo '<hr />';
		echo '<h2>Operational Review Surfaces</h2>';
		echo '<p><a class="button" href="' . esc_url( $runs_url ) . '">Open Sync Runs / Replay</a> ';
		echo '<a class="button" href="' . esc_url( $exceptions_url ) . '">Open Exceptions</a> ';
		echo '<a class="button" href="' . esc_url( $review_url ) . '">Open Vendor Sync Review</a></p>';
	}

	private function redirect_with_message( string $status, string $message ): void {
		$redirect = add_query_arg(
			array(
				'page'                   => self::ADMIN_PAGE,
				'aims_square_sync_status'  => $status,
				'aims_square_sync_message' => $message,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private function can_manage_square_sync(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id > 0 && is_object( $this->responsibility_auth ) && method_exists( $this->responsibility_auth, 'can_manage_square_sync' ) ) {
			if ( $this->responsibility_auth->can_manage_square_sync( $user_id ) ) {
				return true;
			}
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_SQUARE_SYNC );
	}
}

