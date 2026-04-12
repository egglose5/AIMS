<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class SquareOrderChargeRuleServiceTest extends \AIMS\Tests\TestCase {
	public function testMatchPayloadChargesUsesSavedRulesAndBuildsProjectionCharge(): void {
		$service = new \AIMS_Square_Order_Charge_Rule_Service();
		$service->save_rules(
			array(
				array(
					'code'                  => 'line_unfulfilled',
					'label'                 => 'Unfulfilled Line Charge',
					'square_charge_name'    => 'Unfulfilled Line Charge',
					'flag_key'              => 'requires_line_followup',
					'apply_projection_charge' => true,
					'push_to_square'        => true,
					'force_unfulfilled'     => true,
				),
			)
		);

		$matches = $service->match_payload_charges(
			array(
				'service_charges' => array(
					array(
						'uid'          => 'svc-10',
						'name'         => 'Unfulfilled Line Charge',
						'amount_money' => array(
							'amount'   => 425,
							'currency' => 'USD',
						),
					),
				),
			)
		);

		$this->assertSame( array( 'requires_line_followup' => true ), $matches['flags'] );
		$this->assertSame( 'line_unfulfilled', $matches['matched_rules'][0]['code'] );
		$this->assertSame( 4.25, $matches['projection_charges'][0]['amount'] );
		$this->assertTrue( $matches['force_unfulfilled'] ?? false );
		$this->assertTrue( $matches['force_pending_projection'] ?? false );

		$push_rules = $service->get_push_rules();
		$this->assertCount( 1, $push_rules );
		$this->assertSame( 'line_unfulfilled', $push_rules[0]['code'] );
		$this->assertTrue( $push_rules[0]['force_unfulfilled'] ?? false );
	}

	public function testMatchPayloadChargesSupportsCustomTriggerFrameworkViaFilterHook(): void {
		add_filter(
			'aims_square_charge_rule_custom_trigger_match',
			static function ( bool $matched, array $rule, array $charge, array $payload ): bool {
				unset( $payload );

				if ( ( $rule['code'] ?? '' ) !== 'event_button_trigger' ) {
					return $matched;
				}

				return 'event-button' === (string) ( $charge['metadata']['source'] ?? '' );
			}
		);

		$service = new \AIMS_Square_Order_Charge_Rule_Service();
		$service->save_rules(
			array(
				array(
					'code'                     => 'event_button_trigger',
					'label'                    => 'Event Button Trigger',
					'trigger_type'             => 'custom',
					'flag_key'                 => 'event_button_used',
					'force_pending_projection' => true,
				),
				array(
					'code'               => 'high_hold_fee',
					'label'              => 'High Hold Fee',
					'trigger_type'       => 'amount_gte',
					'trigger_config'     => array( 'amount' => 5.00 ),
					'force_unfulfilled'  => true,
				),
			)
		);

		$matches = $service->match_payload_charges(
			array(
				'service_charges' => array(
					array(
						'uid'          => 'svc-custom-1',
						'name'         => 'Any Name',
						'metadata'     => array( 'source' => 'event-button' ),
						'amount_money' => array(
							'amount'   => 700,
							'currency' => 'USD',
						),
					),
				),
			)
		);

		$this->assertCount( 2, $matches['matched_rules'] );
		$this->assertTrue( $matches['flags']['event_button_used'] ?? false );
		$this->assertTrue( $matches['force_unfulfilled'] ?? false );
		$this->assertTrue( $matches['force_pending_projection'] ?? false );
	}
}
