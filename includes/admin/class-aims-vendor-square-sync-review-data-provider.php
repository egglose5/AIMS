<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Vendor_Square_Sync_Review_Data_Provider {
	public function get_rows(): array {
		return array(
			array(
				'vendor_name'        => 'Sample Vendor',
				'square_team_member' => 'Sample Team Member',
				'state'              => 'create_required',
				'search_basis'       => 'email + vendor code',
				'notes'              => 'No local Square team member match was found.',
				'updated_at'         => current_time( 'mysql' ),
			),
		);
	}
}
