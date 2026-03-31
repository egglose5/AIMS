<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Module implements AIMS_Module {
	private $vendor_service;
	private $responsibility_auth;
	private $vendor_checkin_portal_controller;
	private $vendor_portal_navigation_controller;
	private const ADMIN_PAGE = 'aims-vendors';

	public function __construct( AIMS_Vendor_Service $vendor_service, AIMS_Responsibility_Authorization_Service $responsibility_auth = null ) {
		$this->vendor_service = $vendor_service;
		$this->responsibility_auth = $responsibility_auth ?: ( class_exists( 'AIMS_Responsibility_Authorization_Service' ) ? new AIMS_Responsibility_Authorization_Service() : null );
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_public_hooks' ) );
		add_action( 'widgets_init', array( $this, 'register_widgets' ) );
		add_action( 'admin_post_aims_vendor_save', array( $this, 'handle_vendor_save' ) );
		add_action( 'admin_post_aims_vendor_archive', array( $this, 'handle_vendor_archive' ) );
	}

	public function register_public_hooks(): void {
		$this->get_vendor_checkin_portal_controller()->register();
		$this->get_vendor_portal_navigation_controller()->register();
	}

	public function register_widgets(): void {
		register_widget( 'AIMS_Vendor_Portal_Navigation_Widget' );
	}

	public function render_shell(): void {
		if ( ! $this->can_manage_vendors() ) {
			wp_die( esc_html__( 'You do not have permission to manage vendors.', 'ai-man-sys' ) );
		}

		$vendor_id     = isset( $_GET['vendor_id'] ) ? max( 0, (int) wp_unslash( $_GET['vendor_id'] ) ) : 0;
		$current_vendor = $vendor_id > 0 ? $this->vendor_service->get_vendor( $vendor_id ) : null;
		$vendors       = $this->vendor_service->list_vendors();

		echo '<div class="wrap">';
		echo '<h1>Vendor Manage</h1>';
		echo '<p>Create, edit, and archive vendors. Archived vendors are retained for attribution history and reporting.</p>';
		$this->render_status_notice();
		$this->render_vendor_form( $current_vendor );
		$this->render_vendor_table( $vendors );
		echo '</div>';
	}

	public function handle_vendor_save(): void {
		if ( ! $this->can_manage_vendors() ) {
			wp_die( esc_html__( 'You do not have permission to manage vendors.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_vendor_save' );

		$vendor_id = isset( $_POST['vendor_id'] ) ? max( 0, (int) wp_unslash( $_POST['vendor_id'] ) ) : 0;
		$data      = $this->collect_vendor_payload();

		if ( '' === $data['vendor_name'] ) {
			$this->redirect_with_message( 'error', 'Vendor name is required.', $vendor_id );
		}

		if ( $vendor_id > 0 ) {
			$this->vendor_service->update_vendor( $vendor_id, $data );
			$this->redirect_with_message( 'success', 'Vendor updated.', $vendor_id );
		}

		$new_vendor_id = $this->vendor_service->create_vendor( $data );
		$this->redirect_with_message( 'success', 'Vendor created.', $new_vendor_id );
	}

	public function handle_vendor_archive(): void {
		if ( ! $this->can_manage_vendors() ) {
			wp_die( esc_html__( 'You do not have permission to manage vendors.', 'ai-man-sys' ) );
		}

		check_admin_referer( 'aims_vendor_archive' );

		$vendor_id = isset( $_POST['vendor_id'] ) ? max( 0, (int) wp_unslash( $_POST['vendor_id'] ) ) : 0;
		if ( $vendor_id <= 0 ) {
			$this->redirect_with_message( 'error', 'Missing vendor id.' );
		}

		$archived = $this->vendor_service->archive_vendor( $vendor_id );
		$this->redirect_with_message(
			$archived ? 'success' : 'error',
			$archived ? 'Vendor archived.' : 'Unable to archive vendor.'
		);
	}

	private function get_vendor_checkin_portal_controller(): AIMS_Vendor_Event_Checkin_Portal_Controller {
		if ( null === $this->vendor_checkin_portal_controller ) {
			$this->vendor_checkin_portal_controller = new AIMS_Vendor_Event_Checkin_Portal_Controller();
		}

		return $this->vendor_checkin_portal_controller;
	}

	private function get_vendor_portal_navigation_controller(): AIMS_Vendor_Portal_Navigation_Controller {
		if ( null === $this->vendor_portal_navigation_controller ) {
			$this->vendor_portal_navigation_controller = new AIMS_Vendor_Portal_Navigation_Controller();
		}

		return $this->vendor_portal_navigation_controller;
	}

	private function render_status_notice(): void {
		$status  = isset( $_GET['aims_vendor_status'] ) ? sanitize_key( wp_unslash( $_GET['aims_vendor_status'] ) ) : '';
		$message = isset( $_GET['aims_vendor_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aims_vendor_message'] ) ) : '';

		if ( '' === $status || '' === $message ) {
			return;
		}

		$class = 'success' === $status ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_vendor_form( ?array $vendor ): void {
		$is_edit = is_array( $vendor );

		echo '<h2>' . esc_html( $is_edit ? 'Edit Vendor' : 'Add Vendor' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aims_vendor_save' );
		echo '<input type="hidden" name="action" value="aims_vendor_save" />';
		echo '<input type="hidden" name="vendor_id" value="' . esc_attr( (string) ( $vendor['id'] ?? 0 ) ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_input_row( 'vendor_name', 'Vendor Name', (string) ( $vendor['vendor_name'] ?? '' ), true );
		$this->render_text_input_row( 'vendor_code', 'Vendor Code', (string) ( $vendor['vendor_code'] ?? '' ), false );
		$this->render_text_input_row( 'square_location_id', 'Square Location ID', (string) ( $vendor['square_location_id'] ?? '' ), false );
		$this->render_text_input_row( 'square_team_member_id', 'Square Team Member ID', (string) ( $vendor['square_team_member_id'] ?? '' ), false );
		$this->render_text_input_row( 'default_bucket_code', 'Default Bucket Code', (string) ( $vendor['default_bucket_code'] ?? '' ), false );
		$this->render_text_input_row( 'commission_rate', 'Commission Rate', (string) ( $vendor['commission_rate'] ?? '0.0000' ), false );
		$this->render_text_input_row( 'email_address', 'Email', (string) ( $vendor['email_address'] ?? '' ), false );
		$this->render_text_input_row( 'phone_number', 'Phone', (string) ( $vendor['phone_number'] ?? '' ), false );
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $is_edit ? 'Update Vendor' : 'Create Vendor' ) . '</button>';
		if ( $is_edit ) {
			$cancel_url = add_query_arg( array( 'page' => self::ADMIN_PAGE ), admin_url( 'admin.php' ) );
			echo ' <a class="button" href="' . esc_url( $cancel_url ) . '">Cancel Edit</a>';
		}
		echo '</p>';
		echo '</form>';
	}

	private function render_vendor_table( array $vendors ): void {
		echo '<hr />';
		echo '<h2>Current Vendors</h2>';

		if ( empty( $vendors ) ) {
			echo '<div class="notice notice-info inline"><p>No vendors yet. Use the form above to create the first vendor.</p></div>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr><th>Name</th><th>Code</th><th>Status</th><th>Square Location</th><th>Team Member</th><th>Commission</th><th>Actions</th></tr></thead>';
		echo '<tbody>';
		foreach ( $vendors as $vendor ) {
			$edit_url = add_query_arg(
				array(
					'page'      => self::ADMIN_PAGE,
					'vendor_id' => (int) $vendor['id'],
				),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $vendor['vendor_name'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $vendor['vendor_code'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( ucfirst( (string) ( $vendor['status'] ?? 'active' ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $vendor['square_location_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $vendor['square_team_member_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $vendor['commission_rate'] ?? '0.0000' ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $edit_url ) . '">Edit</a>';

			if ( 'archived' !== (string) ( $vendor['status'] ?? '' ) ) {
				echo ' <form style="display:inline-block;" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Archive this vendor?\');">';
				wp_nonce_field( 'aims_vendor_archive' );
				echo '<input type="hidden" name="action" value="aims_vendor_archive" />';
				echo '<input type="hidden" name="vendor_id" value="' . esc_attr( (string) ( $vendor['id'] ?? 0 ) ) . '" />';
				echo '<button type="submit" class="button button-small">Archive</button></form>';
			}

			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_text_input_row( string $field, string $label, string $value, bool $required ): void {
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" class="regular-text" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ' /></td>';
		echo '</tr>';
	}

	private function collect_vendor_payload(): array {
		return array(
			'vendor_name'           => sanitize_text_field( wp_unslash( $_POST['vendor_name'] ?? '' ) ),
			'vendor_code'           => sanitize_key( wp_unslash( $_POST['vendor_code'] ?? '' ) ),
			'square_location_id'    => sanitize_text_field( wp_unslash( $_POST['square_location_id'] ?? '' ) ),
			'square_team_member_id' => sanitize_text_field( wp_unslash( $_POST['square_team_member_id'] ?? '' ) ),
			'default_bucket_code'   => sanitize_text_field( wp_unslash( $_POST['default_bucket_code'] ?? '' ) ),
			'commission_rate'       => (float) wp_unslash( $_POST['commission_rate'] ?? 0 ),
			'email_address'         => sanitize_email( wp_unslash( $_POST['email_address'] ?? '' ) ),
			'phone_number'          => sanitize_text_field( wp_unslash( $_POST['phone_number'] ?? '' ) ),
			'status'                => 'active',
		);
	}

	private function redirect_with_message( string $status, string $message, int $vendor_id = 0 ): void {
		$params = array(
			'page'                => self::ADMIN_PAGE,
			'aims_vendor_status'  => $status,
			'aims_vendor_message' => $message,
		);

		if ( $vendor_id > 0 && 'error' === $status ) {
			$params['vendor_id'] = $vendor_id;
		}

		$redirect = add_query_arg( $params, admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	private function can_manage_vendors(): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id > 0 && is_object( $this->responsibility_auth ) && method_exists( $this->responsibility_auth, 'can_manage_vendors' ) ) {
			if ( $this->responsibility_auth->can_manage_vendors( $user_id ) ) {
				return true;
			}
		}

		return current_user_can( AIMS_Capabilities::CAP_MANAGE_VENDORS );
	}
}

