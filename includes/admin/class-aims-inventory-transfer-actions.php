<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Transfer_Actions {
	private const ACTION_CREATE_DRAFT = 'aims_inventory_transfer_create_draft';
	private const ACTION_ADD_ITEM = 'aims_inventory_transfer_add_item';
	private const ACTION_DISPATCH = 'aims_inventory_transfer_dispatch';
	private const ACTION_CONFIRM_RECEIPT = 'aims_inventory_transfer_confirm_receipt';

	private const NONCE_CREATE_DRAFT = '_aims_inventory_transfer_create_draft_nonce';
	private const NONCE_ADD_ITEM = '_aims_inventory_transfer_add_item_nonce';
	private const NONCE_DISPATCH = '_aims_inventory_transfer_dispatch_nonce';
	private const NONCE_CONFIRM_RECEIPT = '_aims_inventory_transfer_confirm_receipt_nonce';

	private $service;
	private $authorization;

	public function __construct( AIMS_Inventory_Transfer_Service $service = null, AIMS_Inventory_Transfer_Authorization_Service $authorization = null ) {
		$this->authorization = $authorization ?: new AIMS_Inventory_Transfer_Authorization_Service();
		$this->service       = $service ?: new AIMS_Inventory_Transfer_Service( null, null, null, null, $this->authorization );
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_CREATE_DRAFT, array( $this, 'handle_create_draft' ) );
		add_action( 'admin_post_' . self::ACTION_ADD_ITEM, array( $this, 'handle_add_item' ) );
		add_action( 'admin_post_' . self::ACTION_DISPATCH, array( $this, 'handle_dispatch' ) );
		add_action( 'admin_post_' . self::ACTION_CONFIRM_RECEIPT, array( $this, 'handle_confirm_receipt' ) );
	}

	public function handle_create_draft(): void {
		if ( ! $this->can_manage_inventory() ) {
			wp_die( esc_html__( 'You do not have permission to manage inventory transfers.', 'ai-man-sys' ) );
		}

		check_admin_referer( self::ACTION_CREATE_DRAFT, self::NONCE_CREATE_DRAFT );

		$source_selection = $this->parse_endpoint_selection( sanitize_text_field( $_POST['source_endpoint_selection'] ?? '' ) );
		$target_selection = $this->parse_endpoint_selection( sanitize_text_field( $_POST['target_endpoint_selection'] ?? '' ) );
		$source_node_id = (int) ( $source_selection['node_id'] ?? ( $_POST['source_node_id'] ?? ( $_POST['source_vendor_id'] ?? 0 ) ) );
		$target_node_id = (int) ( $target_selection['node_id'] ?? ( $_POST['target_node_id'] ?? ( $_POST['target_vendor_id'] ?? 0 ) ) );

		$result = $this->service->create_draft(
			$source_node_id,
			$target_node_id,
			array(
				'source_node_type' => sanitize_key( (string) ( $source_selection['node_type'] ?? ( $_POST['source_node_type'] ?? 'vendor' ) ) ),
				'target_node_type' => sanitize_key( (string) ( $target_selection['node_type'] ?? ( $_POST['target_node_type'] ?? 'vendor' ) ) ),
				'transfer_type'    => sanitize_text_field( $_POST['transfer_type'] ?? 'standard' ),
				'override_route'   => ! empty( $_POST['override_route'] ),
				'override_reason'  => isset( $_POST['override_reason'] ) ? sanitize_textarea_field( $_POST['override_reason'] ) : null,
				'route_guidance'   => isset( $_POST['route_guidance'] ) ? sanitize_text_field( $_POST['route_guidance'] ) : null,
				'initiated_by'     => get_current_user_id(),
				'reference_type'   => sanitize_key( $_POST['reference_type'] ?? '' ),
				'reference_id'     => sanitize_text_field( $_POST['reference_id'] ?? '' ),
				'notes'            => isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : null,
			)
		);

		$this->redirect_with_status( $result, 'aims-inventory', array(
			'transfer_id' => $result['transfer_id'] ?? null,
		) );
	}

	public function handle_add_item(): void {
		if ( ! $this->can_manage_inventory() ) {
			wp_die( esc_html__( 'You do not have permission to manage inventory transfers.', 'ai-man-sys' ) );
		}

		check_admin_referer( self::ACTION_ADD_ITEM, self::NONCE_ADD_ITEM );

		$transfer_id      = (int) ( $_POST['transfer_id'] ?? 0 );
		$product_input    = sanitize_text_field( $_POST['product_input'] ?? '' );
		$source_bucket_id = (int) ( $_POST['source_bucket_id'] ?? 0 );
		$target_bucket_id = (int) ( $_POST['target_bucket_id'] ?? 0 );
		$quantity         = (float) ( $_POST['quantity'] ?? 0 );

		// Try WC product lookup first (for SKU or product ID input)
		$result = $this->service->add_item_via_wc_product(
			$transfer_id,
			$product_input,
			$source_bucket_id,
			$target_bucket_id,
			$quantity,
			array(
				'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : null,
			)
		);

		$this->redirect_with_status( $result, 'aims-inventory', array(
			'transfer_id' => $transfer_id,
			'item_id'     => $result['item_id'] ?? null,
		) );
	}

	public function handle_dispatch(): void {
		if ( ! $this->can_manage_inventory() ) {
			wp_die( esc_html__( 'You do not have permission to manage inventory transfers.', 'ai-man-sys' ) );
		}

		check_admin_referer( self::ACTION_DISPATCH, self::NONCE_DISPATCH );

		$transfer_id = (int) ( $_POST['transfer_id'] ?? 0 );

		$result = $this->service->dispatch_transfer(
			$transfer_id,
			array(
				'user_id'        => get_current_user_id(),
				'notes'          => isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : null,
				'audit_reason'   => isset( $_POST['audit_reason'] ) ? sanitize_textarea_field( $_POST['audit_reason'] ) : null,
				'route_guidance' => isset( $_POST['route_guidance'] ) ? sanitize_text_field( $_POST['route_guidance'] ) : null,
				'override_route' => ! empty( $_POST['override_route'] ),
			)
		);

		$this->redirect_with_status( $result, 'aims-inventory', array(
			'transfer_id' => $transfer_id,
		) );
	}

	public function handle_confirm_receipt(): void {
		if ( ! $this->can_manage_inventory() ) {
			wp_die( esc_html__( 'You do not have permission to manage inventory transfers.', 'ai-man-sys' ) );
		}

		check_admin_referer( self::ACTION_CONFIRM_RECEIPT, self::NONCE_CONFIRM_RECEIPT );

		$transfer_id = (int) ( $_POST['transfer_id'] ?? 0 );

		// Parse item receipts from posted data (item_{ item_id }_qty format)
		$item_receipts = array();
		if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			foreach ( $_POST['items'] as $item_id => $item_data ) {
				$item_id_int = (int) $item_id;
				if ( $item_id_int > 0 && isset( $item_data['received_quantity'] ) ) {
					$item_receipts[ $item_id_int ] = (float) $item_data['received_quantity'];
				}
			}
		}

		$result = $this->service->confirm_receipt(
			$transfer_id,
			$item_receipts,
			array(
				'user_id'        => get_current_user_id(),
				'notes'          => isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : null,
				'audit_reason'   => isset( $_POST['audit_reason'] ) ? sanitize_textarea_field( $_POST['audit_reason'] ) : null,
				'route_guidance' => isset( $_POST['route_guidance'] ) ? sanitize_text_field( $_POST['route_guidance'] ) : null,
				'override_route' => ! empty( $_POST['override_route'] ),
			)
		);

		$this->redirect_with_status( $result, 'aims-inventory', array(
			'transfer_id' => $transfer_id,
		) );
	}

	private function can_manage_inventory(): bool {
		return $this->authorization->can_manage_inventory_transfers( get_current_user_id() );
	}

	private function redirect_with_status( array $result, string $page, array $query_args = array() ): void {
		$redirect_args = array( 'page' => $page );

		if ( isset( $result['success'] ) && $result['success'] ) {
			$redirect_args['aims_status'] = 'success';
			$redirect_args['aims_message'] = $result['message'] ?? 'Operation completed.';
		} else {
			$redirect_args['aims_status'] = 'error';
			$redirect_args['aims_message'] = $result['message'] ?? 'An error occurred.';
		}

		// Merge additional query args
		$redirect_args = array_merge( $redirect_args, $query_args );

		$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	private function parse_endpoint_selection( string $selection ): array {
		$selection = trim( $selection );
		if ( '' === $selection || false === strpos( $selection, ':' ) ) {
			return array();
		}

		list( $node_type, $node_id ) = array_pad( explode( ':', $selection, 2 ), 2, '' );

		return array(
			'node_type' => sanitize_key( $node_type ),
			'node_id'   => max( 0, (int) $node_id ),
		);
	}
}
