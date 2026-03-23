<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Reports_Module {
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_foundation_notices' ) );
	}

	public function register_foundation_notices(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( AIMS_Capabilities::CAP_VIEW_REPORTS ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'aims-reports' !== $page ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_foundation_notice' ) );
	}

	public function render_shell(): void {
		echo '<div class="wrap">';
		echo '<h1>Reports &amp; Analytics</h1>';
		echo '<p>AIMS reporting will move from operational projections into ledger-backed reporting over normalized sales, attributed vendor sales, sync effects, and event-scoped inventory activity.</p>';
		echo '<ul style="list-style: disc; padding-left: 20px;">';
		foreach ( $this->get_report_outline() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	public function render_foundation_notice(): void {
		echo '<div class="notice notice-info"><p>';
		echo esc_html( 'Reports & Analytics foundation is active. Ledger-backed reporting will join normalized sales, runtime assignments, and inventory activity through `event_id`.' );
		echo '</p></div>';
	}

	public function get_report_outline(): array {
		return array(
			'Operational reports should join sales, attribution, and inventory through `event_id` rather than direct Square-to-bucket lookups.',
			'Payout and attribution reports should read from normalized sales and the vendor attribution ledger.',
			'Bucket ledger and reconciliation reports should read event-scoped inventory movement rows.',
			'Replay and undo reporting should be driven by sync runs, sync actions, and sync effects.',
		);
	}
}
