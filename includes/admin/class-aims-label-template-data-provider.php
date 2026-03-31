<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Label_Template_Data_Provider {
	private $template_service;

	public function __construct( AIMS_Label_Template_Service $template_service = null ) {
		$this->template_service = $template_service ?: new AIMS_Label_Template_Service();
	}

	public function get_page_model(): array {
		$notice_status  = isset( $_GET['aims_label_template_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_label_template_status'] ) ) : '';
		$notice_message = isset( $_GET['aims_label_template_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_label_template_message'] ) ) : '';

		return array(
			'notice_status'       => $notice_status,
			'notice_message'      => $notice_message,
			'template_choices'    => $this->template_service->get_template_choices(),
			'template_model'      => $this->template_service->build_settings_view_model(),
			'save_action_url'     => admin_url( 'admin-post.php' ),
			'print_action_url'    => admin_url( 'admin-post.php' ),
			'preview_action_url'  => admin_url( 'admin-post.php' ),
			'sample_items'        => $this->get_sample_items(),
		);
	}

	private function get_sample_items(): array {
		return array(
			array(
				'product_name' => 'Sample Stitch Label',
				'product_sku'  => 'STITCH-001',
				'quantity'     => 3,
			),
			array(
				'product_name' => 'Sample Seam Label',
				'product_sku'  => 'STITCH-002',
				'quantity'     => 1,
			),
		);
	}
}
