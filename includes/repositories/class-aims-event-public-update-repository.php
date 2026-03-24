<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Public_Update_Repository extends AIMS_Public_Event_Update_Repository {
	public function get_published_for_event( int $event_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE event_id = %d AND public_status = %s ORDER BY COALESCE(published_at, last_projected_at, created_at) DESC, id DESC',
				$event_id,
				self::STATUS_PUBLISHED
			),
			ARRAY_A
		);
	}
}
