<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Events_Overview_Data_Provider {
	public function get_outline(): array {
		return array(
			'`event_id` is the bridge between Square runtime data and AIMS operations.',
			'Event bucket assignments will attach physical buckets to a show without coupling them to Square locations.',
			'Runtime assignments and vendor participation remain event-scoped and separate from warehouse identity.',
			'Reporting should join sales, attribution, and inventory activity through `event_id` only.',
		);
	}
}
