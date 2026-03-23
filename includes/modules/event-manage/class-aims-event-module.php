<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Module {
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_foundation_notices' ) );
	}

	public function register_foundation_notices(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( AIMS_Capabilities::CAP_VIEW_EVENTS_SHELL ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'aims-events' !== $page ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_foundation_notice' ) );
	}

	public function render_shell(): void {
		echo '<div class="wrap">';
		echo '<h1>Events</h1>';
		echo '<p>Events are the bridge between Square runtime assignment, vendor participation, and physical bucket commitment. Every operational path should resolve through `event_id` rather than direct Square-to-bucket coupling.</p>';
		echo '</div>';
	}

	public function render_foundation_notice(): void {
		echo '<div class="notice notice-info"><p>';
		echo esc_html( 'Events foundation is active. Event bucket assignments and event-centric reporting will use `event_id` as the shared bridge.' );
		echo '</p></div>';
	}
}

