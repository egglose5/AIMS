<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Person_Repository {
	private $identity;

	public function __construct( AIMS_Person_Identity_Service $identity = null ) {
		$this->identity = $identity ?: new AIMS_Person_Identity_Service();
	}

	public function find( int $user_id ): ?array {
		if ( ! $this->identity->is_aims_person( $user_id ) ) {
			return null;
		}

		$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : null;
		if ( ! is_object( $user ) ) {
			return null;
		}

		return $this->format_person( $user );
	}

	public function all(): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'role__in' => AIMS_Capabilities::get_aims_role_slugs(),
			)
		);

		if ( ! is_array( $users ) ) {
			return array();
		}

		$people = array();

		foreach ( $users as $user ) {
			if ( ! is_object( $user ) ) {
				continue;
			}

			$person = $this->find( (int) ( $user->ID ?? 0 ) );
			if ( is_array( $person ) ) {
				$people[] = $person;
			}
		}

		return $people;
	}

	public function find_by_subtype( string $subtype ): array {
		$subtype = sanitize_key( $subtype );
		if ( '' === $subtype ) {
			return array();
		}

		$people = array();

		foreach ( $this->all() as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}

			$subtypes = (array) ( $person['subtypes'] ?? array() );
			if ( in_array( $subtype, $subtypes, true ) ) {
				$people[] = $person;
			}
		}

		return $people;
	}

	private function format_person( $user ): array {
		$user_id = (int) ( $user->ID ?? 0 );

		return array(
			'user_id'      => $user_id,
			'display_name' => sanitize_text_field( (string) ( $user->display_name ?? '' ) ),
			'email'        => sanitize_email( (string) ( $user->user_email ?? '' ) ),
			'subtypes'     => $this->identity->get_person_subtypes( $user_id ),
		);
	}
}
