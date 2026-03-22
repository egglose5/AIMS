<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Auth_Context_Service {
	public function normalize_actor_user_id( int $actor_user_id ): int {
		return $actor_user_id > 0 ? $actor_user_id : 0;
	}

	public function has_actor_user_id( int $actor_user_id ): bool {
		return $this->normalize_actor_user_id( $actor_user_id ) > 0;
	}

	public function can_user( int $actor_user_id, string $capability ): bool {
		$actor_user_id = $this->normalize_actor_user_id( $actor_user_id );

		if ( $actor_user_id <= 0 ) {
			return false;
		}

		return user_can( $actor_user_id, $capability );
	}

	public function require_actor_user_id( int $actor_user_id, string $action_label = 'this action' ): ?WP_Error {
		if ( $this->has_actor_user_id( $actor_user_id ) ) {
			return null;
		}

		return new WP_Error(
			'aims_actor_context_required',
			sprintf( 'An explicit actor is required to %s.', $action_label )
		);
	}
}
