<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Overview_Data_Provider {
	public function get_outline(): array {
		return array(
			'Physical buckets are permanent objects with warehouse locations and lifecycle state.',
			'Bucket contents will be tracked separately from show assignment so the same bucket can move over time.',
			'Bucket movements are the immutable ledger for stock-in, stock-out, transfer, and event load-out/return activity.',
			'Pick, pack, and reconciliation views should read from bucket and event assignments, not Square identifiers.',
		);
	}
}
