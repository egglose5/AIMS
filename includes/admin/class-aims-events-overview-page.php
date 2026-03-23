<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Events_Overview_Page {
	private $data_provider;

	public function __construct( AIMS_Events_Overview_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1>Events</h1>';
		echo '<p>Events are the operational bridge between Square sales, runtime assignments, and physical inventory commitment.</p>';
		echo '<ul style="list-style: disc; padding-left: 20px;">';
		foreach ( $this->data_provider->get_outline() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}
}
