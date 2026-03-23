<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Square_Exceptions_Data_Provider {
	public function get_rows(): array {
		return array(
			array(
				'sale_ref'          => 'SQSALE-501',
				'exception_type'    => 'stale_assignment',
				'severity'          => 'medium',
				'resolution_status' => 'open',
				'message'           => 'Sale was received outside the active assignment window.',
				'created_at'        => current_time( 'mysql' ),
			),
		);
	}
}
