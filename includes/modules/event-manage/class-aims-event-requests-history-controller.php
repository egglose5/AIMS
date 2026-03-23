<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Requests_History_Controller {
	private const SHORTCODE = 'aims_event_requests_history';

	private $events;
	private $requests;
	private $request_items;

	public function __construct(
		AIMS_Event_Repository $events = null,
		AIMS_Event_Customer_Request_Repository $requests = null,
		AIMS_Event_Customer_Request_Item_Repository $request_items = null
	) {
		$this->events        = $events ?: new AIMS_Event_Repository();
		$this->requests      = $requests ?: new AIMS_Event_Customer_Request_Repository();
		$this->request_items = $request_items ?: new AIMS_Event_Customer_Request_Item_Repository();
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	public function render_shortcode( array $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="aims-event-requests-history"><p>' . esc_html__( 'Please log in to view your event request history.', 'ai-man-sys' ) . '</p></div>';
		}

		$atts = shortcode_atts(
			array(
				'event_id' => 0,
				'title'    => 'My Event Requests',
			),
			$atts,
			self::SHORTCODE
		);

		$user_id = get_current_user_id();
		$rows    = $this->get_rows_for_user( $user_id, (int) $atts['event_id'] );
		$user    = wp_get_current_user();

		ob_start();

		echo '<div class="wrap aims-event-requests-history">';
		echo '<h2>' . esc_html( (string) $atts['title'] ) . '</h2>';
		echo '<p>Logged-in event demand history grouped by event and SKU/product. Planning-only records are shown here; no reservation or payment logic is included.</p>';
		echo '<p><strong>Account:</strong> ' . esc_html( $user->display_name ?: $user->user_login ) . ' (#' . esc_html( (string) $user_id ) . ')</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No event request history is available for this account yet.</p></div>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Event</th>';
		echo '<th>SKU</th>';
		echo '<th>Product</th>';
		echo '<th>Quantity</th>';
		echo '<th>Requested</th>';
		echo '<th>Status</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['event_name'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $row['product_sku'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $row['product_name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['quantity_requested'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['requested_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->display_request_status( (string) ( $row['request_status'] ?? '' ), (string) ( $row['status'] ?? '' ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	private function get_rows_for_user( int $wp_user_id, int $event_filter = 0 ): array {
		$request_rows = (array) $this->requests->get_for_wp_user_id( $wp_user_id );
		$rows         = array();

		foreach ( $request_rows as $request_row ) {
			if ( ! is_array( $request_row ) ) {
				continue;
			}

			$request_event_id = (int) ( $request_row['event_id'] ?? 0 );
			if ( $event_filter > 0 && $request_event_id !== $event_filter ) {
				continue;
			}

			$event = $this->find_event( $request_event_id );
			$item_rows = $this->request_items->get_for_request( (int) ( $request_row['id'] ?? 0 ) );

			foreach ( $item_rows as $item_row ) {
				if ( ! is_array( $item_row ) ) {
					continue;
				}

				$rows[] = array(
					'event_id'        => $request_event_id,
					'event_name'      => (string) ( $event['event_name'] ?? '' ),
					'product_sku'     => (string) ( $item_row['product_sku'] ?? '' ),
					'woo_product_id'  => (int) ( $item_row['woo_product_id'] ?? 0 ),
					'product_name'    => $this->resolve_product_name( $item_row ),
					'quantity_requested' => (float) ( $item_row['quantity_requested'] ?? 0 ),
					'requested_at'    => (string) ( $request_row['requested_at'] ?? '' ),
					'request_status'  => (string) ( $request_row['request_status'] ?? $request_row['status'] ?? '' ),
				);
			}
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$event_compare = strcasecmp( (string) ( $left['event_name'] ?? '' ), (string) ( $right['event_name'] ?? '' ) );
				if ( 0 !== $event_compare ) {
					return $event_compare;
				}

				$sku_compare = strcasecmp( (string) ( $left['product_sku'] ?? '' ), (string) ( $right['product_sku'] ?? '' ) );
				if ( 0 !== $sku_compare ) {
					return $sku_compare;
				}

				return strcmp( (string) ( $right['requested_at'] ?? '' ), (string) ( $left['requested_at'] ?? '' ) );
			}
		);

		return $rows;
	}

	private function find_event( int $event_id ): ?array {
		if ( $event_id <= 0 ) {
			return null;
		}

		foreach ( array( 'find', 'get', 'get_event' ) as $method ) {
			if ( method_exists( $this->events, $method ) ) {
				$event = $this->events->{$method}( $event_id );
				if ( is_array( $event ) ) {
					return $event;
				}
			}
		}

		if ( method_exists( $this->events, 'all' ) ) {
			foreach ( (array) $this->events->all() as $event ) {
				if ( is_array( $event ) && (int) ( $event['id'] ?? 0 ) === $event_id ) {
					return $event;
				}
			}
		}

		return null;
	}

	private function resolve_product_name( array $item_row ): string {
		$product_name = trim( (string) ( $item_row['product_name'] ?? '' ) );
		if ( '' !== $product_name ) {
			return $product_name;
		}

		$woo_product_id = (int) ( $item_row['woo_product_id'] ?? 0 );
		if ( $woo_product_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $woo_product_id );
			if ( $product && is_object( $product ) && ! $product->is_virtual() && ! $product->is_downloadable() ) {
				$product_name = trim( (string) $product->get_name() );
				if ( '' !== $product_name ) {
					return $product_name;
				}
			}
		}

		return (string) ( $item_row['product_sku'] ?? '' );
	}

	private function display_request_status( string $request_status, string $status = '' ): string {
		$normalized = sanitize_key( '' !== $request_status ? $request_status : $status );

		switch ( $normalized ) {
			case 'approved':
			case 'active':
			case 'pending':
			case 'planned':
				return 'Planned';
			case 'cancelled':
				return 'Cancelled';
			case 'archived':
				return 'Archived';
			default:
				return '' !== $normalized ? ucfirst( str_replace( '_', ' ', $normalized ) ) : 'Planned';
		}
	}
}
