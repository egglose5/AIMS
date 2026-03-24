<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AIMS_Module {
	public function register(): void;
}

