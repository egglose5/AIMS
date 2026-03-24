<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Module {
	private $vendor_service;
	private $vendor_checkin_portal_controller;

	public function __construct( AIMS_Vendor_Service $vendor_service ) {
		$this->vendor_service = $vendor_service;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_public_hooks' ) );
		add_action( 'admin_init', array( $this, 'register_foundation_notices' ) );
	}

	public function register_public_hooks(): void {
		$this->get_vendor_checkin_portal_controller()->register();
	}

	public function register_foundation_notices(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( AIMS_Capabilities::CAP_MANAGE_VENDORS ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'aims-vendors' !== $page ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_foundation_notice' ) );
	}

	public function render_foundation_notice(): void {
		$vendors = $this->vendor_service->list_vendors();

		echo '<div class="notice notice-info"><p>';
		echo esc_html(
			sprintf(
				'Vendor Manage foundation is active. %d vendor records currently exist in AIMS tables. Native vendor access control and Square team-member reconciliation remain here, while event and inventory modules own bucket assignment.',
				count( $vendors )
			)
		);
		echo '</p></div>';
	}

	private function get_vendor_checkin_portal_controller(): AIMS_Vendor_Event_Checkin_Portal_Controller {
		if ( null === $this->vendor_checkin_portal_controller ) {
			$this->vendor_checkin_portal_controller = new AIMS_Vendor_Event_Checkin_Portal_Controller();
		}

		return $this->vendor_checkin_portal_controller;
	}
}

