<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Demand_Request_Persistence_Gateway {
	private $requests;
	private $items;

	public function __construct(
		AIMS_Event_Customer_Request_Repository $requests,
		AIMS_Event_Customer_Request_Item_Repository $items
	) {
		$this->requests = $requests;
		$this->items    = $items;
	}

	public function save( array $record ): int {
		$wp_user_id = get_current_user_id();
		$notes      = $this->build_snapshot_note( $record, $wp_user_id );

		$request_id = $this->requests->save(
			array(
				'event_id'       => (int) ( $record['event_id'] ?? 0 ),
				'wp_user_id'     => $wp_user_id,
				'user_id'        => $wp_user_id,
				'vendor_id'      => (int) ( $record['vendor_id'] ?? 0 ),
				'customer_id'    => (int) ( $record['customer_id'] ?? 0 ),
				'customer_name'  => sanitize_text_field( $record['customer_name'] ?? '' ),
				'customer_email' => sanitize_email( $record['customer_email'] ?? '' ),
				'customer_phone' => sanitize_text_field( $record['customer_phone'] ?? '' ),
				'request_source' => sanitize_key( $record['request_source'] ?? 'public_event_demand_form' ),
				'request_status' => sanitize_key( $record['request_status'] ?? AIMS_Event_Customer_Request_Repository::STATUS_PLANNED ),
				'requested_at'   => sanitize_text_field( $record['submitted_at'] ?? current_time( 'mysql' ) ),
				'approved_at'    => sanitize_text_field( $record['submitted_at'] ?? current_time( 'mysql' ) ),
				'notes'          => $notes,
			)
		);

		if ( $request_id <= 0 ) {
			return 0;
		}

		$item_id = $this->items->save(
			array(
				'request_id'     => $request_id,
				'event_id'       => (int) ( $record['event_id'] ?? 0 ),
				'vendor_id'      => (int) ( $record['vendor_id'] ?? 0 ),
				'woo_product_id' => (int) ( $record['woo_product_id'] ?? 0 ),
				'product_sku'    => sanitize_text_field( $record['product_sku'] ?? '' ),
				'product_name'   => sanitize_text_field( $record['product_name'] ?? '' ),
				'quantity'       => (float) ( $record['quantity'] ?? 0 ),
				'item_status'    => AIMS_Event_Customer_Request_Item_Repository::STATUS_PLANNED,
				'notes'          => $notes,
			)
		);

		if ( $item_id <= 0 ) {
			$this->requests->update_status( $request_id, AIMS_Event_Customer_Request_Repository::STATUS_ARCHIVED );
			return 0;
		}

		return $request_id;
	}

	private function build_snapshot_note( array $record, int $wp_user_id ): string {
		$user_note = sprintf(
			'WP user snapshot: id=%d; name=%s; email=%s; phone=%s',
			$wp_user_id,
			sanitize_text_field( (string) ( $record['customer_name'] ?? '' ) ),
			sanitize_email( (string) ( $record['customer_email'] ?? '' ) ),
			sanitize_text_field( (string) ( $record['customer_phone'] ?? '' ) )
		);

		$request_notes = isset( $record['request_notes'] ) ? trim( wp_kses_post( (string) $record['request_notes'] ) ) : '';

		if ( '' === $request_notes ) {
			return $user_note;
		}

		return $request_notes . "\n\n" . $user_note;
	}
}

class AIMS_Event_Demand_Intake_Controller {
	private const SHORTCODE = 'aims_event_demand_form';
	private const ACTION    = 'aims_event_demand_submit';
	private const FLASH_KEY = 'aims_event_demand_flash_';

	private $is_rendering_shortcode = false;
	private $intake_service;

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_filter( 'body_class', array( $this, 'filter_body_class' ) );
		add_filter( 'ocean_display_header', array( $this, 'filter_ocean_display_header' ) );
		add_filter( 'ocean_display_footer_widgets', array( $this, 'filter_ocean_display_footer_widgets' ) );
		add_filter( 'ocean_display_footer_bottom', array( $this, 'filter_ocean_display_footer_bottom' ) );
	}

	public function render_shortcode( array $atts = array() ): string {
		$this->is_rendering_shortcode = true;

		$atts              = shortcode_atts(
			array(
				'event_id'        => 0,
				'title'           => 'Event Demand Request',
				'description'     => 'Submit planning demand for a specific event. This form is planning-only and does not create a payment, reservation, or Square order.',
				'button_label'    => 'Submit Demand Request',
				'disable_chrome'  => '0',
			),
			$atts,
			self::SHORTCODE
		);
		$event_id          = max( 0, (int) $atts['event_id'] );
		$products          = $this->get_available_products();
		$status_message    = $this->get_status_message();
		$confirmation_data = $this->get_confirmation_data();
		$action_url        = admin_url( 'admin-post.php' );
		$return_url        = $this->get_current_url();
		$disable_chrome    = '1' === (string) $atts['disable_chrome'];
		$is_logged_in      = is_user_logged_in();
		$login_url         = wp_login_url( $return_url );
		$user_snapshot     = $this->get_current_user_snapshot();

		ob_start();

		$template_path = AIMS_PLUGIN_PATH . 'templates/event-demand-intake-form.php';

		if ( file_exists( $template_path ) ) {
			$title              = (string) $atts['title'];
			$description        = (string) $atts['description'];
			$button_label       = (string) $atts['button_label'];
			$public_event_id    = $event_id;
			$public_products    = $products;
			$public_action_url  = $action_url;
			$public_return_url  = $return_url;
			$public_status      = $status_message;
			$public_confirmed   = $confirmation_data;
			$public_disable_chrome = $disable_chrome;
			$public_is_logged_in   = $is_logged_in;
			$public_login_url      = $login_url;
			$public_user_snapshot  = $user_snapshot;

			include $template_path;
		} else {
			echo '<div class="aims-event-demand-intake">';
			echo '<p>' . esc_html__( 'Event demand intake template is unavailable.', 'ai-man-sys' ) . '</p>';
			echo '</div>';
		}

		$output = (string) ob_get_clean();

		$this->is_rendering_shortcode = false;

		return $output;
	}

	public function handle_submission(): void {
		$event_id      = isset( $_POST['event_id'] ) ? max( 0, (int) wp_unslash( $_POST['event_id'] ) ) : 0;
		$woo_product_id = isset( $_POST['woo_product_id'] ) ? max( 0, (int) wp_unslash( $_POST['woo_product_id'] ) ) : 0;
		$quantity      = isset( $_POST['quantity_requested'] ) ? (float) wp_unslash( $_POST['quantity_requested'] ) : 0.0;
		$return_url    = isset( $_POST['_aims_return_url'] ) ? esc_url_raw( wp_unslash( $_POST['_aims_return_url'] ) ) : home_url( '/' );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $return_url ) );
			exit;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_aims_event_demand_nonce'] ?? '' ) ), 'aims_event_demand_submit' ) ) {
			$this->redirect_with_status( $return_url, 'error', 'Request verification failed.' );
		}

		if ( $event_id <= 0 ) {
			$this->redirect_with_status( $return_url, 'error', 'A valid event_id is required.' );
		}

		if ( $woo_product_id <= 0 || $quantity <= 0 ) {
			$this->redirect_with_status( $return_url, 'error', 'A physical Woo product and requested quantity are required.' );
		}

		$product = $this->resolve_woo_product( $woo_product_id );

		if ( null === $product ) {
			$this->redirect_with_status( $return_url, 'error', 'The selected Woo product is unavailable for event demand intake.' );
		}

		$product_sku = sanitize_text_field( (string) $product->get_sku() );

		if ( '' === $product_sku ) {
			$this->redirect_with_status( $return_url, 'error', 'The selected Woo product must have a SKU because AIMS uses SKU as the operational product identifier.' );
		}

		$user_snapshot = $this->get_current_user_snapshot();

		$submission = array(
			'event_id'               => $event_id,
			'woo_product_id'         => $woo_product_id,
			'product_sku'            => $product_sku,
			'product_name'           => sanitize_text_field( $product->get_name() ),
			'quantity_requested'     => round( $quantity, 4 ),
			'quantity'               => round( $quantity, 4 ),
			'customer_name'          => $user_snapshot['customer_name'],
			'customer_email'         => $user_snapshot['customer_email'],
			'customer_phone'         => '' !== $user_snapshot['customer_phone']
				? $user_snapshot['customer_phone']
				: sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) ),
			'wp_user_id'             => get_current_user_id(),
			'user_id'                => get_current_user_id(),
			'customer_id'            => 0,
			'request_notes'          => sanitize_textarea_field( wp_unslash( $_POST['request_notes'] ?? '' ) ),
			'notes'                  => sanitize_textarea_field( wp_unslash( $_POST['request_notes'] ?? '' ) ),
			'intake_status'          => 'auto_approved',
			'demand_signal_type'     => 'planning_only',
			'payment_status'         => 'not_applicable',
			'reservation_status'     => 'not_reserved',
			'square_order_creation'  => 'disabled',
			'submitted_at'           => current_time( 'mysql' ),
			'request_source'         => 'public_event_demand_form',
			'request_status'         => AIMS_Event_Customer_Request_Repository::STATUS_PLANNED,
			'approval_mode'          => 'auto_planning_signal',
			'source'                 => 'public_event_demand_form',
		);

		$intake_result = $this->get_intake_service()->intake_request( $submission );

		if ( is_wp_error( $intake_result ) ) {
			$this->redirect_with_status( $return_url, 'error', $intake_result->get_error_message() );
		}

		$request_id = (int) ( $intake_result['request_id'] ?? 0 );

		if ( ! $request_id || empty( $intake_result['persisted'] ) ) {
			$this->redirect_with_status( $return_url, 'error', 'Demand request could not be persisted.' );
		}

		do_action(
			'aims_event_demand_submission_received',
			$submission,
			array(
				'controller'        => __CLASS__,
				'entry_point'       => self::SHORTCODE,
				'intake_result'     => $intake_result,
				'request_id'        => $request_id,
				'return_url'        => $return_url,
				'disable_theme_chrome' => $this->should_disable_theme_chrome(),
			)
		);

		$token = wp_generate_password( 12, false, false );

		set_transient(
			self::FLASH_KEY . $token,
			array(
				'request_id'         => $request_id,
				'event_id'           => $submission['event_id'],
				'woo_product_id'     => $submission['woo_product_id'],
				'product_sku'        => $submission['product_sku'],
				'product_name'       => $submission['product_name'],
				'quantity_requested' => $submission['quantity_requested'],
				'intake_status'      => $submission['intake_status'],
			),
			10 * MINUTE_IN_SECONDS
		);

		$this->redirect_with_status( $return_url, 'success', 'Demand request submitted as an auto-approved planning signal.', $token );
	}

	public function filter_body_class( array $classes ): array {
		if ( ! $this->is_event_demand_context() ) {
			return $classes;
		}

		$classes[] = 'aims-event-demand-intake';

		if ( $this->should_disable_theme_chrome() ) {
			$classes[] = 'aims-event-demand-no-theme-chrome';
		}

		return array_unique( $classes );
	}

	public function filter_ocean_display_header( $display ) {
		return $this->should_disable_theme_chrome() ? false : $display;
	}

	public function filter_ocean_display_footer_widgets( $display ) {
		return $this->should_disable_theme_chrome() ? false : $display;
	}

	public function filter_ocean_display_footer_bottom( $display ) {
		return $this->should_disable_theme_chrome() ? false : $display;
	}

	private function get_available_products(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$results  = array();
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 100,
				'return' => 'objects',
			)
		);

		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}

			if ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
				continue;
			}

			$results[] = array(
				'woo_product_id' => (int) $product->get_id(),
				'product_sku'    => sanitize_text_field( (string) $product->get_sku() ),
				'product_name'   => sanitize_text_field( $product->get_name() ),
			);
		}

		usort(
			$results,
			static function ( array $left, array $right ): int {
				return strcasecmp( $left['product_name'], $right['product_name'] );
			}
		);

		return $results;
	}

	private function resolve_woo_product( int $woo_product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $woo_product_id );

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}

		if ( method_exists( $product, 'get_status' ) && 'publish' !== $product->get_status() ) {
			return null;
		}

		if ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
			return null;
		}

		return $product;
	}

	private function get_current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		return esc_url_raw( $scheme . $host . $uri );
	}

	private function get_status_message(): array {
		$status  = sanitize_key( wp_unslash( $_GET['aims_event_demand_status'] ?? '' ) );
		$message = sanitize_text_field( wp_unslash( $_GET['aims_event_demand_message'] ?? '' ) );

		if ( '' === $status || '' === $message ) {
			return array();
		}

		return array(
			'status'  => $status,
			'message' => $message,
		);
	}

	private function get_confirmation_data(): array {
		$reference = sanitize_key( wp_unslash( $_GET['aims_event_demand_ref'] ?? '' ) );

		if ( '' === $reference ) {
			return array();
		}

		$data = get_transient( self::FLASH_KEY . $reference );

		if ( ! is_array( $data ) ) {
			return array();
		}

		delete_transient( self::FLASH_KEY . $reference );

		return $data;
	}

	private function redirect_with_status( string $return_url, string $status, string $message, string $reference = '' ): void {
		$return_url = '' !== $return_url ? $return_url : home_url( '/' );
		$return_url = add_query_arg(
			array(
				'aims_event_demand_status'  => sanitize_key( $status ),
				'aims_event_demand_message' => $message,
				'aims_event_demand_ref'     => sanitize_key( $reference ),
			),
			$return_url
		);

		wp_safe_redirect( $return_url );
		exit;
	}

	private function is_event_demand_context(): bool {
		return $this->is_rendering_shortcode || $this->should_disable_theme_chrome();
	}

	private function should_disable_theme_chrome(): bool {
		$requested = isset( $_REQUEST['aims_event_demand_chrome'] ) ? sanitize_key( wp_unslash( $_REQUEST['aims_event_demand_chrome'] ) ) : '';

		return (bool) apply_filters( 'aims_event_demand_disable_theme_chrome', 'minimal' === $requested || 'none' === $requested );
	}

	private function get_current_user_snapshot(): array {
		if ( ! is_user_logged_in() ) {
			return array(
				'customer_name'  => '',
				'customer_email' => '',
				'customer_phone' => '',
			);
		}

		$user         = wp_get_current_user();
		$display_name = trim( (string) $user->display_name );

		if ( '' === $display_name ) {
			$display_name = trim(
				sanitize_text_field( (string) get_user_meta( $user->ID, 'first_name', true ) ) . ' ' .
				sanitize_text_field( (string) get_user_meta( $user->ID, 'last_name', true ) )
			);
		}

		return array(
			'customer_name'  => sanitize_text_field( $display_name ),
			'customer_email' => sanitize_email( (string) $user->user_email ),
			'customer_phone' => sanitize_text_field( (string) get_user_meta( $user->ID, 'billing_phone', true ) ),
		);
	}

	private function get_intake_service(): AIMS_Event_Demand_Intake_Service {
		if ( null === $this->intake_service ) {
			$this->intake_service = new AIMS_Event_Demand_Intake_Service(
				new AIMS_Event_Repository(),
				new AIMS_Event_Demand_Request_Persistence_Gateway(
					new AIMS_Event_Customer_Request_Repository(),
					new AIMS_Event_Customer_Request_Item_Repository()
				)
			);
		}

		return $this->intake_service;
	}
}
