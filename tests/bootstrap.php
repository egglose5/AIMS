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

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ): string {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );

		return trim( (string) $title, '-' );
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ): string {
		return (string) json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ): string {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text ): string {
		return nl2br( (string) $text );
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

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		return \AIMS\Tests\Support\TestState::get_users( $args );
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

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = '' ): void {
		echo esc_html__( $text, $domain );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return \AIMS\Tests\Support\TestState::current_user_can( $cap );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return \AIMS\Tests\Support\TestState::get_option( $option, $default );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, bool $autoload = false ): bool {
		return \AIMS\Tests\Support\TestState::update_option( $option, $value );
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		return \AIMS\Tests\Support\TestState::delete_option( $option );
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, string $cap ): bool {
		return \AIMS\Tests\Support\TestState::user_can( (int) $user_id, $cap );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		\AIMS\Tests\Support\TestState::record_hook_call(
			$hook,
			array(
				'callback'       => $callback,
				'priority'       => $priority,
				'accepted_args'   => $accepted_args,
			)
		);
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		\AIMS\Tests\Support\TestState::record_hook_call(
			$hook,
			array(
				'args' => $args,
			)
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		\AIMS\Tests\Support\TestState::record_hook_call(
			$hook,
			array(
				'value' => $value,
				'args'  => $args,
			)
		);

		return $value;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( string $tag, $callback ): void {
		\AIMS\Tests\Support\TestState::record_hook_call(
			'add_shortcode',
			array(
				'tag'      => $tag,
				'callback' => $callback,
			)
		);
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( ...$args ) {
		\AIMS\Tests\Support\TestState::record_hook_call( 'add_menu_page', $args );
		return 'add_menu_page';
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( ...$args ) {
		\AIMS\Tests\Support\TestState::record_hook_call( 'add_submenu_page', $args );
		return 'add_submenu_page';
	}
}

if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( ...$args ) {
		\AIMS\Tests\Support\TestState::record_hook_call( 'remove_submenu_page', $args );
		return true;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( string $redirect = '' ): string {
		return 'http://example.test/wp-login.php?redirect_to=' . rawurlencode( $redirect );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, string $url = '' ): string {
		$query = is_array( $args ) ? http_build_query( $args ) : (string) $args;
		if ( '' === $url ) {
			return $query;
		}

		$separator = false !== strpos( $url, '?' ) ? '&' : '?';
		return $url . $separator . $query;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		$base = 'http://localhost/';
		return '' === $path ? $base : $base . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( string $action = '', string $query_arg = '_wpnonce' ): void {
		\AIMS\Tests\Support\TestState::record_hook_call(
			'check_admin_referer',
			array(
				'action'    => $action,
				'query_arg' => $query_arg,
			)
		);
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, $action = -1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$field = '<input type="hidden" name="' . htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' ) . '" value="test-nonce" />';
		if ( $echo ) {
			echo $field;
		}

		return $field;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location ): void {
		\AIMS\Tests\Support\TestState::record_hook_call(
			'wp_safe_redirect',
			array(
				'location' => $location,
			)
		);

		if ( \AIMS\Tests\Support\TestState::should_throw_on_redirect() ) {
			throw new RuntimeException( 'redirect:' . $location );
		}
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '' ): void {
		throw new RuntimeException( (string) $message );
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( int $product_id ) {
		return \AIMS\Tests\Support\TestState::get_product( $product_id );
	}
}

\AIMS\Tests\Support\TestState::reset();
$GLOBALS['wpdb'] = new \AIMS\Tests\Support\FakeWpdb( 'wp_' );
