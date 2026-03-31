<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Square_Sync_Service {
	private $vendors;
	private $team_members;

	public function __construct(
		AIMS_Vendor_Person_Repository $vendors,
		AIMS_Square_Team_Member_Repository $team_members = null
	) {
		$this->vendors      = $vendors;
		$this->team_members = $team_members;
	}

	public function plan_vendor_sync( int $vendor_id, array $square_team_members = array() ): array {
		$vendor = $this->vendors->find( $vendor_id );

		if ( empty( $vendor ) ) {
			return array(
				'vendor_id'           => $vendor_id,
				'state'               => 'missing_vendor',
				'search_strategy'     => array(),
				'matched_team_member' => null,
				'create_payload'      => null,
				'vendor_context'      => array(),
				'reasons'             => array( 'Vendor record was not found.' ),
			);
		}

		return $this->plan_vendor_sync_from_record( $vendor, $square_team_members );
	}

	public function plan_vendor_sync_from_record( array $vendor, array $square_team_members = array() ): array {
		$vendor_context = $this->prepare_vendor_context( $vendor );
		$matched_member = $this->find_matching_square_team_member( $vendor_context, $square_team_members );

		if ( ! empty( $matched_member ) ) {
			return array(
				'vendor_id'           => (int) $vendor_context['vendor_id'],
				'state'               => 'matched',
				'search_strategy'     => $vendor_context['search_terms'],
				'matched_team_member' => $matched_member,
				'create_payload'      => null,
				'vendor_context'      => $vendor_context,
				'reasons'             => array( 'Square team member matched locally.' ),
			);
		}

		return array(
			'vendor_id'           => (int) $vendor_context['vendor_id'],
			'state'               => 'create_required',
			'search_strategy'     => $vendor_context['search_terms'],
			'matched_team_member' => null,
			'create_payload'      => $this->build_square_team_member_create_payload( $vendor_context ),
			'vendor_context'      => $vendor_context,
			'reasons'             => array( 'No Square team member matched the vendor record.' ),
		);
	}

	public function prepare_vendor_context( array $vendor ): array {
		$vendor_name           = sanitize_text_field( $vendor['vendor_name'] ?? '' );
		$vendor_code           = sanitize_key( $vendor['vendor_code'] ?? '' );
		$email_address         = sanitize_email( $vendor['email_address'] ?? '' );
		$phone_number          = sanitize_text_field( $vendor['phone_number'] ?? '' );
		$square_team_member_id = sanitize_text_field( $vendor['square_team_member_id'] ?? '' );
		$name_parts            = $this->split_vendor_name( $vendor_name );

		return array(
			'vendor_id'             => (int) ( $vendor['id'] ?? 0 ),
			'vendor_code'           => $vendor_code,
			'vendor_name'           => $vendor_name,
			'status'                => sanitize_key( $vendor['status'] ?? 'active' ),
			'email_address'         => $email_address,
			'phone_number'          => $phone_number,
			'square_team_member_id' => $square_team_member_id,
			'given_name'            => $name_parts['given_name'],
			'family_name'           => $name_parts['family_name'],
			'search_terms'          => array(
				'square_team_member_id' => $square_team_member_id,
				'email_address'         => $email_address,
				'phone_number'          => $phone_number,
				'vendor_name'           => $this->normalize_lookup_value( $vendor_name ),
				'vendor_code'           => $this->normalize_lookup_value( $vendor_code ),
			),
		);
	}

	public function find_matching_square_team_member( array $vendor, array $square_team_members ): ?array {
		$search_terms = array(
			'square_team_member_id' => $this->normalize_lookup_value( $vendor['square_team_member_id'] ?? '' ),
			'email_address'         => $this->normalize_lookup_value( $vendor['email_address'] ?? '' ),
			'phone_number'          => $this->normalize_lookup_value( $vendor['phone_number'] ?? '' ),
			'vendor_name'           => $this->normalize_lookup_value( $vendor['vendor_name'] ?? '' ),
			'vendor_code'           => $this->normalize_lookup_value( $vendor['vendor_code'] ?? '' ),
		);

		foreach ( $square_team_members as $candidate ) {
			$normalized = $this->normalize_square_team_member_record( $candidate );

			if ( '' !== $search_terms['square_team_member_id'] && $normalized['square_team_member_id'] === $search_terms['square_team_member_id'] ) {
				return $normalized;
			}

			if ( '' !== $search_terms['email_address'] && $normalized['email_address'] === $search_terms['email_address'] ) {
				return $normalized;
			}

			if ( '' !== $search_terms['phone_number'] && $normalized['phone_number'] === $search_terms['phone_number'] ) {
				return $normalized;
			}

			if ( '' !== $search_terms['vendor_name'] && $normalized['display_name'] === $search_terms['vendor_name'] ) {
				return $normalized;
			}

			if ( '' !== $search_terms['vendor_code'] && ( $normalized['reference_id'] === $search_terms['vendor_code'] || $normalized['nickname'] === $search_terms['vendor_code'] ) ) {
				return $normalized;
			}
		}

		return null;
	}

	public function build_square_team_member_create_payload( array $vendor ): array {
		return array(
			'given_name'   => sanitize_text_field( $vendor['given_name'] ?? $vendor['vendor_name'] ?? '' ),
			'family_name'  => sanitize_text_field( $vendor['family_name'] ?? '' ),
			'email_address'=> sanitize_email( $vendor['email_address'] ?? '' ),
			'phone_number' => sanitize_text_field( $vendor['phone_number'] ?? '' ),
			'display_name' => sanitize_text_field( $vendor['vendor_name'] ?? '' ),
			'reference_id' => sanitize_key( $vendor['vendor_code'] ?? '' ),
			'notes'        => sprintf(
				'AIMS vendor sync payload for vendor %d. Square team member did not match locally.',
				(int) ( $vendor['vendor_id'] ?? 0 )
			),
			'metadata'     => array(
				'source'    => 'aims',
				'vendor_id' => (int) ( $vendor['vendor_id'] ?? 0 ),
			),
		);
	}

	private function normalize_square_team_member_record( array $candidate ): array {
		$given_name   = sanitize_text_field( $candidate['given_name'] ?? '' );
		$family_name  = sanitize_text_field( $candidate['family_name'] ?? '' );
		$display_name = sanitize_text_field(
			$candidate['display_name'] ?? trim( $given_name . ' ' . $family_name )
		);

		return array(
			'square_team_member_id' => $this->normalize_lookup_value( $candidate['square_team_member_id'] ?? $candidate['id'] ?? '' ),
			'display_name'          => $this->normalize_lookup_value( $display_name ),
			'given_name'            => $this->normalize_lookup_value( $given_name ),
			'family_name'           => $this->normalize_lookup_value( $family_name ),
			'email_address'         => $this->normalize_lookup_value( $candidate['email_address'] ?? '' ),
			'phone_number'          => $this->normalize_lookup_value( $candidate['phone_number'] ?? '' ),
			'reference_id'          => $this->normalize_lookup_value( $candidate['reference_id'] ?? '' ),
			'nickname'              => $this->normalize_lookup_value( $candidate['nickname'] ?? '' ),
			'status'                => sanitize_key( $candidate['status'] ?? '' ),
			'raw'                   => $candidate,
		);
	}

	private function split_vendor_name( string $vendor_name ): array {
		$vendor_name = trim( preg_replace( '/\s+/', ' ', $vendor_name ) );

		if ( '' === $vendor_name ) {
			return array(
				'given_name'  => '',
				'family_name' => '',
			);
		}

		$parts = explode( ' ', $vendor_name );

		if ( 1 === count( $parts ) ) {
			return array(
				'given_name'  => $vendor_name,
				'family_name' => '',
			);
		}

		$family_name = array_pop( $parts );

		return array(
			'given_name'  => trim( implode( ' ', $parts ) ),
			'family_name' => $family_name,
		);
	}

	private function normalize_lookup_value( $value ): string {
		if ( is_array( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( (string) $value );
		$value = strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );

		return $value;
	}
}
