<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'AIMS_VERSION' ) ) {
	define( 'AIMS_VERSION', '0.1.0' );
}

if ( ! defined( 'AIMS_PLUGIN_FILE' ) ) {
	define( 'AIMS_PLUGIN_FILE', __DIR__ . '/ai-man-sys.php' );
}

if ( ! defined( 'AIMS_PLUGIN_BASENAME' ) ) {
	define( 'AIMS_PLUGIN_BASENAME', plugin_basename( AIMS_PLUGIN_FILE ) );
}

if ( ! defined( 'AIMS_PLUGIN_PATH' ) ) {
	define( 'AIMS_PLUGIN_PATH', plugin_dir_path( AIMS_PLUGIN_FILE ) );
}

if ( ! defined( 'AIMS_PLUGIN_URL' ) ) {
	define( 'AIMS_PLUGIN_URL', plugin_dir_url( AIMS_PLUGIN_FILE ) );
}

require_once __DIR__ . '/includes/core/class-aims-loader.php';

AIMS_Loader::init();

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		AIMS_Plugin::uninstall();
		restore_current_blog();
	}
} else {
	AIMS_Plugin::uninstall();
}
