<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Wholesale_Contract_Service {
	public const META_ENABLED            = 'aims_wholesale_enabled';
	public const META_LEAD_TIME_DAYS     = 'aims_wholesale_lead_time_days';
	public const META_MIN_ORDER_QTY      = 'aims_wholesale_min_order_qty';
	public const META_TIER_RATES         = 'aims_wholesale_tier_rates';
	public const META_PAYMENT_TERMS      = 'aims_wholesale_payment_terms';
	public const META_SHIPPING_WINDOW    = 'aims_wholesale_shipping_window';
	public const META_CONTRACT_NOTES     = 'aims_wholesale_contract_notes';
	public const META_ELEVATED_CUSTOMER  = 'aims_wholesale_elevated_customer';

	public function is_wholesale_customer( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$enabled = (string) get_user_meta( $user_id, self::META_ENABLED, true );
		if ( in_array( strtolower( $enabled ), array( '1', 'yes', 'true', 'on' ), true ) ) {
			return true;
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		$roles = array_map( 'strval', (array) $user->roles );
		return in_array( 'aims_wholesale_customer', $roles, true );
	}

	public function get_contract( int $user_id ): array {
		$tier_rows = $this->parse_tier_rates( (string) get_user_meta( $user_id, self::META_TIER_RATES, true ) );

		return array(
			'enabled'           => $this->is_wholesale_customer( $user_id ),
			'elevated_customer' => $this->read_flag( $user_id, self::META_ELEVATED_CUSTOMER, true ),
			'lead_time_days'    => $this->read_int( $user_id, self::META_LEAD_TIME_DAYS, 7, 0, 365 ),
			'min_order_qty'     => $this->read_int( $user_id, self::META_MIN_ORDER_QTY, 1, 1, 1000000 ),
			'tier_rates'        => $tier_rows,
			'payment_terms'     => $this->read_string( $user_id, self::META_PAYMENT_TERMS, 'Net 15' ),
			'shipping_window'   => $this->read_string( $user_id, self::META_SHIPPING_WINDOW, 'Monday-Friday' ),
			'contract_notes'    => $this->read_string( $user_id, self::META_CONTRACT_NOTES, '' ),
		);
	}

	public function save_contract_from_profile( int $user_id, array $payload ): void {
		$enabled = ! empty( $payload[ self::META_ENABLED ] ) ? '1' : '0';
		$elevated = ! empty( $payload[ self::META_ELEVATED_CUSTOMER ] ) ? '1' : '0';

		update_user_meta( $user_id, self::META_ENABLED, $enabled );
		update_user_meta( $user_id, self::META_ELEVATED_CUSTOMER, $elevated );
		update_user_meta(
			$user_id,
			self::META_LEAD_TIME_DAYS,
			$this->bound_int( $payload[ self::META_LEAD_TIME_DAYS ] ?? 7, 0, 365 )
		);
		update_user_meta(
			$user_id,
			self::META_MIN_ORDER_QTY,
			$this->bound_int( $payload[ self::META_MIN_ORDER_QTY ] ?? 1, 1, 1000000 )
		);
		update_user_meta( $user_id, self::META_TIER_RATES, $this->sanitize_tier_rates_raw( (string) ( $payload[ self::META_TIER_RATES ] ?? '' ) ) );
		update_user_meta( $user_id, self::META_PAYMENT_TERMS, sanitize_text_field( (string) ( $payload[ self::META_PAYMENT_TERMS ] ?? '' ) ) );
		update_user_meta( $user_id, self::META_SHIPPING_WINDOW, sanitize_text_field( (string) ( $payload[ self::META_SHIPPING_WINDOW ] ?? '' ) ) );
		update_user_meta( $user_id, self::META_CONTRACT_NOTES, sanitize_textarea_field( (string) ( $payload[ self::META_CONTRACT_NOTES ] ?? '' ) ) );
	}

	public function parse_tier_rates( string $raw ): array {
		$rows = preg_split( '/\r\n|\r|\n/', trim( $raw ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$parsed = array();
		foreach ( $rows as $row ) {
			$line = trim( (string) $row );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $qty_part, $discount_part ) = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( ! preg_match( '/^\d+$/', $qty_part ) || ! is_numeric( $discount_part ) ) {
				continue;
			}

			$min_qty = max( 1, absint( $qty_part ) );
			$discount_percent = (float) $discount_part;
			$discount_percent = min( 100, max( 0, $discount_percent ) );

			$parsed[] = array(
				'min_qty'           => $min_qty,
				'discount_percent'  => round( $discount_percent, 4 ),
				'multiplier'        => round( 1 - ( $discount_percent / 100 ), 6 ),
			);
		}

		usort(
			$parsed,
			static function ( array $left, array $right ): int {
				return $left['min_qty'] <=> $right['min_qty'];
			}
		);

		return $parsed;
	}

	public function sanitize_tier_rates_raw( string $raw ): string {
		$rows = $this->parse_tier_rates( $raw );
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = (int) $row['min_qty'] . ':' . rtrim( rtrim( number_format( (float) $row['discount_percent'], 4, '.', '' ), '0' ), '.' );
		}

		return implode( "\n", $lines );
	}

	public function resolve_discount_for_quantity( array $tier_rates, int $quantity ): float {
		$quantity = max( 0, $quantity );
		$discount = 0.0;

		foreach ( $tier_rates as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}

			$min_qty = (int) ( $tier['min_qty'] ?? 0 );
			if ( $quantity >= $min_qty ) {
				$discount = (float) ( $tier['discount_percent'] ?? 0 );
			}
		}

		return min( 100, max( 0, $discount ) );
	}

	private function read_string( int $user_id, string $meta_key, string $default ): string {
		$value = (string) get_user_meta( $user_id, $meta_key, true );
		$value = trim( $value );
		return '' === $value ? $default : $value;
	}

	private function read_int( int $user_id, string $meta_key, int $default, int $min, int $max ): int {
		$value = get_user_meta( $user_id, $meta_key, true );
		if ( '' === (string) $value ) {
			return $default;
		}

		return $this->bound_int( $value, $min, $max );
	}

	private function read_flag( int $user_id, string $meta_key, bool $default ): bool {
		$value = (string) get_user_meta( $user_id, $meta_key, true );
		if ( '' === $value ) {
			return $default;
		}

		return in_array( strtolower( $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private function bound_int( $value, int $min, int $max ): int {
		$normalized = absint( $value );
		return min( $max, max( $min, $normalized ) );
	}
}
