<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Shipping_Queue_Data_Provider {
	public function get_rows(): array {
		return array(
			array(
				'order_ref' => 'SQ-10021',
				'customer_name' => 'Sample Customer',
				'event_name' => 'Sample Show',
				'shipping_label' => 'AIMS Shipping Required',
				'status' => 'needs_shipping',
				'created_at' => current_time( 'mysql' ),
			),
		);
	}
}
