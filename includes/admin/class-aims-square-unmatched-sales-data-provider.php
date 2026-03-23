<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Unmatched_Sales_Data_Provider {
	public function get_rows(): array {
		return array(
			array(
				'order_ref'   => 'SQ-10021',
				'event_name'  => 'Sample Show',
				'vendor_name' => 'Sample Vendor',
				'match_state' => 'missing_assignment',
				'reason'      => 'No runtime assignment was active for the sale window.',
				'created_at'  => current_time( 'mysql' ),
			),
		);
	}
}
