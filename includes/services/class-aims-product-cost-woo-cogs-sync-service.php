<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Product_Cost_Woo_Cogs_Sync_Service {
	private const DEFAULT_META_KEYS = array(
		'_wc_cog_cost',
		'_wc_cogs_value',
		'_wc_cogs',
		'_aims_product_cost',
	);

	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'aims_product_cost_rule_saved', array( __CLASS__, 'handle_cost_rule_saved' ), 10, 2 );
	}

	public static function handle_cost_rule_saved( int $rule_id, array $rule ): void {
		unset( $rule_id );

		$product_ids = self::resolve_affected_product_ids( $rule );
		if ( empty( $product_ids ) ) {
			return;
		}

		$active_rules = self::get_active_rules();
		foreach ( $product_ids as $product_id ) {
			self::sync_public_floor_for_product( (int) $product_id, $active_rules );
		}
	}

	public static function sync_public_floor_for_product( int $product_id, array $active_rules = array() ): array {
		if ( $product_id <= 0 ) {
			return self::result( false, 'invalid_product_id', $product_id, '', 0.0, 0.0, 0.0 );
		}

		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
			return self::result( false, 'post_meta_unavailable', $product_id, '', 0.0, 0.0, 0.0 );
		}

		$rules = ! empty( $active_rules ) ? $active_rules : self::get_active_rules();
		$aims_floor = self::compute_public_floor_for_product( $product_id, $rules );
		if ( $aims_floor <= 0 ) {
			return self::result( false, 'no_active_floor', $product_id, '', 0.0, 0.0, 0.0 );
		}

		$meta_info = self::resolve_meta_key_and_value( $product_id );
		$meta_key = (string) $meta_info['meta_key'];
		$current_value = (float) $meta_info['current_value'];
		$target_value = max( $current_value, $aims_floor );

		if ( $target_value <= $current_value ) {
			return self::result( false, 'already_at_or_above_floor', $product_id, $meta_key, $current_value, $aims_floor, $target_value );
		}

		$updated = (bool) update_post_meta( $product_id, $meta_key, number_format( $target_value, 4, '.', '' ) );
		if ( ! $updated ) {
			return self::result( false, 'update_failed', $product_id, $meta_key, $current_value, $aims_floor, $target_value );
		}

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $product_id, '_aims_min_public_cogs_floor', number_format( $aims_floor, 4, '.', '' ) );
		}

		return self::result( true, 'updated', $product_id, $meta_key, $current_value, $aims_floor, $target_value );
	}

	private static function resolve_affected_product_ids( array $rule ): array {
		$assignment_type = sanitize_key( (string) ( $rule['assignment_type'] ?? '' ) );

		if ( AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_PRODUCT === $assignment_type ) {
			$product_id = (int) ( $rule['product_id'] ?? 0 );
			return $product_id > 0 ? array( $product_id ) : array();
		}

		if ( AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_CATEGORY === $assignment_type ) {
			$term_id = (int) ( $rule['category_term_id'] ?? 0 );
			if ( $term_id <= 0 || ! function_exists( 'get_posts' ) ) {
				return array();
			}

			$products = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $term_id,
						),
					),
				)
			);

			return array_values( array_filter( array_map( 'intval', is_array( $products ) ? $products : array() ) ) );
		}

		return array();
	}

	private static function compute_public_floor_for_product( int $product_id, array $active_rules ): float {
		$max_cost = 0.0;

		foreach ( $active_rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$assignment_type = sanitize_key( (string) ( $rule['assignment_type'] ?? '' ) );
			$rule_cost = (float) ( $rule['unit_cost'] ?? 0 );
			if ( $rule_cost <= 0 ) {
				continue;
			}

			if ( AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_PRODUCT === $assignment_type ) {
				if ( (int) ( $rule['product_id'] ?? 0 ) !== $product_id ) {
					continue;
				}
				$max_cost = max( $max_cost, $rule_cost );
				continue;
			}

			if ( AIMS_Product_Cost_Rule_Repository::ASSIGNMENT_TYPE_CATEGORY === $assignment_type ) {
				$term_id = (int) ( $rule['category_term_id'] ?? 0 );
				if ( $term_id <= 0 || ! function_exists( 'has_term' ) ) {
					continue;
				}

				if ( ! has_term( $term_id, 'product_cat', $product_id ) ) {
					continue;
				}

				$max_cost = max( $max_cost, $rule_cost );
			}
		}

		return round( $max_cost, 4 );
	}

	private static function resolve_meta_key_and_value( int $product_id ): array {
		$meta_keys = self::get_candidate_meta_keys( $product_id );
		$meta_key = (string) ( $meta_keys[0] ?? '_wc_cog_cost' );
		$current_value = 0.0;

		foreach ( $meta_keys as $candidate_key ) {
			$value = get_post_meta( $product_id, (string) $candidate_key, true );
			if ( '' === $value || null === $value ) {
				continue;
			}

			if ( is_numeric( $value ) ) {
				$meta_key = (string) $candidate_key;
				$current_value = max( 0.0, (float) $value );
				break;
			}
		}

		return array(
			'meta_key'      => $meta_key,
			'current_value' => round( $current_value, 4 ),
		);
	}

	private static function get_candidate_meta_keys( int $product_id ): array {
		$keys = self::DEFAULT_META_KEYS;
		if ( function_exists( 'apply_filters' ) ) {
			$keys = (array) apply_filters( 'aims_woo_cogs_meta_keys', $keys, $product_id );
		}

		$normalized = array();
		foreach ( $keys as $key ) {
			$key = trim( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$normalized[] = $key;
		}

		return ! empty( $normalized ) ? array_values( array_unique( $normalized ) ) : self::DEFAULT_META_KEYS;
	}

	private static function get_active_rules(): array {
		if ( ! class_exists( 'AIMS_Product_Cost_Rule_Repository' ) ) {
			return array();
		}

		$repo = new AIMS_Product_Cost_Rule_Repository();
		$rules = $repo->get_active_rules();

		return is_array( $rules ) ? $rules : array();
	}

	private static function result( bool $updated, string $reason, int $product_id, string $meta_key, float $current_value, float $aims_floor, float $target_value ): array {
		return array(
			'updated'       => $updated,
			'reason'        => $reason,
			'product_id'    => $product_id,
			'meta_key'      => $meta_key,
			'current_value' => round( $current_value, 4 ),
			'aims_floor'    => round( $aims_floor, 4 ),
			'target_value'  => round( $target_value, 4 ),
		);
	}
}
