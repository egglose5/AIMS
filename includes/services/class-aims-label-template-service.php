<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Label_Template_Service {
	private $repository;

	public function __construct( AIMS_Label_Template_Repository $repository = null ) {
		$this->repository = $repository ?: new AIMS_Label_Template_Repository();
	}

	public function get_templates(): array {
		return $this->repository->get_templates();
	}

	public function get_active_template_key(): string {
		return $this->repository->get_active_template_key();
	}

	public function get_active_template(): array {
		return $this->repository->get_active_template();
	}

	public function get_template( string $template_key ): ?array {
		return $this->repository->get_template( $template_key );
	}

	public function save_template_settings( string $template_key, array $settings ): array {
		$payload = $this->normalize_template_settings_payload( $settings );

		return $this->repository->save_template_settings( $template_key, $payload );
	}

	public function set_active_template_key( string $template_key ): bool {
		return $this->repository->set_active_template_key( $template_key );
	}

	public function get_template_choices(): array {
		$choices = array();

		foreach ( $this->get_templates() as $template_key => $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}

			$choices[ $template_key ] = sprintf(
				'%s (%s x %s in)',
				(string) ( $template['template_name'] ?? $template_key ),
				$this->format_decimal( (float) ( $template['label_width_in'] ?? 0 ) ),
				$this->format_decimal( (float) ( $template['label_height_in'] ?? 0 ) )
			);
		}

		return $choices;
	}

	public function build_settings_view_model(): array {
		$active_template = $this->get_active_template();

		return array(
			'active_template_key' => $this->get_active_template_key(),
			'active_template'     => $active_template,
			'templates'           => $this->get_templates(),
			'template_choices'    => $this->get_template_choices(),
		);
	}

	private function normalize_template_settings_payload( array $settings ): array {
		return array(
			'template_name'         => sanitize_text_field( (string) ( $settings['template_name'] ?? '' ) ),
			'description'           => sanitize_text_field( (string) ( $settings['description'] ?? '' ) ),
			'label_width_in'        => $this->normalize_decimal( $settings['label_width_in'] ?? 0 ),
			'label_height_in'       => $this->normalize_decimal( $settings['label_height_in'] ?? 0 ),
			'padding_in'            => $this->normalize_decimal( $settings['padding_in'] ?? 0 ),
			'product_name_font_px'  => $this->normalize_integer( $settings['product_name_font_px'] ?? 0 ),
			'sku_font_px'           => $this->normalize_integer( $settings['sku_font_px'] ?? 0 ),
			'barcode_height_px'     => $this->normalize_integer( $settings['barcode_height_px'] ?? 0 ),
			'barcode_margin_top_px' => $this->normalize_integer( $settings['barcode_margin_top_px'] ?? 0 ),
			'show_product_name'     => ! empty( $settings['show_product_name'] ) ? 1 : 0,
			'show_sku_text'         => ! empty( $settings['show_sku_text'] ) ? 1 : 0,
			'show_barcode_text'     => ! empty( $settings['show_barcode_text'] ) ? 1 : 0,
			'set_active'            => ! empty( $settings['set_active'] ) ? 1 : 0,
		);
	}

	private function normalize_integer( $value ): int {
		return max( 0, (int) round( (float) $value ) );
	}

	private function normalize_decimal( $value ): float {
		$number = (float) $value;
		if ( $number < 0 ) {
			$number = 0;
		}

		return round( $number, 4 );
	}

	private function format_decimal( float $value ): string {
		$formatted = number_format( $value, 2, '.', '' );
		return rtrim( rtrim( $formatted, '0' ), '.' );
	}
}
