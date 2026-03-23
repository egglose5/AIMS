<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Inventory_Overview_Page {
	private $data_provider;

	public function __construct( AIMS_Inventory_Overview_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1>Inventory</h1>';
		echo '<p>Inventory is being rebuilt around permanent buckets, warehouse locations, and event-scoped assignment windows.</p>';
		echo '<ul style="list-style: disc; padding-left: 20px;">';
		foreach ( $this->data_provider->get_outline() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}
}
