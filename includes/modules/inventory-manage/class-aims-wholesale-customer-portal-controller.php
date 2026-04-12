<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Wholesale_Customer_Portal_Controller {
	private const SHORTCODE = 'aims_wholesale_portal';
	private const ENDPOINT = 'aims-wholesale';
	private const ACTION_REORDER = 'aims_wholesale_reorder';

	private $contract_service;

	public function __construct( AIMS_Wholesale_Contract_Service $contract_service = null ) {
		$this->contract_service = $contract_service ?: new AIMS_Wholesale_Contract_Service();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'register_menu_item' ), 40, 1 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_account_endpoint' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_minimum_order_quantity' ), 20, 5 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_wholesale_tier_pricing' ), 20, 1 );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_contract_notice' ) );
		add_filter( 'woocommerce_get_item_data', array( $this, 'append_line_discount_context' ), 20, 2 );
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'render_wholesale_savings_row' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_wholesale_savings_row' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_order_line_item_contract_meta' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist_order_contract_meta' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_contract_summary' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_' . self::ACTION_REORDER, array( $this, 'handle_reorder' ) );
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	public function register_endpoint(): void {
		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			$mask = ( defined( 'EP_ROOT' ) ? (int) EP_ROOT : 0 ) | ( defined( 'EP_PAGES' ) ? (int) EP_PAGES : 0 );
			add_rewrite_endpoint( self::ENDPOINT, $mask > 0 ? $mask : 1 );
		}
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return array_values( array_unique( $vars ) );
	}

	public function register_menu_item( array $items ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! $this->contract_service->is_wholesale_customer( $user_id ) ) {
			return $items;
		}

		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}

		$items[ self::ENDPOINT ] = 'Wholesale Portal';

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	public function render_account_endpoint(): void {
		echo $this->render_portal_markup();
	}

	public function render_shortcode(): string {
		return $this->render_portal_markup();
	}

	public function apply_wholesale_tier_pricing( $cart ): void {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		if ( function_exists( 'is_admin' ) && is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! $this->contract_service->is_wholesale_customer( $user_id ) ) {
			return;
		}

		$contract   = $this->contract_service->get_contract( $user_id );
		$tier_rates = (array) ( $contract['tier_rates'] ?? array() );

		foreach ( (array) $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! method_exists( $product, 'get_price' ) || ! method_exists( $product, 'set_price' ) ) {
				continue;
			}

			$quantity = max( 0, (int) ( $cart_item['quantity'] ?? 0 ) );
			if ( $quantity <= 0 ) {
				continue;
			}

			$base_price = isset( $cart_item['aims_wholesale_base_price'] )
				? (float) $cart_item['aims_wholesale_base_price']
				: (float) $product->get_price();

			$discount_percent = $this->contract_service->resolve_discount_for_quantity( $tier_rates, $quantity );
			$multiplier       = max( 0, 1 - ( $discount_percent / 100 ) );
			$discounted_price = round( $base_price * $multiplier, 4 );
			$line_savings     = round( max( 0, $base_price - $discounted_price ) * $quantity, 4 );

			$product->set_price( $discounted_price );

			if ( isset( $cart->cart_contents[ $cart_item_key ] ) && is_array( $cart->cart_contents[ $cart_item_key ] ) ) {
				$cart->cart_contents[ $cart_item_key ]['aims_wholesale_base_price'] = $base_price;
				$cart->cart_contents[ $cart_item_key ]['aims_wholesale_discount_percent'] = $discount_percent;
				$cart->cart_contents[ $cart_item_key ]['aims_wholesale_line_savings'] = $line_savings;
			}
		}
	}

	public function validate_minimum_order_quantity( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $passed ) {
			return $passed;
		}

		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return $passed;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! $this->contract_service->is_wholesale_customer( $user_id ) ) {
			return $passed;
		}

		$minimum_qty = (int) ( $this->contract_service->get_contract( $user_id )['min_order_qty'] ?? 1 );
		$existing_quantity = $this->get_existing_cart_quantity( (int) $product_id, (int) $variation_id );
		$effective_quantity = max( 0, (int) $quantity ) + $existing_quantity;

		if ( $effective_quantity >= $minimum_qty ) {
			return $passed;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( sprintf( 'Wholesale minimum quantity for this account is %d unit(s). Current cart amount for this item would be %d.', $minimum_qty, $effective_quantity ), 'error' );
		}

		return false;
	}

	public function append_line_discount_context( array $item_data, array $cart_item ): array {
		$discount_percent = isset( $cart_item['aims_wholesale_discount_percent'] ) ? (float) $cart_item['aims_wholesale_discount_percent'] : 0.0;
		$base_price       = isset( $cart_item['aims_wholesale_base_price'] ) ? (float) $cart_item['aims_wholesale_base_price'] : 0.0;
		$line_savings     = isset( $cart_item['aims_wholesale_line_savings'] ) ? (float) $cart_item['aims_wholesale_line_savings'] : 0.0;

		if ( $discount_percent <= 0 && $line_savings <= 0 ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'   => 'Wholesale Discount',
			'value' => number_format( $discount_percent, 2, '.', '' ) . '%',
		);

		if ( $base_price > 0 ) {
			$item_data[] = array(
				'key'   => 'Wholesale Base Unit Price',
				'value' => function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $base_price ) ) : '$' . number_format( $base_price, 2, '.', ',' ),
			);
		}

		if ( $line_savings > 0 ) {
			$item_data[] = array(
				'key'   => 'Wholesale Line Savings',
				'value' => function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $line_savings ) ) : '$' . number_format( $line_savings, 2, '.', ',' ),
			);
		}

		return $item_data;
	}

	public function render_wholesale_savings_row(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! is_object( WC()->cart ) || ! method_exists( WC()->cart, 'get_cart' ) ) {
			return;
		}

		$savings = 0.0;
		foreach ( (array) WC()->cart->get_cart() as $cart_item ) {
			$savings += isset( $cart_item['aims_wholesale_line_savings'] ) ? (float) $cart_item['aims_wholesale_line_savings'] : 0.0;
		}

		if ( $savings <= 0 ) {
			return;
		}

		$label = 'Wholesale Savings';
		$value = function_exists( 'wc_price' ) ? wc_price( $savings ) : '$' . number_format( $savings, 2, '.', ',' );

		echo '<tr class="aims-wholesale-savings">';
		echo '<th>' . esc_html( $label ) . '</th>';
		echo '<td data-title="' . esc_attr( $label ) . '"><strong>- ' . wp_kses_post( $value ) . '</strong></td>';
		echo '</tr>';
	}

	public function render_checkout_contract_notice(): void {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! $this->contract_service->is_wholesale_customer( $user_id ) ) {
			return;
		}

		$contract = $this->contract_service->get_contract( $user_id );
		$lead_time_days = (int) ( $contract['lead_time_days'] ?? 0 );
		$payment_terms = sanitize_text_field( (string) ( $contract['payment_terms'] ?? '' ) );
		$shipping_window = sanitize_text_field( (string) ( $contract['shipping_window'] ?? '' ) );

		echo '<div class="woocommerce-info aims-wholesale-checkout-note" style="margin:12px 0;">';
		echo '<strong>' . esc_html__( 'Wholesale Contract Applied', 'ai-man-sys' ) . '</strong><br />';
		echo esc_html( sprintf( 'Lead time: %d day(s)', $lead_time_days ) );
		if ( '' !== $payment_terms ) {
			echo ' | ' . esc_html( sprintf( 'Payment terms: %s', $payment_terms ) );
		}
		if ( '' !== $shipping_window ) {
			echo ' | ' . esc_html( sprintf( 'Shipping window: %s', $shipping_window ) );
		}
		echo '</div>';
	}

	public function persist_order_line_item_contract_meta( $item, $cart_item_key, $values, $order ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! $this->contract_service->is_wholesale_customer( $user_id ) ) {
			return;
		}

		$base_price = is_array( $values ) && isset( $values['aims_wholesale_base_price'] ) ? (float) $values['aims_wholesale_base_price'] : 0.0;
		$discount_percent = is_array( $values ) && isset( $values['aims_wholesale_discount_percent'] ) ? (float) $values['aims_wholesale_discount_percent'] : 0.0;

		$item->add_meta_data( '_aims_wholesale_base_unit_price', round( $base_price, 4 ), true );
		$item->add_meta_data( '_aims_wholesale_discount_percent', round( $discount_percent, 4 ), true );
	}

	public function persist_order_contract_meta( $order, $data ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! $this->contract_service->is_wholesale_customer( $user_id ) ) {
			return;
		}

		$contract = $this->contract_service->get_contract( $user_id );
		$snapshot = array(
			'lead_time_days'  => (int) ( $contract['lead_time_days'] ?? 0 ),
			'min_order_qty'   => (int) ( $contract['min_order_qty'] ?? 1 ),
			'payment_terms'   => (string) ( $contract['payment_terms'] ?? '' ),
			'shipping_window' => (string) ( $contract['shipping_window'] ?? '' ),
			'tier_rates'      => (array) ( $contract['tier_rates'] ?? array() ),
		);

		$serialized_snapshot = function_exists( 'wp_json_encode' )
			? (string) wp_json_encode( $snapshot )
			: (string) json_encode( $snapshot );

		$order->update_meta_data( '_aims_wholesale_contract_applied', '1' );
		$order->update_meta_data( '_aims_wholesale_lead_time_days', $snapshot['lead_time_days'] );
		$order->update_meta_data( '_aims_wholesale_min_order_qty', $snapshot['min_order_qty'] );
		$order->update_meta_data( '_aims_wholesale_payment_terms', $snapshot['payment_terms'] );
		$order->update_meta_data( '_aims_wholesale_shipping_window', $snapshot['shipping_window'] );
		$order->update_meta_data( '_aims_wholesale_contract_snapshot', $serialized_snapshot );

		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'AIMS wholesale contract terms applied during checkout.' );
		}
	}

	public function render_order_contract_summary( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}

		$applied = (string) $order->get_meta( '_aims_wholesale_contract_applied', true );
		if ( '1' !== $applied ) {
			return;
		}

		$lead_time_days  = (int) $order->get_meta( '_aims_wholesale_lead_time_days', true );
		$min_order_qty   = (int) $order->get_meta( '_aims_wholesale_min_order_qty', true );
		$payment_terms   = sanitize_text_field( (string) $order->get_meta( '_aims_wholesale_payment_terms', true ) );
		$shipping_window = sanitize_text_field( (string) $order->get_meta( '_aims_wholesale_shipping_window', true ) );

		echo '<section class="aims-wholesale-order-summary" style="margin:16px 0;padding:12px;border:1px solid #dcdcde;border-radius:6px;">';
		echo '<h3 style="margin:0 0 6px;">' . esc_html__( 'AIMS Wholesale Contract', 'ai-man-sys' ) . '</h3>';
		echo '<p style="margin:0;">' . esc_html( sprintf( 'Lead time: %d day(s) | Min reorder quantity: %d', $lead_time_days, $min_order_qty ) ) . '</p>';
		if ( '' !== $payment_terms || '' !== $shipping_window ) {
			echo '<p style="margin:6px 0 0;">';
			if ( '' !== $payment_terms ) {
				echo esc_html( sprintf( 'Payment terms: %s', $payment_terms ) );
			}
			if ( '' !== $shipping_window ) {
				echo ( '' !== $payment_terms ? ' | ' : '' ) . esc_html( sprintf( 'Shipping window: %s', $shipping_window ) );
			}
			echo '</p>';
		}
		echo '</section>';
	}

	public function handle_reorder(): void {
		$redirect = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( function_exists( 'wp_login_url' ) ? wp_login_url( $redirect ) : $redirect );
			exit;
		}

		$user_id = (int) get_current_user_id();
		if ( ! $this->contract_service->is_wholesale_customer( $user_id ) ) {
			$this->add_wc_notice( 'Wholesale contract access is required for reorder.', 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_aims_wholesale_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, self::ACTION_REORDER ) ) {
			$this->add_wc_notice( 'Wholesale reorder verification failed.', 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( $order_id <= 0 ) {
			$this->add_wc_notice( 'A reorder source order is required.', 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			$this->add_wc_notice( 'WooCommerce is unavailable for reorder.', 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_customer_id() !== $user_id ) {
			$this->add_wc_notice( 'The selected order is not available for reorder.', 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! is_object( WC()->cart ) ) {
			$this->add_wc_notice( 'Cart is unavailable. Try again after refreshing.', 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		WC()->cart->empty_cart();
		$added = 0;
		$minimum_qty = (int) ( $this->contract_service->get_contract( $user_id )['min_order_qty'] ?? 1 );

		foreach ( (array) $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			if ( $product_id <= 0 ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );
			$added_item_key = WC()->cart->add_to_cart( $product_id, $quantity );
			if ( $added_item_key ) {
				++$added;
			}
		}

		if ( $added <= 0 ) {
			$this->add_wc_notice( 'No order lines could be re-added to cart.', 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$this->add_wc_notice( sprintf( 'Wholesale reorder ready: %d line(s) added to cart.', $added ), 'success' );
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $redirect;
		wp_safe_redirect( $checkout );
		exit;
	}

	public function render_profile_fields( $user ): void {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		if ( $user_id <= 0 || ! $this->can_edit_user_profile( $user_id ) ) {
			return;
		}

		$contract = $this->contract_service->get_contract( $user_id );
		$tier_raw = (string) get_user_meta( $user_id, AIMS_Wholesale_Contract_Service::META_TIER_RATES, true );
		$enabled_checked = ! empty( $contract['enabled'] ) ? ' checked="checked"' : '';
		$elevated_checked = ! empty( $contract['elevated_customer'] ) ? ' checked="checked"' : '';
		?>
		<h2><?php esc_html_e( 'AIMS Wholesale Contract', 'ai-man-sys' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_ENABLED ); ?>"><?php esc_html_e( 'Enable Wholesale Contract', 'ai-man-sys' ); ?></label></th>
				<td>
					<label><input type="checkbox" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_ENABLED ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_ENABLED ); ?>" value="1"<?php echo $enabled_checked; ?> /> <?php esc_html_e( 'Treat as wholesale account', 'ai-man-sys' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_ELEVATED_CUSTOMER ); ?>"><?php esc_html_e( 'Elevated WC Customer', 'ai-man-sys' ); ?></label></th>
				<td>
					<label><input type="checkbox" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_ELEVATED_CUSTOMER ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_ELEVATED_CUSTOMER ); ?>" value="1"<?php echo $elevated_checked; ?> /> <?php esc_html_e( 'Show contract-focused dashboard portal', 'ai-man-sys' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_LEAD_TIME_DAYS ); ?>"><?php esc_html_e( 'Lead Time (days)', 'ai-man-sys' ); ?></label></th>
				<td><input type="number" min="0" max="365" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_LEAD_TIME_DAYS ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_LEAD_TIME_DAYS ); ?>" value="<?php echo esc_attr( (string) ( $contract['lead_time_days'] ?? 7 ) ); ?>" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_MIN_ORDER_QTY ); ?>"><?php esc_html_e( 'Minimum Reorder Quantity', 'ai-man-sys' ); ?></label></th>
				<td><input type="number" min="1" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_MIN_ORDER_QTY ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_MIN_ORDER_QTY ); ?>" value="<?php echo esc_attr( (string) ( $contract['min_order_qty'] ?? 1 ) ); ?>" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_TIER_RATES ); ?>"><?php esc_html_e( 'Tier Rates', 'ai-man-sys' ); ?></label></th>
				<td>
					<textarea class="large-text code" rows="5" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_TIER_RATES ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_TIER_RATES ); ?>"><?php echo esc_textarea( $tier_raw ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One tier per line using minQty:discountPercent (example: 10:5 means 5% off at 10+ units).', 'ai-man-sys' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_PAYMENT_TERMS ); ?>"><?php esc_html_e( 'Payment Terms', 'ai-man-sys' ); ?></label></th>
				<td><input type="text" class="regular-text" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_PAYMENT_TERMS ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_PAYMENT_TERMS ); ?>" value="<?php echo esc_attr( (string) ( $contract['payment_terms'] ?? '' ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_SHIPPING_WINDOW ); ?>"><?php esc_html_e( 'Shipping Window', 'ai-man-sys' ); ?></label></th>
				<td><input type="text" class="regular-text" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_SHIPPING_WINDOW ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_SHIPPING_WINDOW ); ?>" value="<?php echo esc_attr( (string) ( $contract['shipping_window'] ?? '' ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_CONTRACT_NOTES ); ?>"><?php esc_html_e( 'Contract Notes', 'ai-man-sys' ); ?></label></th>
				<td><textarea class="large-text" rows="3" id="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_CONTRACT_NOTES ); ?>" name="<?php echo esc_attr( AIMS_Wholesale_Contract_Service::META_CONTRACT_NOTES ); ?>"><?php echo esc_textarea( (string) ( $contract['contract_notes'] ?? '' ) ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( int $user_id ): void {
		if ( ! $this->can_edit_user_profile( $user_id ) ) {
			return;
		}

		$this->contract_service->save_contract_from_profile( $user_id, wp_unslash( $_POST ) );
	}

	private function render_portal_markup(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$login_url = function_exists( 'wp_login_url' ) ? wp_login_url( $this->resolve_current_url() ) : '';

		$model = array(
			'logged_in'            => $user_id > 0,
			'is_wholesale'         => false,
			'elevated_customer'    => false,
			'login_url'            => $login_url,
			'contract'             => array(),
			'recent_orders'        => array(),
			'integration_ingest_url' => function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'aims/v1/integrations/inventory' ) ) : '',
			'integration_feed_url' => function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'aims/v1/integrations/updates' ) ) : '',
			'endpoint_url'         => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : '',
			'reorder_post_url'     => esc_url( admin_url( 'admin-post.php' ) ),
			'reorder_action'       => self::ACTION_REORDER,
			'reorder_nonce_action' => self::ACTION_REORDER,
		);

		if ( $user_id > 0 ) {
			$model['is_wholesale']      = $this->contract_service->is_wholesale_customer( $user_id );
			$model['contract']          = $this->contract_service->get_contract( $user_id );
			$model['elevated_customer'] = ! empty( $model['contract']['elevated_customer'] );
			$model['recent_orders']     = $model['is_wholesale'] ? $this->get_recent_order_rows( $user_id, $model['contract'] ) : array();
		}

		ob_start();
		$template_path = AIMS_PLUGIN_PATH . 'templates/wholesale-customer-portal.php';
		if ( file_exists( $template_path ) ) {
			$portal_model = $model;
			include $template_path;
		} else {
			echo '<div class="aims-wholesale-portal"><p>' . esc_html__( 'Wholesale portal template is unavailable.', 'ai-man-sys' ) . '</p></div>';
		}

		return (string) ob_get_clean();
	}

	private function get_recent_order_rows( int $user_id, array $contract ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 8,
				'status'      => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$rows = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
				continue;
			}

			$total_qty = 0;
			$total_lines = 0;
			$estimate_base = 0.0;
			$line_items = array();

			foreach ( (array) $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
					continue;
				}

				$qty = (int) $item->get_quantity();
				if ( $qty <= 0 ) {
					continue;
				}

				++$total_lines;
				$total_qty += $qty;
				$line_total = (float) $item->get_total();
				$estimate_base += max( 0, $line_total );
				$line_items[] = array(
					'name' => (string) ( method_exists( $item, 'get_name' ) ? $item->get_name() : '' ),
					'qty'  => $qty,
				);
			}

			$discount_percent = $this->contract_service->resolve_discount_for_quantity( (array) ( $contract['tier_rates'] ?? array() ), $total_qty );
			$estimated_total = round( $estimate_base * ( 1 - ( $discount_percent / 100 ) ), 2 );

			$rows[] = array(
				'order_id'            => (int) $order->get_id(),
				'order_number'        => (string) $order->get_order_number(),
				'date_created'        => is_callable( array( $order, 'get_date_created' ) ) && $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
				'status'              => (string) $order->get_status(),
				'total_lines'         => $total_lines,
				'total_qty'           => $total_qty,
				'line_items'          => $line_items,
				'estimate_base'       => $estimate_base,
				'discount_percent'    => $discount_percent,
				'estimated_reorder'   => $estimated_total,
			);
		}

		return $rows;
	}

	private function add_wc_notice( string $message, string $type = 'success' ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
			return;
		}

		if ( 'error' === $type ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( 'aims_wholesale', 'aims_wholesale', $message, 'error' );
			}
		}
	}

	private function resolve_current_url(): string {
		$uri = sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		return home_url( $uri );
	}

	private function get_existing_cart_quantity( int $product_id, int $variation_id = 0 ): int {
		if ( $product_id <= 0 || ! function_exists( 'WC' ) || ! WC() || ! is_object( WC()->cart ) || ! method_exists( WC()->cart, 'get_cart' ) ) {
			return 0;
		}

		$total = 0;
		foreach ( (array) WC()->cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$item_product_id = (int) ( $cart_item['product_id'] ?? 0 );
			$item_variation_id = (int) ( $cart_item['variation_id'] ?? 0 );

			if ( $item_product_id !== $product_id ) {
				continue;
			}

			if ( $variation_id > 0 && $item_variation_id !== $variation_id ) {
				continue;
			}

			$total += max( 0, (int) ( $cart_item['quantity'] ?? 0 ) );
		}

		return $total;
	}

	private function can_edit_user_profile( int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $current_user_id > 0 && $current_user_id === $user_id ) {
			return true;
		}

		return current_user_can( 'manage_options' ) || current_user_can( 'edit_users' );
	}
}
