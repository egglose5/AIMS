<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Audit_Log_Page {
	public const PAGE_SLUG = 'aims-activity-log';

	private $data_provider;

	public function __construct( AIMS_Audit_Log_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$filters = $this->get_filters();
		$model   = $this->data_provider->get_page_model( $filters );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AIMS Activity Log', 'ai-man-sys' ) . '</h1>';
		echo '<p>' . esc_html__( 'Structured plugin-side action proof for the WordPress control surface.', 'ai-man-sys' ) . '</p>';

		$widget = new AIMS_Admin_Audit_Log_Widget( new AIMS_Admin_Meta_Object( array(
			'filters' => $model['filters'] ?? $filters,
			'rows'    => $model['rows'] ?? array(),
			'summary' => $model['summary'] ?? array(),
		) ) );

		$widget->render();

		echo '</div>';
	}

	private function get_filters(): array {
		return array(
			'user_id'    => isset( $_GET['aims_audit_user_id'] ) ? absint( wp_unslash( $_GET['aims_audit_user_id'] ) ) : 0,
			'action_key' => isset( $_GET['aims_audit_action'] ) ? sanitize_key( wp_unslash( $_GET['aims_audit_action'] ) ) : '',
			'status'     => isset( $_GET['aims_audit_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_audit_status'] ) ) : '',
			'search'     => isset( $_GET['aims_audit_search'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_audit_search'] ) ) : '',
			'limit'      => isset( $_GET['aims_audit_limit'] ) ? absint( wp_unslash( $_GET['aims_audit_limit'] ) ) : 50,
		);
	}
}
