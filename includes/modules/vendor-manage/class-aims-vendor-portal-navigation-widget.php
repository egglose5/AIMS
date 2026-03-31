<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Portal_Navigation_Widget extends WP_Widget {
	private $service;

	public function __construct( AIMS_Vendor_Portal_Navigation_Service $service = null ) {
		parent::__construct(
			'aims_vendor_portal_nav',
			__( 'AIMS Vendor Portal Navigation', 'ai-man-sys' ),
			array( 'description' => __( 'Displays vendor portal navigation with assigned events and check-in links.', 'ai-man-sys' ) )
		);
		$this->service = $service ?: new AIMS_Vendor_Portal_Navigation_Service();
	}

	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Vendor Portal', 'ai-man-sys' );
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

		echo wp_kses_post( (string) ( $args['before_widget'] ?? '' ) );

		if ( ! empty( $title ) ) {
			echo wp_kses_post( (string) ( $args['before_title'] ?? '' ) );
			echo esc_html( $title );
			echo wp_kses_post( (string) ( $args['after_title'] ?? '' ) );
		}

		$model = $this->service->get_nav_model( wp_unslash( $_GET ) );

		ob_start();

		$template_path = AIMS_PLUGIN_PATH . 'templates/vendor-portal-navigation.php';
		if ( file_exists( $template_path ) ) {
			$nav_model = $model;
			include $template_path;
		} else {
			echo '<p>' . esc_html__( 'Vendor portal navigation template is unavailable.', 'ai-man-sys' ) . '</p>';
		}

		echo wp_kses_post( (string) ob_get_clean() );
		echo wp_kses_post( (string) ( $args['after_widget'] ?? '' ) );
	}

	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Vendor Portal', 'ai-man-sys' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'ai-man-sys' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ! empty( $new_instance['title'] ) ? sanitize_text_field( wp_unslash( $new_instance['title'] ) ) : '';

		return $instance;
	}
}
