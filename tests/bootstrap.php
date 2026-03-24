<?php

declare( strict_types=1 );

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'AIMS_VERSION' ) ) {
	define( 'AIMS_VERSION', '0.1.0-test' );
}

if ( ! defined( 'AIMS_PLUGIN_FILE' ) ) {
	define( 'AIMS_PLUGIN_FILE', $root . DIRECTORY_SEPARATOR . 'ai-man-sys.php' );
}

if ( ! defined( 'AIMS_PLUGIN_BASENAME' ) ) {
	define( 'AIMS_PLUGIN_BASENAME', 'ai-man-sys/ai-man-sys.php' );
}

if ( ! defined( 'AIMS_PLUGIN_PATH' ) ) {
	define( 'AIMS_PLUGIN_PATH', rtrim( $root, '\\/' ) . DIRECTORY_SEPARATOR );
}

if ( ! defined( 'AIMS_PLUGIN_URL' ) ) {
	define( 'AIMS_PLUGIN_URL', 'http://example.test/wp-content/plugins/ai-man-sys/' );
}

require_once $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

require_once AIMS_PLUGIN_PATH . 'includes/core/class-aims-loader.php';
AIMS_Loader::init();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ): string {
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ): string {
		return trim( strtolower( (string) $email ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $text ): string {
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ): string {
		return (string) $text;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql' ): string {
		return \AIMS\Tests\Support\TestState::current_time();
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return \AIMS\Tests\Support\TestState::current_user_id();
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		return \AIMS\Tests\Support\TestState::get_user_by( $field, $value );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key, bool $single = true ) {
		return \AIMS\Tests\Support\TestState::get_user_meta( $user_id, $key, $single );
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return \AIMS\Tests\Support\TestState::current_user_object();
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return \AIMS\Tests\Support\TestState::current_user_id() > 0;
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
		return array_merge( $pairs, $atts );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( int $product_id ) {
		return \AIMS\Tests\Support\TestState::get_product( $product_id );
	}
}

\AIMS\Tests\Support\TestState::reset();
$GLOBALS['wpdb'] = new \AIMS\Tests\Support\FakeWpdb( 'wp_' );
