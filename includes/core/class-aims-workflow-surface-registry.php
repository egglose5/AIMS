<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Workflow_Surface_Registry {
	public const HUB_SHORTCODE       = 'aims_workflow_hub';
	public const HUB_TEMPLATE_SLUG   = 'aims-workflow-hub.php';
	public const HUB_TEMPLATE_LABEL  = 'AIMS Workflow Hub';
	public const HUB_TEMPLATE_PATH   = 'templates/aims-workflow-hub.php';
	public const WIDGET_CLASS        = 'AIMS_Workflow_Surface_Widget';

	/**
	 * @return array<string, AIMS_Admin_Meta_Object>
	 */
	public static function get_definitions(): array {
		$definitions = array(
			'event_catalog' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'event_catalog',
					'title'       => 'Event Catalog',
					'description' => 'Public list of currently published events.',
					'shortcode'   => '[aims_events_catalog]',
					'category'    => 'events',
				)
			),
			'event_detail' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'event_detail',
					'title'       => 'Event Detail',
					'description' => 'Event landing page with optional demand intake embedded.',
					'shortcode'   => '[aims_event_detail]',
					'category'    => 'events',
				)
			),
			'event_updates' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'event_updates',
					'title'       => 'Event Updates Feed',
					'description' => 'Public event updates stream for a published event.',
					'shortcode'   => '[aims_event_updates_feed]',
					'category'    => 'events',
				)
			),
			'event_demand_form' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'event_demand_form',
					'title'       => 'Event Demand Form',
					'description' => 'Planning-only customer demand form for a specific event.',
					'shortcode'   => '[aims_event_demand_form]',
					'category'    => 'events',
				)
			),
			'event_requests_history' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'event_requests_history',
					'title'       => 'Event Request History',
					'description' => 'Logged-in history of prior event demand requests.',
					'shortcode'   => '[aims_event_requests_history]',
					'category'    => 'events',
				)
			),
			'vendor_portal_navigation' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'vendor_portal_navigation',
					'title'       => 'Vendor Portal Navigation',
					'description' => 'Assigned shows, join links, and vendor navigation surfaces.',
					'shortcode'   => '[aims_vendor_portal_nav]',
					'category'    => 'vendors',
				)
			),
			'vendor_event_checkin' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'vendor_event_checkin',
					'title'       => 'Vendor Event Check-In',
					'description' => 'Vendor mobile check-in, expense capture, and receipt upload.',
					'shortcode'   => '[aims_vendor_event_checkin_portal]',
					'category'    => 'vendors',
				)
			),
			'stitch_portal' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'stitch_portal',
					'title'       => 'Stitch Portal',
					'description' => 'Line-level stitcher workspace for in-progress production work.',
					'shortcode'   => '[aims_stitch_portal]',
					'category'    => 'production',
				)
			),
			'cycle_count_portal' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'cycle_count_portal',
					'title'       => 'Cycle Count Portal',
					'description' => 'Inventory counting and deployment portal with barcode entry.',
					'shortcode'   => '[aims_cycle_count_portal]',
					'category'    => 'inventory',
				)
			),
			'wholesale_portal' => new AIMS_Admin_Meta_Object(
				array(
					'key'         => 'wholesale_portal',
					'title'       => 'Wholesale Portal',
					'description' => 'Wholesale reorder, pricing, and contract visibility surface.',
					'shortcode'   => '[aims_wholesale_portal]',
					'category'    => 'customers',
				)
			),
		);

		return $definitions;
	}

	public static function get_surface( string $key ): ?AIMS_Admin_Meta_Object {
		$definitions = self::get_definitions();
		$key         = sanitize_key( $key );

		return isset( $definitions[ $key ] ) ? $definitions[ $key ] : null;
	}

	public function register(): void {
		add_shortcode( self::HUB_SHORTCODE, array( $this, 'render_hub_shortcode' ) );
		add_action( 'widgets_init', array( $this, 'register_widgets' ) );
		add_filter( 'theme_page_templates', array( $this, 'register_page_template' ) );
		add_filter( 'template_include', array( $this, 'resolve_page_template' ) );
	}

	public function register_widgets(): void {
		if ( class_exists( self::WIDGET_CLASS ) && function_exists( 'register_widget' ) ) {
			register_widget( self::WIDGET_CLASS );
		}
	}

	public function register_page_template( array $templates ): array {
		$templates[ self::HUB_TEMPLATE_SLUG ] = self::HUB_TEMPLATE_LABEL;
		return $templates;
	}

	public function resolve_page_template( string $template ): string {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return $template;
		}

		if ( ! function_exists( 'get_queried_object_id' ) || ! function_exists( 'get_post_meta' ) ) {
			return $template;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return $template;
		}

		$selected_template = (string) get_post_meta( $post_id, '_wp_page_template', true );
		if ( self::HUB_TEMPLATE_SLUG !== $selected_template ) {
			return $template;
		}

		$plugin_template = AIMS_PLUGIN_PATH . self::HUB_TEMPLATE_PATH;

		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}

	public function render_hub_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'           => 'AIMS Workflow Hub',
				'description'     => 'Theme-editor-ready workflow surfaces for every front-end AIMS flow except the role editor.',
				'workflow'        => '',
				'show_shortcodes' => '1',
				'show_index'      => '1',
			),
			$atts,
			self::HUB_SHORTCODE
		);

		$surfaces = $this->resolve_requested_surfaces( (string) $atts['workflow'] );
		if ( empty( $surfaces ) ) {
			return '<div class="aims-workflow-hub"><p>' . esc_html__( 'No AIMS workflows are available for this surface.', 'ai-man-sys' ) . '</p></div>';
		}

		$show_shortcodes = '0' !== (string) $atts['show_shortcodes'];
		$show_index      = '0' !== (string) $atts['show_index'];

		ob_start();
		echo '<div class="aims-workflow-hub">';
		echo '<header class="aims-workflow-hub__header">';
		echo '<h2>' . esc_html( (string) $atts['title'] ) . '</h2>';
		echo '<p>' . esc_html( (string) $atts['description'] ) . '</p>';
		echo '</header>';

		if ( $show_index ) {
			echo '<ul class="aims-workflow-hub__index">';
			foreach ( $surfaces as $surface ) {
				echo '<li><a href="#aims-workflow-' . esc_attr( (string) $surface->get( 'key' ) ) . '">' . esc_html( (string) $surface->get( 'title' ) ) . '</a></li>';
			}
			echo '</ul>';
		}

		foreach ( $surfaces as $surface ) {
			$key       = (string) $surface->get( 'key' );
			$shortcode = (string) $surface->get( 'shortcode' );

			echo '<section id="aims-workflow-' . esc_attr( $key ) . '" class="aims-workflow-hub__surface">';
			echo '<h3>' . esc_html( (string) $surface->get( 'title' ) ) . '</h3>';
			echo '<p>' . esc_html( (string) $surface->get( 'description' ) ) . '</p>';

			if ( $show_shortcodes ) {
				echo '<p><strong>' . esc_html__( 'Shortcode', 'ai-man-sys' ) . ':</strong> <code>' . esc_html( $shortcode ) . '</code></p>';
			}

			echo '<div class="aims-workflow-hub__rendered">';
			echo do_shortcode( $shortcode );
			echo '</div>';
			echo '</section>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * @return array<int, AIMS_Admin_Meta_Object>
	 */
	private function resolve_requested_surfaces( string $workflow_filter ): array {
		$definitions = self::get_definitions();
		$keys        = array();

		if ( '' !== trim( $workflow_filter ) ) {
			$keys = array_filter(
				array_map(
					'sanitize_key',
					array_map(
						'trim',
						explode( ',', $workflow_filter )
					)
				)
			);
		}

		if ( empty( $keys ) ) {
			return array_values( $definitions );
		}

		$resolved = array();
		foreach ( $keys as $key ) {
			if ( isset( $definitions[ $key ] ) ) {
				$resolved[] = $definitions[ $key ];
			}
		}

		return $resolved;
	}
}
