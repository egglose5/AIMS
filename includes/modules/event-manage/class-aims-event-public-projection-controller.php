<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Public_Projection_Controller {
	private const SHORTCODE_CATALOG = 'aims_events_catalog';
	private const SHORTCODE_CARD    = 'aims_event_card';
	private const SHORTCODE_DETAIL  = 'aims_event_detail';

	private $repository;

	public function __construct( AIMS_Public_Event_Catalog_Repository $repository = null ) {
		$this->repository = $repository ?: new AIMS_Public_Event_Catalog_Repository();
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE_CATALOG, array( $this, 'render_catalog_shortcode' ) );
		add_shortcode( self::SHORTCODE_CARD, array( $this, 'render_card_shortcode' ) );
		add_shortcode( self::SHORTCODE_DETAIL, array( $this, 'render_detail_shortcode' ) );
	}

	public function render_catalog_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'         => 'Events',
				'empty_message' => 'No public events are currently available.',
			),
			$atts,
			self::SHORTCODE_CATALOG
		);

		$events = $this->repository->get_public_events();

		ob_start();
		$template_path = AIMS_PLUGIN_PATH . 'templates/event-catalog.php';

		if ( file_exists( $template_path ) ) {
			$catalog_title         = (string) $atts['title'];
			$catalog_empty_message = (string) $atts['empty_message'];
			$catalog_events        = $events;
			include $template_path;
		}

		return (string) ob_get_clean();
	}

	public function render_card_shortcode( array $atts = array() ): string {
		$event = $this->resolve_event_from_atts( $atts, self::SHORTCODE_CARD );

		if ( empty( $event ) ) {
			return '<div class="aims-event-card"><p>' . esc_html__( 'Event projection is unavailable.', 'ai-man-sys' ) . '</p></div>';
		}

		ob_start();
		$template_path = AIMS_PLUGIN_PATH . 'templates/event-card.php';

		if ( file_exists( $template_path ) ) {
			$card_event = $event;
			include $template_path;
		}

		return (string) ob_get_clean();
	}

	public function render_detail_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'event_id'           => 0,
				'slug'               => '',
				'show_demand_form'   => '1',
				'demand_title'       => 'Request Event Demand',
				'demand_button_label'=> 'Submit Demand Request',
			),
			$atts,
			self::SHORTCODE_DETAIL
		);

		$event = $this->resolve_event_from_atts( $atts, self::SHORTCODE_DETAIL );

		if ( empty( $event ) ) {
			return '<div class="aims-event-detail"><p>' . esc_html__( 'Event detail is unavailable.', 'ai-man-sys' ) . '</p></div>';
		}

		ob_start();
		$template_path = AIMS_PLUGIN_PATH . 'templates/event-detail.php';

		if ( file_exists( $template_path ) ) {
			$detail_event           = $event;
			$detail_show_demand_form = '1' === (string) $atts['show_demand_form'];
			$projection_allows_intake = ! empty( $event['request_intake_enabled'] );
			$detail_demand_shortcode = ( $detail_show_demand_form && $projection_allows_intake )
				? do_shortcode(
					sprintf(
						'[aims_event_demand_form event_id="%d" title="%s" button_label="%s"]',
						(int) $event['event_id'],
						esc_attr( (string) $atts['demand_title'] ),
						esc_attr( (string) $atts['demand_button_label'] )
					)
				)
				: '';
			include $template_path;
		}

		return (string) ob_get_clean();
	}

	private function resolve_event_from_atts( array $atts, string $shortcode ): ?array {
		$atts = shortcode_atts(
			array(
				'event_id' => 0,
				'slug'     => '',
			),
			$atts,
			$shortcode
		);

		return $this->repository->find_public_event(
			(int) $atts['event_id'],
			(string) $atts['slug']
		);
	}
}
