<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Public_Projection_Page {
	private $data_provider;

	public function __construct( AIMS_Event_Public_Projection_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$rows    = $this->data_provider->get_rows();
		$outline = $this->data_provider->get_display_expectations();
		$status  = sanitize_key( wp_unslash( $_GET['aims_public_projection_status'] ?? '' ) );
		$message = sanitize_text_field( wp_unslash( $_GET['aims_public_projection_message'] ?? '' ) );

		echo '<div class="wrap">';
		echo '<h1>Events &rsaquo; Public Projection</h1>';
		echo '<p>Public visibility is intentional. This screen manages the safe projection layer for public catalog and detail views. Default state is draft and nothing becomes public unless explicitly published here.</p>';

		foreach ( array( 'success', 'error' ) as $notice_type ) {
			if ( $notice_type !== $status ) {
				continue;
			}

			$class = 'success' === $notice_type ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		echo '<h2>Projection Expectations</h2>';
		echo '<ul style="list-style: disc; padding-left: 20px;">';
		foreach ( $outline as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';

		echo '<p>Public catalog and detail output should be driven by `aims_public_event_catalog` using public-safe fields only. Financial totals, vendor internals, and Square-specific data must remain excluded.</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info inline"><p>No events are available to project yet.</p></div>';
			echo '</div>';
			return;
		}

		foreach ( $rows as $row ) {
			$this->render_event_card( $row );
		}

		echo '</div>';
	}

	private function render_event_card( array $row ): void {
		$event_id = (int) ( $row['event_id'] ?? 0 );
		$status   = (string) ( $row['public_status'] ?? 'draft' );

		echo '<div class="postbox" style="padding:16px; margin-bottom:16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html( (string) ( $row['event_name'] ?? '' ) ) . ' <code>#' . esc_html( (string) $event_id ) . '</code></h2>';
		echo '<p><strong>Preview:</strong> ' . esc_html( (string) ( $row['preview_event_name'] ?? '' ) ) . ' | <code>' . esc_html( (string) ( $row['preview_slug'] ?? '' ) ) . '</code> | ' . esc_html( (string) ( $row['preview_venue_name'] ?? '' ) ) . ' | ' . esc_html( (string) ( $row['date_range_label'] ?? '' ) ) . '</p>';
		echo '<p><strong>Current State:</strong> ' . esc_html( $this->format_status_label( $status ) ) . ' | Featured: ' . esc_html( ! empty( $row['is_featured'] ) ? 'Yes' : 'No' ) . ' | Request Intake: ' . esc_html( ! empty( $row['request_intake_enabled'] ) ? 'Enabled' : 'Disabled' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aims_event_public_projection_save' );
		echo '<input type="hidden" name="action" value="aims_save_event_public_projection" />';
		echo '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '" />';
		echo '<table class="form-table" role="presentation">';

		$this->render_select_row( 'Public Status', 'public_status', $status, $this->data_provider->get_status_options() );
		$this->render_text_row( 'Public Title', 'public_title', (string) ( $row['preview_event_name'] ?? '' ), 'Visible title for the public catalog and detail view.' );
		$this->render_textarea_row( 'Public Summary', 'public_summary', (string) ( $row['public_summary'] ?? '' ), 'Public-facing summary only. Do not use internal notes or financial fields.' );
		$this->render_text_row( 'Slug', 'slug', (string) ( $row['preview_slug'] ?? '' ), 'Used for the public catalog URL and detail lookup.' );
		$this->render_text_row( 'Venue Name', 'venue_name', (string) ( $row['preview_venue_name'] ?? '' ), 'Public venue label shown on catalog and detail pages.' );
		$this->render_text_row( 'City', 'city', (string) ( $row['city'] ?? '' ), 'Optional public location metadata.' );
		$this->render_text_row( 'State / Region', 'state_region', (string) ( $row['state_region'] ?? '' ), 'Optional public location metadata.' );
		$this->render_text_row( 'Hero Image Reference', 'hero_image_reference', (string) ( $row['hero_image_reference'] ?? '' ), 'Reference only. The catalog can use this for a public hero image later.' );

		echo '<tr><th scope="row">Public Flags</th><td>';
		echo '<label style="display:block; margin-bottom:8px;"><input type="checkbox" name="is_featured" value="1" ' . checked( ! empty( $row['is_featured'] ), true, false ) . '> Featured in catalog listings</label>';
		echo '<label style="display:block;"><input type="checkbox" name="request_intake_enabled" value="1" ' . checked( ! empty( $row['request_intake_enabled'] ), true, false ) . '> Enable request intake from the public projection layer</label>';
		echo '</td></tr>';

		echo '</table>';
		echo '<p><button type="submit" class="button button-primary">Save Public Projection</button></p>';
		echo '</form>';
		echo '</div>';
	}

	private function render_select_row( string $label, string $name, string $current_value, array $options ): void {
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current_value, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td>';
		echo '</tr>';
	}

	private function render_text_row( string $label, string $name, string $value, string $help = '' ): void {
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td>';
		echo '</tr>';
	}

	private function render_textarea_row( string $label, string $name, string $value, string $help = '' ): void {
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td>';
		echo '<textarea class="large-text" rows="4" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td>';
		echo '</tr>';
	}

	private function format_status_label( string $status ): string {
		switch ( sanitize_key( $status ) ) {
			case 'published':
				return 'Published';
			case 'archived':
				return 'Archived';
			default:
				return 'Draft';
		}
	}
}
