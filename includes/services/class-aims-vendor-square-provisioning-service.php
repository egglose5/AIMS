<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Square_Provisioning_Service {
	private $vendors;
	private $sync;
	private $client;
	private $team_members;

	public function __construct(
		AIMS_Vendor_Service $vendors = null,
		AIMS_Vendor_Square_Sync_Service $sync = null,
		AIMS_Headless_Api_Client $client = null,
		AIMS_Square_Team_Member_Repository $team_members = null
	) {
		$this->vendors      = $vendors ?: new AIMS_Vendor_Service();
		$this->team_members = $team_members ?: new AIMS_Square_Team_Member_Repository();
		$this->sync         = $sync ?: new AIMS_Vendor_Square_Sync_Service( new AIMS_Vendor_Person_Repository(), $this->team_members );
		$this->client       = $client ?: AIMS_Headless_Api_Client::from_plugin_options();
	}

	public function provision_vendor( int $vendor_id ): array {
		$vendor = $this->vendors->get_vendor( $vendor_id );
		if ( ! is_array( $vendor ) || empty( $vendor ) ) {
			return $this->failure( $vendor_id, 'missing_vendor', 'Vendor record was not found for Square provisioning.' );
		}

		$location_result = $this->ensure_square_location( $vendor );
		if ( empty( $location_result['success'] ) ) {
			return $location_result;
		}

		$vendor = is_array( $location_result['vendor'] ?? null ) ? $location_result['vendor'] : $vendor;
		$plan   = $this->sync->plan_vendor_sync_from_record( $vendor );
		$state  = sanitize_key( (string) ( $plan['state'] ?? '' ) );

		if ( 'matched' === $state ) {
			$matched        = is_array( $plan['matched_team_member'] ?? null ) ? $plan['matched_team_member'] : array();
			$team_member_id = sanitize_text_field( (string) ( $matched['square_team_member_id'] ?? $matched['id'] ?? '' ) );

			if ( '' === $team_member_id ) {
				return $this->failure( $vendor_id, 'match_missing_id', 'Matched Square team member did not include an ID.', array( 'plan' => $plan ) );
			}

			$this->store_team_member( $vendor_id, $team_member_id, $matched );

			return array(
				'success'               => true,
				'vendor_id'             => $vendor_id,
				'square_location_id'    => sanitize_text_field( (string) ( $vendor['square_location_id'] ?? '' ) ),
				'square_team_member_id' => $team_member_id,
				'state'                 => 'matched',
				'message'               => 'Vendor matched to an existing Square team member.',
			);
		}

		if ( 'create_required' !== $state ) {
			return $this->failure( $vendor_id, 'unsupported_sync_state', 'Vendor Square sync state could not be resolved.', array( 'plan' => $plan ) );
		}

		$payload = is_array( $plan['create_payload'] ?? null ) ? $plan['create_payload'] : array();
		if ( empty( $payload['assigned_locations'] ) && ! empty( $vendor['square_location_id'] ) ) {
			$payload['assigned_locations'] = array(
				'assignment_type' => 'EXPLICIT_LOCATIONS',
				'location_ids'    => array( sanitize_text_field( (string) $vendor['square_location_id'] ) ),
			);
		}

		$response       = $this->client->create_square_team_member( $payload );
		$team_member    = $this->extract_team_member( $response );
		$team_member_id = sanitize_text_field( (string) ( $team_member['id'] ?? $team_member['square_team_member_id'] ?? '' ) );

		if ( empty( $response['success'] ) || '' === $team_member_id ) {
			return $this->failure(
				$vendor_id,
				'create_team_member_failed',
				'The Square team member could not be created for this vendor.',
				array( 'response' => $response, 'plan' => $plan )
			);
		}

		$this->store_team_member( $vendor_id, $team_member_id, $team_member );

		return array(
			'success'               => true,
			'vendor_id'             => $vendor_id,
			'square_location_id'    => sanitize_text_field( (string) ( $vendor['square_location_id'] ?? '' ) ),
			'square_team_member_id' => $team_member_id,
			'state'                 => 'created',
			'message'               => 'Vendor was provisioned in Square.',
		);
	}

	private function ensure_square_location( array $vendor ): array {
		$vendor_id          = (int) ( $vendor['id'] ?? $vendor['user_id'] ?? 0 );
		$square_location_id = sanitize_text_field( (string) ( $vendor['square_location_id'] ?? '' ) );

		if ( '' !== $square_location_id ) {
			return array(
				'success'            => true,
				'vendor_id'          => $vendor_id,
				'square_location_id' => $square_location_id,
				'vendor'             => $vendor,
				'created'            => false,
			);
		}

		$response          = $this->client->create_square_location( $this->build_square_location_payload( $vendor ) );
		$location          = $this->extract_location( $response );
		$square_location_id = sanitize_text_field( (string) ( $location['id'] ?? $location['location_id'] ?? '' ) );

		if ( empty( $response['success'] ) || '' === $square_location_id ) {
			return $this->failure(
				$vendor_id,
				'create_location_failed',
				'The Square location could not be created for this vendor.',
				array( 'response' => $response )
			);
		}

		$this->vendors->update_vendor(
			$vendor_id,
			array(
				'square_location_id' => $square_location_id,
			)
		);

		$vendor['square_location_id'] = $square_location_id;

		return array(
			'success'            => true,
			'vendor_id'          => $vendor_id,
			'square_location_id' => $square_location_id,
			'vendor'             => $vendor,
			'created'            => true,
		);
	}

	private function store_team_member( int $vendor_id, string $team_member_id, array $member ): void {
		$this->vendors->update_vendor(
			$vendor_id,
			array(
				'square_team_member_id' => $team_member_id,
			)
		);

		if ( is_object( $this->team_members ) && method_exists( $this->team_members, 'save' ) ) {
			$this->team_members->save(
				array(
					'square_team_member_id' => $team_member_id,
					'display_name'          => sanitize_text_field( (string) ( $member['display_name'] ?? '' ) ),
					'given_name'            => sanitize_text_field( (string) ( $member['given_name'] ?? '' ) ),
					'family_name'           => sanitize_text_field( (string) ( $member['family_name'] ?? '' ) ),
					'email_address'         => sanitize_email( (string) ( $member['email_address'] ?? '' ) ),
					'phone_number'          => sanitize_text_field( (string) ( $member['phone_number'] ?? '' ) ),
					'status'                => sanitize_key( (string) ( $member['status'] ?? 'active' ) ),
					'raw_payload'           => $member,
					'last_synced_at'        => current_time( 'mysql' ),
				)
			);
		}
	}

	private function build_square_location_payload( array $vendor ): array {
		$location = array(
			'name'          => $this->build_location_name( $vendor ),
			'business_name' => sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) ),
			'email'         => sanitize_email( (string) ( $vendor['email_address'] ?? '' ) ),
			'phone_number'  => sanitize_text_field( (string) ( $vendor['phone_number'] ?? '' ) ),
			'description'   => sprintf(
				'AIMS vendor location for vendor %d.',
				(int) ( $vendor['id'] ?? $vendor['user_id'] ?? 0 )
			),
			'address'       => array_filter(
				array(
					'address_line_1'               => sanitize_text_field( (string) ( $vendor['address_line_1'] ?? '' ) ),
					'address_line_2'               => sanitize_text_field( (string) ( $vendor['address_line_2'] ?? '' ) ),
					'locality'                     => sanitize_text_field( (string) ( $vendor['city'] ?? '' ) ),
					'administrative_district_level_1' => sanitize_text_field( (string) ( $vendor['state_region'] ?? '' ) ),
					'postal_code'                  => sanitize_text_field( (string) ( $vendor['postal_code'] ?? '' ) ),
					'country'                      => strtoupper( sanitize_text_field( (string) ( $vendor['country_code'] ?? 'US' ) ) ),
				),
				static function ( $value ): bool {
					return '' !== trim( (string) $value );
				}
			),
		);

		if ( empty( $location['address'] ) ) {
			unset( $location['address'] );
		}

		return array( 'location' => $location );
	}

	private function build_location_name( array $vendor ): string {
		$vendor_name = sanitize_text_field( (string) ( $vendor['vendor_name'] ?? '' ) );
		$vendor_code = strtoupper( sanitize_key( (string) ( $vendor['vendor_code'] ?? '' ) ) );

		if ( '' !== $vendor_name && '' !== $vendor_code ) {
			return $vendor_name . ' — ' . $vendor_code;
		}

		if ( '' !== $vendor_name ) {
			return $vendor_name;
		}

		if ( '' !== $vendor_code ) {
			return 'Vendor ' . $vendor_code;
		}

		return 'AIMS Vendor Location';
	}

	private function extract_location( array $response ): array {
		$location = $response['location'] ?? $response['json']['location'] ?? $response['json']['result']['location'] ?? array();
		return is_array( $location ) ? $location : array();
	}

	private function extract_team_member( array $response ): array {
		$member = $response['team_member'] ?? $response['json']['team_member'] ?? $response['json']['result']['team_member'] ?? array();
		return is_array( $member ) ? $member : array();
	}

	private function failure( int $vendor_id, string $step, string $message, array $context = array() ): array {
		return array_merge(
			array(
				'success'   => false,
				'vendor_id' => $vendor_id,
				'step'      => sanitize_key( $step ),
				'message'   => $message,
			),
			$context
		);
	}
}
