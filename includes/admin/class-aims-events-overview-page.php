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

		$events_widget = new AIMS_Admin_Events_Widget( new AIMS_Admin_Meta_Object( array(
			'outline' => $this->data_provider->get_outline(),
		) ) );
		$events_widget->render();

		echo '</div>';
	}
}
