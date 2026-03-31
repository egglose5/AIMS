<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Module implements AIMS_Module {
	private const LABEL_SETTINGS_ACTION = 'aims_save_stitch_label_template_settings';
	private const LABEL_PRINT_ACTION    = 'aims_print_stitch_labels';

	private $stitch_source;
	private $responsibility_auth;
	private $landing_data_provider;
	private $workspace_data_provider;
	private $label_template_service;
	private $label_render_service;

	public function __construct(
		$stitch_source = null,
		AIMS_Responsibility_Authorization_Service $responsibility_auth = null,
		AIMS_Label_Template_Service $label_template_service = null,
		AIMS_Stitch_Label_Rendering_Service $label_render_service = null
	) {
		$this->stitch_source      = $stitch_source;
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
		$this->label_template_service = $label_template_service ?: new AIMS_Label_Template_Service();
		$this->label_render_service   = $label_render_service ?: new AIMS_Stitch_Label_Rendering_Service( $this->label_template_service );
	}

	public function register(): void {
		add_action( 'admin_post_aims_stitch_job_save_note', array( $this, 'handle_save_job_note' ) );
		add_action( 'admin_post_aims_stitch_job_mark_reviewed', array( $this, 'handle_mark_job_reviewed' ) );
		add_action( 'admin_post_' . self::LABEL_SETTINGS_ACTION, array( $this, 'handle_save_label_template_settings' ) );
		add_action( 'admin_post_' . self::LABEL_PRINT_ACTION, array( $this, 'handle_print_stitch_labels' ) );
	}

	public function render_shell(): void {
		if ( ! $this->can_manage_stitch_jobs() ) {
			wp_die( esc_html__( 'You do not have permission to manage stitch jobs.', 'ai-man-sys' ) );
		}

		$page = new AIMS_Stitch_Jobs_Page( $this->get_landing_data_provider() );

		echo '<div class="wrap">';
		echo '<h1>Producer Stitching</h1>';
		$page->render();
		echo '</div>';
	}

	public function render_workspace(): void {
		if ( ! $this->can_manage_stitch_jobs() ) {
			wp_die( esc_html__( 'You do not have permission to manage stitch jobs.', 'ai-man-sys' ) );
		}

		$page = new AIMS_Stitch_Workspace_Page( $this->get_workspace_data_provider() );

		echo '<div class="wrap">';
		echo '<h1>Stitch Job Workspace</h1>';
		$page->render();
		echo '</div>';
	}

	public function render_label_template_shell(): void {
		if ( ! $this->can_manage_stitch_jobs() ) {
			wp_die( esc_html__( 'You do not have permission to manage stitch labels.', 'ai-man-sys' ) );
		}

		$page = new AIMS_Label_Template_Page( new AIMS_Label_Template_Data_Provider( $this->label_template_service ) );
		$page->render();
	}

	public function handle_save_job_note(): void {
		$this->handle_job_action(
			'save_note',
			array( 'save_job_note', 'update_job_note' ),
			'Stitch job notes could not be saved.'
		);
	}

	public function handle_mark_job_reviewed(): void {
		$this->handle_job_action(
			'mark_reviewed',
			array( 'mark_job_reviewed', 'mark_job_as_reviewed' ),
			'Stitch job review state could not be updated.'
		);
	}

	public function handle_save_label_template_settings(): void {
		if ( ! $this->can_manage_stitch_jobs() ) {
			wp_die( esc_html__( 'You do not have permission to manage stitch labels.', 'ai-man-sys' ) );
		}

		check_admin_referer( self::LABEL_SETTINGS_ACTION );

		$template_key = isset( $_POST['template_key'] ) ? sanitize_key( wp_unslash( $_POST['template_key'] ) ) : '';
		$result       = $this->label_template_service->save_template_settings(
			$template_key,
			array(
				'template_name'         => wp_unslash( $_POST['template_name'] ?? '' ),
				'description'           => wp_unslash( $_POST['description'] ?? '' ),
				'label_width_in'        => wp_unslash( $_POST['label_width_in'] ?? 0 ),
				'label_height_in'       => wp_unslash( $_POST['label_height_in'] ?? 0 ),
				'padding_in'            => wp_unslash( $_POST['padding_in'] ?? 0 ),
				'product_name_font_px'  => wp_unslash( $_POST['product_name_font_px'] ?? 0 ),
				'sku_font_px'           => wp_unslash( $_POST['sku_font_px'] ?? 0 ),
				'barcode_height_px'     => wp_unslash( $_POST['barcode_height_px'] ?? 0 ),
				'barcode_margin_top_px' => wp_unslash( $_POST['barcode_margin_top_px'] ?? 0 ),
				'show_product_name'     => ! empty( $_POST['show_product_name'] ) ? 1 : 0,
				'show_sku_text'         => ! empty( $_POST['show_sku_text'] ) ? 1 : 0,
				'show_barcode_text'     => ! empty( $_POST['show_barcode_text'] ) ? 1 : 0,
				'set_active'            => ! empty( $_POST['set_active'] ) ? 1 : 0,
			)
		);

		if ( ! empty( $result['success'] ) && ! empty( $_POST['set_active'] ) && ! empty( $result['template']['template_key'] ) ) {
			$this->label_template_service->set_active_template_key( (string) $result['template']['template_key'] );
		}

		$redirect = add_query_arg(
			array(
				'page'                        => AIMS_Label_Template_Page::PAGE_SLUG,
				'aims_label_template_status'  => ! empty( $result['success'] ) ? 'success' : 'error',
				'aims_label_template_message' => (string) ( $result['message'] ?? 'Unable to save label template settings.' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_print_stitch_labels(): void {
		if ( ! $this->can_manage_stitch_jobs() ) {
			wp_die( esc_html__( 'You do not have permission to print stitch labels.', 'ai-man-sys' ) );
		}

		check_admin_referer( self::LABEL_PRINT_ACTION );

		$template_key = isset( $_POST['template_key'] ) ? sanitize_key( wp_unslash( $_POST['template_key'] ) ) : '';
		$items        = $this->parse_label_items_from_request();

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $this->label_render_service->render_print_document( $items, $template_key );
		exit;
	}

	private function get_landing_data_provider(): AIMS_Stitch_Jobs_Data_Provider {
		if ( null === $this->landing_data_provider ) {
			$this->landing_data_provider = new AIMS_Stitch_Jobs_Data_Provider(
				$this->resolve_stitch_source(),
				$this->responsibility_auth
			);
		}

		return $this->landing_data_provider;
	}

	private function get_workspace_data_provider(): AIMS_Stitch_Workspace_Data_Provider {
		if ( null === $this->workspace_data_provider ) {
			$this->workspace_data_provider = new AIMS_Stitch_Workspace_Data_Provider(
				$this->resolve_stitch_source(),
				$this->responsibility_auth
			);
		}

		return $this->workspace_data_provider;
	}

	private function resolve_stitch_source() {
		if ( is_object( $this->stitch_source ) ) {
			return $this->stitch_source;
		}

		if ( class_exists( 'AIMS_Stitch_Service' ) ) {
			return new AIMS_Stitch_Service();
		}

		return null;
	}

	private function can_manage_stitch_jobs(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return false;
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_STITCH )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE_PRODUCTION )
			|| current_user_can( AIMS_Capabilities::CAP_MANAGE );
	}

	private function handle_job_action( string $action_key, array $candidate_methods, string $fallback_message ): void {
		if ( ! $this->can_manage_stitch_jobs() ) {
			wp_die( esc_html__( 'You do not have permission to manage stitch jobs.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_stitch_job_action_' . $action_key );

		$job_id     = isset( $_POST['stitch_job_id'] ) ? max( 0, (int) wp_unslash( $_POST['stitch_job_id'] ) ) : 0;
		$return_url = isset( $_POST['return_url'] ) ? esc_url_raw( wp_unslash( $_POST['return_url'] ) ) : admin_url( 'admin.php?page=' . AIMS_Stitch_Jobs_Data_Provider::PAGE_SLUG );

		if ( $job_id <= 0 ) {
			$this->redirect_with_message( 'error', 'A valid stitch job is required.', $return_url );
		}

		$source = $this->resolve_stitch_source();
		if ( ! is_object( $source ) ) {
			$this->redirect_with_message( 'error', $fallback_message, $return_url );
		}

		foreach ( $candidate_methods as $method ) {
			if ( ! method_exists( $source, $method ) ) {
				continue;
			}

			$result = $source->{$method}( $job_id, wp_unslash( $_POST ) );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_message( 'error', $result->get_error_message(), $return_url );
			}

			$this->redirect_with_message( 'success', 'Stitch job action completed.', $return_url );
		}

		$this->redirect_with_message( 'error', $fallback_message, $return_url );
	}

	private function redirect_with_message( string $status, string $message, string $return_url ): void {
		$redirect = add_query_arg(
			array(
				'aims_stitch_status'  => sanitize_key( $status ),
				'aims_stitch_message' => $message,
			),
			$return_url
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private function parse_label_items_from_request(): array {
		if ( isset( $_POST['label_items'] ) && is_array( $_POST['label_items'] ) ) {
			return wp_unslash( $_POST['label_items'] );
		}

		$raw_json = '';
		if ( isset( $_POST['label_items_json'] ) ) {
			$raw_json = (string) wp_unslash( $_POST['label_items_json'] );
		}

		if ( '' === trim( $raw_json ) ) {
			return array();
		}

		$decoded = json_decode( $raw_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return $decoded;
	}
}
