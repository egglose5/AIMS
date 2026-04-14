<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Workflow_Surface_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'aims_workflow_surface',
			esc_html__( 'AIMS Workflow Surface', 'ai-man-sys' ),
			array(
				'description' => esc_html__( 'Renders a theme-editor-friendly AIMS workflow surface by shortcode.', 'ai-man-sys' ),
			)
		);
	}

	public function widget( $args, $instance ) {
		$workflow = sanitize_key( (string) ( $instance['workflow'] ?? '' ) );
		$surface  = AIMS_Workflow_Surface_Registry::get_surface( $workflow );

		if ( ! $surface instanceof AIMS_Admin_Meta_Object ) {
			return;
		}

		$title = trim( (string) ( $instance['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) $surface->get( 'title', '' );
		}

		echo wp_kses_post( (string) ( $args['before_widget'] ?? '' ) );

		if ( '' !== $title ) {
			echo wp_kses_post( (string) ( $args['before_title'] ?? '' ) );
			echo esc_html( $title );
			echo wp_kses_post( (string) ( $args['after_title'] ?? '' ) );
		}

		echo do_shortcode( (string) $surface->get( 'shortcode', '' ) );
		echo wp_kses_post( (string) ( $args['after_widget'] ?? '' ) );
	}

	public function form( $instance ) {
		$title    = (string) ( $instance['title'] ?? '' );
		$workflow = sanitize_key( (string) ( $instance['workflow'] ?? 'event_catalog' ) );
		$surfaces = AIMS_Workflow_Surface_Registry::get_definitions();
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title', 'ai-man-sys' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'workflow' ) ); ?>"><?php esc_html_e( 'Workflow', 'ai-man-sys' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'workflow' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'workflow' ) ); ?>">
				<?php foreach ( $surfaces as $key => $surface ) : ?>
					<option value="<?php echo esc_attr( (string) $key ); ?>"<?php selected( $workflow, $key ); ?>>
						<?php echo esc_html( (string) $surface->get( 'title', $key ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );

		return array(
			'title'    => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ),
			'workflow' => sanitize_key( (string) ( $new_instance['workflow'] ?? '' ) ),
		);
	}
}
