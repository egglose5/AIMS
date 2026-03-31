<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Label_Template_Repository {
	public const OPTION_ACTIVE_TEMPLATE_KEY = 'aims_active_label_template_key';
	public const OPTION_TEMPLATE_SETTINGS    = 'aims_label_template_settings';
	public const OPTION_CUSTOM_TEMPLATES     = 'aims_custom_label_templates';
	public const DEFAULT_TEMPLATE_KEY        = 'legacy-stitch-2x0-75';

	public function get_default_templates(): array {
		return array(
			self::DEFAULT_TEMPLATE_KEY => array(
				'template_key'         => self::DEFAULT_TEMPLATE_KEY,
				'template_name'        => 'Legacy Stitch Label',
				'description'          => '2in x 0.75in label with product name, SKU, and barcode.',
				'label_width_in'       => 2.0,
				'label_height_in'      => 0.75,
				'padding_in'           => 0.06,
				'product_name_font_px' => 10,
				'sku_font_px'          => 8,
				'barcode_height_px'    => 26,
				'barcode_margin_top_px' => 2,
				'show_product_name'    => 1,
				'show_sku_text'        => 1,
				'show_barcode_text'    => 1,
			),
		);
	}

	public function get_templates(): array {
		$templates = $this->get_default_templates();
		$custom    = $this->get_custom_templates();

		foreach ( $custom as $template_key => $template ) {
			$templates[ $template_key ] = $template;
		}

		$stored_settings = $this->get_template_settings_overrides();
		foreach ( $stored_settings as $template_key => $settings ) {
			if ( ! isset( $templates[ $template_key ] ) || ! is_array( $settings ) ) {
				continue;
			}

			$templates[ $template_key ] = array_merge( $templates[ $template_key ], $settings );
		}

		return $templates;
	}

	public function get_template( string $template_key ): ?array {
		$template_key = $this->sanitize_template_key( $template_key );
		if ( '' === $template_key ) {
			return null;
		}

		$templates = $this->get_templates();
		if ( ! isset( $templates[ $template_key ] ) ) {
			return null;
		}

		return $this->normalize_template( $templates[ $template_key ] );
	}

	public function get_active_template_key(): string {
		$stored_key = $this->sanitize_template_key( (string) get_option( self::OPTION_ACTIVE_TEMPLATE_KEY, self::DEFAULT_TEMPLATE_KEY ) );
		$templates  = $this->get_templates();

		if ( '' === $stored_key || ! isset( $templates[ $stored_key ] ) ) {
			return self::DEFAULT_TEMPLATE_KEY;
		}

		return $stored_key;
	}

	public function set_active_template_key( string $template_key ): bool {
		$template_key = $this->sanitize_template_key( $template_key );
		if ( '' === $template_key ) {
			return false;
		}

		$templates = $this->get_templates();
		if ( ! isset( $templates[ $template_key ] ) ) {
			return false;
		}

		$updated = update_option( self::OPTION_ACTIVE_TEMPLATE_KEY, $template_key );
		if ( $updated ) {
			return true;
		}

		return $this->get_active_template_key() === $template_key;
	}

	public function get_active_template(): array {
		$template = $this->get_template( $this->get_active_template_key() );

		if ( is_array( $template ) ) {
			return $template;
		}

		return $this->normalize_template( $this->get_default_templates()[ self::DEFAULT_TEMPLATE_KEY ] );
	}

	public function save_template_settings( string $template_key, array $settings ): array {
		$template_key = $this->sanitize_template_key( $template_key );
		$template     = $this->get_template( $template_key );

		if ( ! is_array( $template ) ) {
			return array(
				'success' => false,
				'message' => 'Template not found.',
			);
		}

		$overrides                       = $this->get_template_settings_overrides();
		$overrides[ $template_key ] = $this->sanitize_template_settings( $settings, $template );

		$updated = update_option( self::OPTION_TEMPLATE_SETTINGS, $overrides );
		if ( ! $updated && $this->get_template_settings_overrides() !== $overrides ) {
			return array(
				'success' => false,
				'message' => 'Unable to save label template settings.',
			);
		}

		if ( ! empty( $settings['set_active'] ) ) {
			$this->set_active_template_key( $template_key );
		}

		return array(
			'success'  => true,
			'message'  => 'Label template settings saved.',
			'template' => $this->get_template( $template_key ),
		);
	}

	public function get_template_settings_overrides(): array {
		$overrides = get_option( self::OPTION_TEMPLATE_SETTINGS, array() );
		if ( ! is_array( $overrides ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $overrides as $template_key => $settings ) {
			$template_key = $this->sanitize_template_key( (string) $template_key );
			if ( '' === $template_key || ! is_array( $settings ) ) {
				continue;
			}

			$normalized[ $template_key ] = $settings;
		}

		return $normalized;
	}

	public function get_custom_templates(): array {
		$custom = get_option( self::OPTION_CUSTOM_TEMPLATES, array() );
		if ( ! is_array( $custom ) ) {
			return array();
		}

		$templates = array();
		foreach ( $custom as $template_key => $template ) {
			$template_key = $this->sanitize_template_key( (string) $template_key );
			if ( '' === $template_key || ! is_array( $template ) ) {
				continue;
			}

			$templates[ $template_key ] = $this->normalize_template(
				array_merge(
					array(
						'template_key' => $template_key,
					),
					$template
				)
			);
		}

		return $templates;
	}

	private function normalize_template( array $template ): array {
		$template_key = $this->sanitize_template_key( (string) ( $template['template_key'] ?? '' ) );
		if ( '' === $template_key ) {
			$template_key = self::DEFAULT_TEMPLATE_KEY;
		}

		return array(
			'template_key'          => $template_key,
			'template_name'         => sanitize_text_field( (string) ( $template['template_name'] ?? 'Label Template' ) ),
			'description'           => sanitize_text_field( (string) ( $template['description'] ?? '' ) ),
			'label_width_in'        => $this->sanitize_decimal( $template['label_width_in'] ?? 0 ),
			'label_height_in'       => $this->sanitize_decimal( $template['label_height_in'] ?? 0 ),
			'padding_in'            => $this->sanitize_decimal( $template['padding_in'] ?? 0 ),
			'product_name_font_px'  => $this->sanitize_integer( $template['product_name_font_px'] ?? 0 ),
			'sku_font_px'           => $this->sanitize_integer( $template['sku_font_px'] ?? 0 ),
			'barcode_height_px'     => $this->sanitize_integer( $template['barcode_height_px'] ?? 0 ),
			'barcode_margin_top_px' => $this->sanitize_integer( $template['barcode_margin_top_px'] ?? 0 ),
			'show_product_name'     => ! empty( $template['show_product_name'] ) ? 1 : 0,
			'show_sku_text'         => ! empty( $template['show_sku_text'] ) ? 1 : 0,
			'show_barcode_text'     => ! empty( $template['show_barcode_text'] ) ? 1 : 0,
		);
	}

	private function sanitize_template_settings( array $settings, array $template ): array {
		$normalized = $this->normalize_template( array_merge( $template, $settings ) );
		$normalized['template_key']  = $template['template_key'];
		$normalized['template_name'] = '' !== sanitize_text_field( (string) ( $settings['template_name'] ?? '' ) )
			? sanitize_text_field( (string) $settings['template_name'] )
			: (string) ( $template['template_name'] ?? 'Label Template' );
		$normalized['description'] = sanitize_text_field( (string) ( $settings['description'] ?? (string) ( $template['description'] ?? '' ) ) );

		return $normalized;
	}

	private function sanitize_template_key( string $template_key ): string {
		return sanitize_key( $template_key );
	}

	private function sanitize_integer( $value ): int {
		return max( 0, (int) round( (float) $value ) );
	}

	private function sanitize_decimal( $value ): float {
		$number = (float) $value;
		if ( $number < 0 ) {
			$number = 0;
		}

		return round( $number, 4 );
	}
}
