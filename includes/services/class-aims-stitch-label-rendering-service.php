<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Label_Rendering_Service {
	private $template_service;

	public function __construct( AIMS_Label_Template_Service $template_service = null ) {
		$this->template_service = $template_service ?: new AIMS_Label_Template_Service();
	}

	public function build_print_model( array $items, string $template_key = '' ): array {
		$template = '' !== sanitize_key( $template_key ) ? $this->template_service->get_template( $template_key ) : null;
		if ( ! is_array( $template ) ) {
			$template = $this->template_service->get_active_template();
		}

		$labels = $this->expand_items_to_labels( $items, $template );

		return array(
			'template'          => $template,
			'labels'            => $labels,
			'items'             => $this->normalize_items( $items ),
			'label_count'       => count( $labels ),
			'generated_at'      => current_time( 'mysql' ),
			'generated_by_user' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		);
	}

	public function render_print_document( array $items, string $template_key = '' ): string {
		$print_model = $this->build_print_model( $items, $template_key );
		$template_path = AIMS_PLUGIN_PATH . 'templates/print/stitch-label-sheet.php';

		ob_start();

		if ( file_exists( $template_path ) ) {
			include $template_path;
		} else {
			echo '<div class="wrap"><p>Label print template is unavailable.</p></div>';
		}

		return (string) ob_get_clean();
	}

	public function normalize_items( array $items ): array {
		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$product_name = $this->extract_product_name( $item );
			$product_sku  = $this->extract_product_sku( $item );
			$quantity     = $this->extract_quantity( $item );

			if ( '' === $product_name && '' === $product_sku ) {
				continue;
			}

			$normalized[] = array(
				'product_name' => $product_name,
				'product_sku'  => $product_sku,
				'quantity'     => $quantity,
				'raw'          => $item,
			);
		}

		return $normalized;
	}

	public function expand_items_to_labels( array $items, array $template = array() ): array {
		$labels = array();

		foreach ( $this->normalize_items( $items ) as $item_index => $item ) {
			$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );

			for ( $copy = 1; $copy <= $quantity; $copy++ ) {
				$labels[] = array(
					'label_index'   => count( $labels ) + 1,
					'copy_number'   => $copy,
					'copy_total'    => $quantity,
					'product_name'  => (string) ( $item['product_name'] ?? '' ),
					'product_sku'   => (string) ( $item['product_sku'] ?? '' ),
					'barcode_value' => $this->normalize_barcode_value( (string) ( $item['product_sku'] ?? '' ) ),
					'barcode_svg'   => $this->render_barcode_svg( (string) ( $item['product_sku'] ?? '' ), $template ),
					'item_index'    => $item_index,
					'raw'           => $item['raw'] ?? array(),
				);
			}
		}

		return $labels;
	}

	public function render_barcode_svg( string $barcode_value, array $template = array() ): string {
		$barcode_value = $this->normalize_barcode_value( $barcode_value );
		if ( '' === $barcode_value ) {
			return '';
		}

		$pattern_map = $this->get_code39_patterns();
		$encoded     = '*' . $barcode_value . '*';
		$modules     = array();

		foreach ( str_split( $encoded ) as $character ) {
			$pattern = $pattern_map[ $character ] ?? $pattern_map['*'];
			$segments = str_split( $pattern );

			foreach ( $segments as $index => $segment ) {
				$is_bar = 0 === ( $index % 2 );
				$modules[] = array(
					'bar'   => $is_bar,
					'wide'  => 'w' === $segment,
				);
			}

			$modules[] = array(
				'bar'  => false,
				'wide' => false,
				'space_between_characters' => true,
			);
		}

		array_pop( $modules );

		$narrow = 2;
		$wide    = 6;
		$height  = max( 24, (int) ( $template['barcode_height_px'] ?? 26 ) );
		$margin_top = max( 0, (int) ( $template['barcode_margin_top_px'] ?? 0 ) );

		$current_x = 0;
		$elements  = array();
		foreach ( $modules as $module ) {
			if ( ! empty( $module['space_between_characters'] ) ) {
				$current_x += $narrow;
				continue;
			}

			$width = ! empty( $module['wide'] ) ? $wide : $narrow;
			if ( ! empty( $module['bar'] ) ) {
				$elements[] = sprintf(
					'<rect x="%1$d" y="%2$d" width="%3$d" height="%4$d" fill="#000" />',
					$current_x,
					$margin_top,
					$width,
					$height
				);
			}

			$current_x += $width;
		}

		$svg_width = max( 120, $current_x );
		$svg_height = $height + $margin_top + 2;

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s">%4$s</svg>',
			$svg_width,
			$svg_height,
			esc_attr( $barcode_value ),
			implode( '', $elements )
		);
	}

	private function extract_product_name( array $item ): string {
		$keys = array( 'product_name', 'label_product_name', 'name', 'title' );
		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) ( $item[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function extract_product_sku( array $item ): string {
		$keys = array( 'product_sku', 'label_sku', 'sku', 'stitch_sku' );
		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) ( $item[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function extract_quantity( array $item ): int {
		$keys = array( 'quantity', 'label_quantity', 'qty', 'quantity_units' );
		foreach ( $keys as $key ) {
			$value = (int) ( $item[ $key ] ?? 0 );
			if ( $value > 0 ) {
				return $value;
			}
		}

		return 1;
	}

	private function normalize_barcode_value( string $value ): string {
		$value = strtoupper( trim( $value ) );
		$value = preg_replace( '/[^0-9A-Z\-\.\ \$\/\+\%]/', '', $value );

		return (string) $value;
	}

	private function get_code39_patterns(): array {
		return array(
			'0' => 'nnnwwnwnn',
			'1' => 'wnnwnnnnw',
			'2' => 'nnwwnnnnw',
			'3' => 'wnwwnnnnn',
			'4' => 'nnnwwnnnw',
			'5' => 'wnnwwnnnn',
			'6' => 'nnwwwnnnn',
			'7' => 'nnnwnnwnw',
			'8' => 'wnnwnnwnn',
			'9' => 'nnwwnnwnn',
			'A' => 'wnnnnwnnw',
			'B' => 'nnwnnwnnw',
			'C' => 'wnwnnwnnn',
			'D' => 'nnnnwwnnw',
			'E' => 'wnnnwwnnn',
			'F' => 'nnwnwwnnn',
			'G' => 'nnnnnwwnw',
			'H' => 'wnnnnwwnn',
			'I' => 'nnwnnwwnn',
			'J' => 'nnnnwwwnn',
			'K' => 'wnnnnnnww',
			'L' => 'nnwnnnnww',
			'M' => 'wnwnnnnwn',
			'N' => 'nnnnwnnww',
			'O' => 'wnnnwnnwn',
			'P' => 'nnwnwnnwn',
			'Q' => 'nnnnnnwww',
			'R' => 'wnnnnnwwn',
			'S' => 'nnwnnnwwn',
			'T' => 'nnnnwnwwn',
			'U' => 'wwnnnnnnw',
			'V' => 'nwwnnnnnw',
			'W' => 'wwwnnnnnn',
			'X' => 'nwnnwnnnw',
			'Y' => 'wwnnwnnnn',
			'Z' => 'nwwnwnnnn',
			'-' => 'nwnnnnwnw',
			'.' => 'wwnnnnwnn',
			' ' => 'nwwnnnwnn',
			'$' => 'nwnwnwnnn',
			'/' => 'nwnwnnnwn',
			'+' => 'nwnnnwnwn',
			'%' => 'nnnwnwnwn',
			'*' => 'nwnnwnwnn',
		);
	}
}
