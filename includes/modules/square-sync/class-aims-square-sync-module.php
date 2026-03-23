<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Sync_Module {
	private $webhook_controller;
	private $sync_run_controller;

	public function __construct(
		?AIMS_Square_Webhook_Controller $webhook_controller = null,
		?AIMS_Square_Sync_Run_Controller $sync_run_controller = null
	) {
		$this->webhook_controller = $webhook_controller ? $webhook_controller : new AIMS_Square_Webhook_Controller();
		$this->sync_run_controller = $sync_run_controller ? $sync_run_controller : new AIMS_Square_Sync_Run_Controller();
	}

	public function register(): void {
		$this->webhook_controller->register();
		$this->sync_run_controller->register();
		add_action( 'admin_init', array( $this, 'register_foundation_notices' ) );
	}

	public function register_foundation_notices(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( AIMS_Capabilities::CAP_MANAGE_SQUARE_SYNC ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'aims-square-sync' !== $page ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_foundation_notice' ) );
	}

	public function render_shell(): void {
		echo '<div class="wrap">';
		echo '<h1>Square Sync</h1>';
		echo '<p>The Square runtime shell will route webhooks, backfill, replay, and undo through isolated controllers and services.</p>';
		echo '<ul style="list-style: disc; padding-left: 20px;">';
		foreach ( $this->get_surface_outline() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	public function render_foundation_notice(): void {
		echo '<div class="notice notice-info"><p>';
		echo esc_html( 'Square Sync foundation is active. Webhook intake, replay, and undo controllers will attach here once the runtime services are wired.' );
		echo '</p></div>';
	}

	public function get_surface_outline(): array {
		return array(
			'Webhook intake stays thin and records only raw facts.',
			'Replay and undo operations will flow through dedicated controllers and sync logging.',
			'Vendor sync and assignment logic will live in services, not controllers.',
		);
	}
}

