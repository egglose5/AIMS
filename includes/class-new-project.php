<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class New_Project {
	public function run(): void {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'new-project',
			false,
			dirname( plugin_basename( NEW_PROJECT_FILE ) ) . '/languages'
		);
	}
}

