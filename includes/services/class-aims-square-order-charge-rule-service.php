<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Order_Charge_Rule_Service {
	public const OPTION_RULES = 'aims_square_order_charge_rules';

	public function get_rules(): array {
		$rules = get_option( self::OPTION_RULES, array() );

		if ( ! is_array( $rules ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$normalized_rule = $this->normalize_rule( $rule );
			if ( empty( $normalized_rule ) ) {
				continue;
			}

			$normalized[] = $normalized_rule;
		}

		return $normalized;
	}

	public function save_rules( array $rules ): bool {
		$normalized = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$normalized_rule = $this->normalize_rule( $rule );
			if ( empty( $normalized_rule ) ) {
				continue;
			}

			$normalized[] = $normalized_rule;
		}

		return (bool) update_option( self::OPTION_RULES, $normalized );
	}

	public function match_payload_charges( array $payload ): array {
		$charges = array();

		if ( ! empty( $payload['service_charges'] ) && is_array( $payload['service_charges'] ) ) {
			$charges = $payload['service_charges'];
		}

		$rules              = $this->get_rules();
		$matched_rules      = array();
		$projection_charges = array();
		$flags              = array();
		$force_unfulfilled  = false;
		$force_pending_projection = false;

		if ( empty( $charges ) || empty( $rules ) ) {
			return array(
				'matched_rules'      => array(),
				'matched_charge_ids' => array(),
				'flags'              => array(),
				'projection_charges' => array(),
				'force_unfulfilled'  => false,
				'force_pending_projection' => false,
			);
		}

		foreach ( $charges as $charge ) {
			if ( ! is_array( $charge ) ) {
				continue;
			}

			$charge_name = sanitize_text_field( (string) ( $charge['name'] ?? '' ) );

			foreach ( $rules as $rule ) {
				if ( ! $this->rule_matches_charge( $rule, $charge, $payload ) ) {
					continue;
				}

				$matched_rules[] = array(
					'code'            => (string) ( $rule['code'] ?? '' ),
					'label'           => (string) ( $rule['label'] ?? '' ),
					'flag_key'        => (string) ( $rule['flag_key'] ?? '' ),
					'charge_name'     => $charge_name,
					'charge_id'       => sanitize_text_field( (string) ( $charge['uid'] ?? $charge['id'] ?? '' ) ),
					'charge_amount'   => $this->normalize_charge_amount( $charge ),
					'charge_currency' => sanitize_text_field( (string) ( $charge['amount_money']['currency'] ?? '' ) ),
				);

				$flag_key = sanitize_key( (string) ( $rule['flag_key'] ?? '' ) );
				if ( '' !== $flag_key ) {
					$flags[ $flag_key ] = true;
				}

				if ( ! empty( $rule['apply_projection_charge'] ) ) {
					$projection_charges[] = $this->build_projection_charge_from_match( $rule, $charge_name, $charge );
				}

				$force_unfulfilled = $force_unfulfilled || ! empty( $rule['force_unfulfilled'] );
				$force_pending_projection = $force_pending_projection || ! empty( $rule['force_pending_projection'] );
			}
		}

		$matched_charge_ids = array();
		foreach ( $matched_rules as $match ) {
			$charge_id = sanitize_text_field( (string) ( $match['charge_id'] ?? '' ) );
			if ( '' !== $charge_id ) {
				$matched_charge_ids[] = $charge_id;
			}
		}

		return array(
			'matched_rules'      => $matched_rules,
			'matched_charge_ids' => array_values( array_unique( $matched_charge_ids ) ),
			'flags'              => $flags,
			'projection_charges' => $projection_charges,
			'force_unfulfilled'  => $force_unfulfilled,
			'force_pending_projection' => $force_pending_projection,
		);
	}

	public function get_push_rules(): array {
		$rules      = $this->get_rules();
		$push_rules = array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['push_to_square'] ) ) {
				continue;
			}

			$push_rules[] = array(
				'code'                   => (string) ( $rule['code'] ?? '' ),
				'label'                  => (string) ( $rule['label'] ?? '' ),
				'square_charge_name'     => (string) ( $rule['square_charge_name'] ?? '' ),
				'match_mode'             => (string) ( $rule['match_mode'] ?? 'exact' ),
				'trigger_type'           => (string) ( $rule['trigger_type'] ?? 'charge_name' ),
				'trigger_config'         => (array) ( $rule['trigger_config'] ?? array() ),
				'flag_key'               => (string) ( $rule['flag_key'] ?? '' ),
				'default_amount'         => (float) ( $rule['default_amount'] ?? 0.0 ),
				'taxable'                => ! empty( $rule['taxable'] ),
				'tax_class'              => (string) ( $rule['tax_class'] ?? '' ),
				'apply_projection_charge' => ! empty( $rule['apply_projection_charge'] ),
				'projection_label'       => (string) ( $rule['projection_label'] ?? '' ),
				'projection_amount'      => (float) ( $rule['projection_amount'] ?? 0.0 ),
				'force_unfulfilled'      => ! empty( $rule['force_unfulfilled'] ),
				'force_pending_projection' => ! empty( $rule['force_pending_projection'] ),
			);
		}

		return $push_rules;
	}

	private function normalize_rule( array $rule ): array {
		$label             = sanitize_text_field( (string) ( $rule['label'] ?? '' ) );
		$trigger_type      = $this->normalize_trigger_type( (string) ( $rule['trigger_type'] ?? 'charge_name' ) );
		$square_charge_name = sanitize_text_field( (string) ( $rule['square_charge_name'] ?? $rule['match_name'] ?? $label ) );
		$code              = sanitize_key( (string) ( $rule['code'] ?? $square_charge_name ) );

		if ( '' === $code ) {
			return array();
		}

		if ( 'charge_name' === $trigger_type && '' === $square_charge_name ) {
			return array();
		}

		$match_mode = sanitize_key( (string) ( $rule['match_mode'] ?? 'exact' ) );
		if ( 'contains' !== $match_mode ) {
			$match_mode = 'exact';
		}

		$flag_key = sanitize_key( (string) ( $rule['flag_key'] ?? ( 'has_' . $code ) ) );

		$default_amount   = round( (float) ( $rule['default_amount'] ?? $rule['amount'] ?? 0 ), 2 );
		$projection_label = sanitize_text_field( (string) ( $rule['projection_label'] ?? $label ?: $square_charge_name ) );
		$projection_amount = array_key_exists( 'projection_amount', $rule )
			? round( (float) $rule['projection_amount'], 2 )
			: $default_amount;

		return array(
			'code'                  => $code,
			'label'                 => '' !== $label ? $label : $square_charge_name,
			'square_charge_name'    => $square_charge_name,
			'match_mode'            => $match_mode,
			'trigger_type'          => $trigger_type,
			'trigger_config'        => $this->sanitize_trigger_config( (array) ( $rule['trigger_config'] ?? array() ) ),
			'flag_key'              => $flag_key,
			'push_to_square'        => $this->is_truthy( $rule['push_to_square'] ?? $rule['include_in_square_push'] ?? false ),
			'apply_projection_charge' => $this->is_truthy( $rule['apply_projection_charge'] ?? false ),
			'default_amount'        => $default_amount,
			'taxable'               => $this->is_truthy( $rule['taxable'] ?? false ),
			'tax_class'             => sanitize_text_field( (string) ( $rule['tax_class'] ?? '' ) ),
			'projection_label'      => $projection_label,
			'projection_amount'     => $projection_amount,
			'force_unfulfilled'     => $this->is_truthy( $rule['force_unfulfilled'] ?? false ),
			'force_pending_projection' => $this->is_truthy( $rule['force_pending_projection'] ?? ( $rule['force_unfulfilled'] ?? false ) ),
		);
	}

	private function rule_matches_charge( array $rule, array $charge, array $payload ): bool {
		$trigger_type = $this->normalize_trigger_type( (string) ( $rule['trigger_type'] ?? 'charge_name' ) );

		if ( 'custom' === $trigger_type ) {
			if ( function_exists( 'apply_filters' ) ) {
				return (bool) apply_filters( 'aims_square_charge_rule_custom_trigger_match', false, $rule, $charge, $payload );
			}

			return false;
		}

		if ( 'amount_gte' === $trigger_type ) {
			$threshold = (float) ( $rule['trigger_config']['amount'] ?? $rule['default_amount'] ?? 0.0 );
			return $this->normalize_charge_amount( $charge ) >= $threshold;
		}

		if ( 'always' === $trigger_type ) {
			return true;
		}

		$charge_name = sanitize_text_field( (string) ( $charge['name'] ?? '' ) );
		if ( '' === $charge_name ) {
			return false;
		}

		return $this->rule_matches_charge_name( $rule, $charge_name );
	}

	private function rule_matches_charge_name( array $rule, string $charge_name ): bool {
		$target = sanitize_text_field( (string) ( $rule['square_charge_name'] ?? '' ) );
		if ( '' === $target || '' === $charge_name ) {
			return false;
		}

		if ( 'contains' === (string) ( $rule['match_mode'] ?? 'exact' ) ) {
			return false !== stripos( $charge_name, $target );
		}

		return 0 === strcasecmp( $target, $charge_name );
	}

	private function build_projection_charge_from_match( array $rule, string $charge_name, array $charge ): array {
		$amount = $this->normalize_charge_amount( $charge );
		if ( $amount <= 0 ) {
			$amount = (float) ( $rule['projection_amount'] ?? $rule['default_amount'] ?? 0.0 );
		}

		$label = sanitize_text_field( (string) ( $rule['projection_label'] ?? $rule['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $charge_name;
		}

		return array(
			'code'      => sanitize_key( (string) ( $rule['code'] ?? '' ) ),
			'label'     => $label,
			'amount'    => round( (float) $amount, 2 ),
			'taxable'   => ! empty( $rule['taxable'] ),
			'tax_class' => sanitize_text_field( (string) ( $rule['tax_class'] ?? '' ) ),
			'meta'      => array(
				'source'            => 'square_service_charge_rule',
				'square_charge_id'  => sanitize_text_field( (string) ( $charge['uid'] ?? $charge['id'] ?? '' ) ),
				'square_charge_name' => $charge_name,
			),
		);
	}

	private function normalize_charge_amount( array $charge ): float {
		$cents = (float) ( $charge['amount_money']['amount'] ?? 0 );
		if ( $cents > 0 ) {
			return round( $cents / 100, 2 );
		}

		$decimal_amount = (float) ( $charge['amount'] ?? 0 );
		return round( $decimal_amount, 2 );
	}

	private function normalize_trigger_type( string $trigger_type ): string {
		$trigger_type = sanitize_key( $trigger_type );
		$allowed      = array( 'charge_name', 'amount_gte', 'always', 'custom' );

		return in_array( $trigger_type, $allowed, true ) ? $trigger_type : 'charge_name';
	}

	private function sanitize_trigger_config( array $config ): array {
		$sanitized = array();

		foreach ( $config as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_trigger_config( $value );
				continue;
			}

			if ( is_bool( $value ) || is_numeric( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	private function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (float) $value > 0;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			return in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}
}
