<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Role_Editor_Actions {
	public const ACTION_SAVE   = 'aims_role_editor_save';
	public const ACTION_DELETE = 'aims_role_editor_delete';

	private $service;

	public function __construct( AIMS_Role_Editor_Service $service = null ) {
		$this->service = $service ?: new AIMS_Role_Editor_Service();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( $this, 'handle_delete' ) );
	}

	public function handle_save(): void {
		$this->assert_can_manage_rbac();
		check_admin_referer( self::ACTION_SAVE );

		$result = $this->service->save_role( wp_unslash( $_POST ) );
		$this->redirect_with_notice(
			! empty( $result['success'] ) ? 'success' : 'error',
			(string) ( $result['message'] ?? 'Unable to save role.' ),
			sanitize_key( (string) ( $result['role']['role_slug'] ?? ( $_POST['role_slug'] ?? '' ) ) )
		);
	}

	public function handle_delete(): void {
		$this->assert_can_manage_rbac();
		check_admin_referer( self::ACTION_DELETE );

		$role_slug = isset( $_POST['role_slug'] ) ? sanitize_key( wp_unslash( $_POST['role_slug'] ) ) : '';
		$deleted   = $this->service->delete_role( $role_slug );

		$this->redirect_with_notice(
			$deleted ? 'success' : 'error',
			$deleted ? 'Role deleted.' : 'Role could not be deleted.'
		);
	}

	private function assert_can_manage_rbac(): void {
		if ( current_user_can( AIMS_Capabilities::CAP_MANAGE_RBAC ) || current_user_can( AIMS_Capabilities::CAP_MANAGE ) ) {
			return;
		}

		wp_die( esc_html__( 'You do not have permission to manage AIMS roles.', 'ai-man-sys' ) );
	}

	private function redirect_with_notice( string $status, string $message, string $role_slug = '' ): void {
		$args = array(
			'page'              => AIMS_Role_Editor_Page::PAGE_SLUG,
			'aims_role_status'  => sanitize_key( $status ),
			'aims_role_message' => $message,
		);

		if ( '' !== $role_slug ) {
			$args['role_slug'] = $role_slug;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
