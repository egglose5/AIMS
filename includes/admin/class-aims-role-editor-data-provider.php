<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Role_Editor_Data_Provider {
	public const PAGE_SLUG = 'aims-role-editor';

	private $service;

	public function __construct( AIMS_Role_Editor_Service $service = null ) {
		$this->service = $service ?: new AIMS_Role_Editor_Service();
	}

	public function get_page_model(): array {
		$editing_role_slug = isset( $_GET['role_slug'] ) ? sanitize_key( wp_unslash( $_GET['role_slug'] ) ) : '';
		$notice_status     = isset( $_GET['aims_role_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_role_status'] ) ) : '';
		$notice_message    = isset( $_GET['aims_role_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_role_message'] ) ) : '';

		return array_merge(
			$this->service->get_page_model( $editing_role_slug ),
			array(
				'page_slug'       => self::PAGE_SLUG,
				'notice_status'   => $notice_status,
				'notice_message'  => $notice_message,
				'save_action_url' => admin_url( 'admin-post.php' ),
				'delete_action_url' => admin_url( 'admin-post.php' ),
			)
		);
	}
}
